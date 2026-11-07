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


def network(identifier):
    return {'Id': identifier, 'Internal': True, 'Driver': 'bridge', 'Ingress': False,
            'Attachable': False, 'Scope': 'local', 'Containers': {}}


def container(name, network_id, volume):
    return {
        'Name': '/' + name,
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
        'Mounts': [{'Type': 'volume', 'Name': volume}],
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

    def test_disposable_containers_do_not_share_network_or_volume(self):
        rows = [container('source-db', S, 'private-source'),
                container('target-db', T, 'private-target')]
        g.validate_containers(rows, S, T, ['source-db'], ['target-db'])
        resolved_name = copy.deepcopy(rows)
        resolved_name[0]['HostConfig']['NetworkMode'] = 'recovery-net'
        g.validate_containers(resolved_name, S, T, ['source-db'], ['target-db'])
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
            ('HostConfig.UsernsMode', 'host'),
            ('NetworkSettings.Networks', {'x': {'NetworkID': T}}),
            ('NetworkSettings.Ports', {'5432/tcp': [{'HostPort': '5432'}]}),
            ('NetworkSettings.Ports', ['malformed-list']),
            ('Mounts', [{'Type': 'bind', 'Source': '/etc', 'Destination': '/host'}]),
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
                g.validate_containers(altered, S, T, ['source-db'], ['target-db'])

    def test_four_container_app_restore_with_unique_volumes(self):
        rows = [container('source-db', S, 'source-db-storage'),
                container('source-app', S, 'source-app-private'),
                container('target-db', T, 'target-db-storage'),
                container('target-app', T, 'target-app-private')]
        g.validate_containers(rows, S, T,
                              ['source-db', 'source-app'], ['target-db', 'target-app'])
        with self.assertRaises(g.IsolationError):
            g.validate_containers(rows[:2], S, T,
                                  ['source-db', 'source-app'], ['target-db', 'target-app'])

    def test_cli_never_prints_inspect_environment_fields(self):
        rows = [container('source-db', S, 'one'), container('target-db', T, 'two')]
        rows[0]['Config'] = {'Env': ['DB_PASSWORD=SUPER_SECRET_DO_NOT_PRINT']}
        good = io.BytesIO(json.dumps(rows).encode())
        self.assertEqual(g.main(['containers', '--source-network', S,
                                 '--target-network', T, '--source-name', 'source-db',
                                 '--target-name', 'target-db'], good), 0)
        bad = copy.deepcopy(rows)
        bad[0]['HostConfig']['Privileged'] = True
        output, errors = io.StringIO(), io.StringIO()
        with redirect_stdout(output), redirect_stderr(errors):
            self.assertEqual(g.main(['containers', '--source-network', S,
                                     '--target-network', T, '--source-name', 'source-db',
                                     '--target-name', 'target-db'],
                                    io.BytesIO(json.dumps(bad).encode())), 1)
        self.assertNotIn('SUPER_SECRET_DO_NOT_PRINT', output.getvalue() + errors.getvalue())
        for invalid in (b'null', b'{}', b'not-json', b'[' + b'a'*1048576):
            with self.subTest(length=len(invalid)):
                self.assertEqual(g.main(['networks', '--source-network', S,
                                         '--target-network', T], io.BytesIO(invalid)), 1)


if __name__ == '__main__':
    unittest.main()
