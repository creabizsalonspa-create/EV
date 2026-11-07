"""No-Docker synthetic runtime negative tests for recovery container isolation."""
import copy
from contextlib import redirect_stdout, redirect_stderr
import importlib.util
import io
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('runtime_guard', ROOT / 'scripts/recovery-runtime-guard.py')
g = importlib.util.module_from_spec(spec)
spec.loader.exec_module(g)
S = 'a' * 64
T = 'b' * 64
DB_IMAGE = 'postgres:17-alpine@sha256:' + 'f' * 64
APP_IMAGE = 'creabiz-ev-business:review'
DB_ID = 'sha256:' + '9' * 64
APP_ID = 'sha256:' + '8' * 64
C1 = 'c' * 64
C2 = 'd' * 64
C3 = 'e' * 64
C4 = 'f' * 64


def network(identifier):
    return {'Id': identifier, 'Internal': True, 'Driver': 'bridge', 'Ingress': False,
            'Attachable': False, 'Scope': 'local', 'Containers': {}}


def container(name, network_id, volume):
    return {
        'Name': '/' + name,
        'Config': {'Image': APP_IMAGE if '-app' in name else DB_IMAGE},
        'Image': APP_ID if '-app' in name else DB_ID,
        'HostConfig': {
            'NetworkMode': network_id, 'Privileged': False,
            'PortBindings': {}, 'CapAdd': None, 'Devices': [], 'Binds': None,
            'ExtraHosts': None, 'Links': None, 'PidMode': '', 'IpcMode': 'private',
            'UsernsMode': '',
        },
        'NetworkSettings': {
            'Networks': {'recovery-net': {'NetworkID': network_id}},
            'Ports': {'5432/tcp': None},
        },
        'Mounts': [{'Type': 'volume', 'Name': volume,
                    'Destination': '/var/lib/postgresql/data', 'RW': True}],
    }


class RecoveryIsolationTests(unittest.TestCase):
    def test_distinct_empty_internal_networks(self):
        g.validate_networks([network(S), network(T)], S, T)
        for field, bad in [('Internal', False), ('Driver', 'overlay'),
                           ('Ingress', True), ('Attachable', True),
                           ('Scope', 'swarm'), ('Containers', {'intruder': {}})]:
            with self.subTest(field=field), self.assertRaises(g.IsolationError):
                altered = [network(S), network(T)]
                altered[0][field] = bad
                g.validate_networks(altered, S, T)
        with self.assertRaises(g.IsolationError):
            g.validate_networks([network(S), network(S)], S, T)
        with self.assertRaises(g.IsolationError):
            g.validate_networks([network(S), network(T)], S, S)
        owned = [network(S), network(T)]
        owned[0]['Containers'] = {C1: {'Name': 'source'}}
        owned[1]['Containers'] = {C2: {'Name': 'target'}}
        g.validate_networks(owned, S, T, [C1], [C2])
        for bad_source, bad_target in (
            ([C1], [C1]), ([C1], [C3]), ([C1, C3], [C2]),
            (['invalid'], [C2]), ([], [C2]),
        ):
            with self.subTest(expected=(bad_source, bad_target)):
                with self.assertRaises(g.IsolationError):
                    g.validate_networks(owned, S, T, bad_source, bad_target)
        owned[0]['Containers'][C3] = {'Name': 'intruder'}
        with self.assertRaises(g.IsolationError):
            g.validate_networks(owned, S, T, [C1], [C2])

    def test_disposable_containers_do_not_share_network_or_volume(self):
        rows = [container('source-db', S, 'private-source'),
                container('target-db', T, 'private-target')]
        g.validate_containers(rows, S, T, ['source-db'], ['target-db'], DB_IMAGE)
        resolved_name = copy.deepcopy(rows)
        resolved_name[0]['HostConfig']['NetworkMode'] = 'recovery-net'
        g.validate_containers(resolved_name, S, T, ['source-db'], ['target-db'],
                              DB_IMAGE)
        tests = [
            ('Name', '/unexpected'),
            ('HostConfig.NetworkMode', 'host'),
            ('HostConfig.Privileged', True),
            ('HostConfig.PortBindings', {'5432/tcp': [{'HostPort': '5432'}]}),
            ('HostConfig.CapAdd', ['SYS_ADMIN']),
            ('HostConfig.Devices', [{'PathOnHost': '/dev/sda'}]),
            ('HostConfig.Binds', ['/etc:/host']),
            ('HostConfig.ExtraHosts', ['private:127.0.0.1']),
            ('HostConfig.Links', ['production']),
            ('HostConfig.PidMode', 'host'),
            ('HostConfig.IpcMode', 'host'),
            ('Config.Image', 'unexpected:image'),
            ('Image', 'sha256:' + 'a' * 64),
            ('Image', 'not-a-valid-sha'),
            ('HostConfig.UsernsMode', 'host'),
            ('HostConfig.CgroupnsMode', 'host'),
            ('HostConfig.UTSMode', 'host'),
            ('HostConfig.SecurityOpt', ['seccomp=unconfined']),
            ('HostConfig.DeviceRequests', [{'Driver': 'nvidia'}]),
            ('HostConfig.DeviceCgroupRules', ['a *:* rwm']),
            ('NetworkSettings.Networks', {'x': {'NetworkID': T}}),
            ('NetworkSettings.Ports', {'5432/tcp': [{'HostPort': '5432'}]}),
            ('NetworkSettings.Ports', ['malformed-list']),
            ('Mounts', [{'Type': 'bind', 'Source': '/etc', 'Destination': '/host'}]),
            ('Mounts', []),
            ('Mounts', [{'Type': 'volume', 'Name': 'new', 'RW': True,
                         'Destination': '/etc'}]),
            ('Mounts', [{'Type': 'volume', 'Name': 'new', 'RW': False,
                         'Destination': '/var/lib/postgresql/data'}]),
            ('Mounts', [{'Type': 'volume', 'Name': 'new', 'RW': True,
                         'Destination': '/var/lib/postgresql/data'},
                        {'Type': 'volume', 'Name': 'extra', 'RW': True,
                         'Destination': '/home/customer'}]),
            ('Mounts', [{'Type': 'volume', 'Name': 'private-target'}]),
        ]
        for key, bad in tests:
            with self.subTest(key=key), self.assertRaises(g.IsolationError):
                altered = copy.deepcopy(rows)
                if '.' in key:
                    a, b = key.split('.')
                    altered[0][a][b] = bad
                else:
                    altered[0][key] = bad
                g.validate_containers(altered, S, T, ['source-db'], ['target-db'],
                                      DB_IMAGE)

    def test_four_container_app_restore_with_unique_volumes(self):
        rows = [container('source-db', S, 'source-db-storage'),
                container('source-app', S, 'source-app-private'),
                container('target-db', T, 'target-db-storage'),
                container('target-app', T, 'target-app-private')]
        for index in (1, 3):
            rows[index]['Mounts'][0]['Destination'] = '/var/www/html/storage/app/private'
        g.validate_containers(rows, S, T,
                              ['source-db', 'source-app'], ['target-db', 'target-app'],
                              DB_IMAGE, APP_IMAGE)
        with self.assertRaises(g.IsolationError):
            g.validate_containers(rows[:2], S, T,
                                  ['source-db', 'source-app'], ['target-db', 'target-app'],
                                  DB_IMAGE, APP_IMAGE)
        wrong = copy.deepcopy(rows)
        wrong[1]['Mounts'][0]['Destination'] = '/var/lib/postgresql/data'
        with self.assertRaises(g.IsolationError):
            g.validate_containers(wrong, S, T, ['source-db', 'source-app'],
                                  ['target-db', 'target-app'], DB_IMAGE, APP_IMAGE)

    def test_mixed_runtime_rejects_app_image_or_digest_mismatch(self):
        rows = [container('source-db', S, 'source-db-storage'),
                container('source-app', S, 'source-app-private'),
                container('target-db', T, 'target-db-storage'),
                container('target-app', T, 'target-app-private')]
        for i in (1, 3):
            rows[i]['Mounts'][0]['Destination'] = '/var/www/html/storage/app/private'
        g.validate_containers(rows, S, T,
                              ['source-db', 'source-app'], ['target-db', 'target-app'],
                              DB_IMAGE, APP_IMAGE)
        for position, field, value in (
            (3, 'Image', 'sha256:' + '1'*64),
            (3, 'Config.Image', 'creabiz-ev-business:unreviewed'),
            (0, 'Config.Image', 'postgres:latest'),
            (1, 'Image', 'sha256:bad'),
        ):
            with self.subTest(position=position, field=field):
                modified = copy.deepcopy(rows)
                if '.' in field:
                    key, member = field.split('.')
                    modified[position][key][member] = value
                else:
                    modified[position][field] = value
                with self.assertRaises(g.IsolationError):
                    g.validate_containers(modified, S, T,
                                         ['source-db', 'source-app'],
                                         ['target-db', 'target-app'],
                                         DB_IMAGE, APP_IMAGE)

    def test_inspector_rejects_ambiguous_nested_json_and_never_logs_secrets(self):
        payloads = (
            b'[{"Id":"a","Id":"b"},{}]',
            b'[{"HostConfig":{"Privileged":false,"Privileged":true}},{}]',
            b'[{"Config":{"Env":["DB_PASSWORD=TOP_SECRET"],"Image":NaN}},{}]',
            b'[{"Config":{"Image":Infinity}},{}]',
            b'[{"Config":{"Image":-Infinity}},{}]',
            b'[' + b'[' * 1200 + b']' * 1200 + b']',
        )
        for value in payloads:
            with self.subTest(payload=value[:48]):
                out, err = io.StringIO(), io.StringIO()
                with redirect_stdout(out), redirect_stderr(err):
                    self.assertEqual(g.main([
                        'networks', '--source-network', S, '--target-network', T,
                    ], io.BytesIO(value)), 1)
                self.assertNotIn('TOP_SECRET', out.getvalue() + err.getvalue())
                self.assertNotIn('DB_PASSWORD', out.getvalue() + err.getvalue())

        clean = io.BytesIO(json.dumps([network(S), network(T)]).encode())
        self.assertEqual(g.main([
            'networks', '--source-network', S, '--target-network', T,
        ], clean), 0)

    def test_cli_never_prints_inspect_environment_fields(self):
        rows = [container('source-db', S, 'one'), container('target-db', T, 'two')]
        rows[0]['Config']['Env'] = ['DB_PASSWORD=SUPER_SECRET_DO_NOT_PRINT']
        good = io.BytesIO(json.dumps(rows).encode())
        self.assertEqual(g.main(['containers', '--source-network', S,
                                 '--target-network', T, '--source-name', 'source-db',
                                 '--target-name', 'target-db',
                                 '--reviewed-db-image', DB_IMAGE], good), 0)
        bad = copy.deepcopy(rows)
        bad[0]['HostConfig']['Privileged'] = True
        output, errors = io.StringIO(), io.StringIO()
        with redirect_stdout(output), redirect_stderr(errors):
            self.assertEqual(g.main(['containers', '--source-network', S,
                                     '--target-network', T, '--source-name', 'source-db',
                                     '--target-name', 'target-db',
                                     '--reviewed-db-image', DB_IMAGE],
                                    io.BytesIO(json.dumps(bad).encode())), 1)
        self.assertNotIn('SUPER_SECRET_DO_NOT_PRINT', output.getvalue() + errors.getvalue())
        for invalid in (b'null', b'{}', b'not-json', b'[' + b'a'*1048576):
            with self.subTest(length=len(invalid)):
                self.assertEqual(g.main(['networks', '--source-network', S,
                                         '--target-network', T], io.BytesIO(invalid)), 1)


if __name__ == '__main__':
    unittest.main()
