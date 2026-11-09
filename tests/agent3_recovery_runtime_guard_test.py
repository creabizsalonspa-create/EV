"""No-Docker synthetic runtime negative tests for recovery container isolation."""
import base64
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


def synthetic_password(name):
    return ('f' if name.startswith('source') or name.endswith('source') else 'e') * 64


def safe_db_env(name):
    return ['POSTGRES_DB=ev_recovery_review',
            'POSTGRES_USER=ev_recovery_review',
            'POSTGRES_PASSWORD=' + synthetic_password(name)]


def safe_app_env(name):
    db_host = name.replace('-app-', '-db-') if '-app-' in name else name[:-4] + '-db'
    fields = {
        'APP_ENV': 'staging', 'APP_DEBUG': 'false',
        'APP_URL': 'https://recovery.example.invalid',
        'APP_KEY': 'base64:' + base64.b64encode(b'k' * 32).decode(),
        'DB_CONNECTION': 'pgsql', 'DB_HOST': db_host, 'DB_PORT': '5432',
        'DB_DATABASE': 'ev_recovery_review', 'DB_USERNAME': 'ev_recovery_review',
        'DB_PASSWORD': synthetic_password(name), 'DB_URL': '',
        'SESSION_DRIVER': 'database', 'SESSION_ENCRYPT': 'true',
        'SESSION_SECURE_COOKIE': 'true', 'CACHE_STORE': 'database',
        'QUEUE_CONNECTION': 'database', 'MAIL_MAILER': 'log',
        'LOG_CHANNEL': 'stderr', 'LOG_LEVEL': 'warning',
        'CSMS_SIMULATOR_ENABLED': 'false', 'CSMS_SESSION_BRIDGE_ENABLED': 'false',
        'CSMS_FAULT_WORKER_ENABLED': 'false',
        'CSMS_COMMAND_WORKER_ENABLED': 'false',
        'CSMS_REMOTE_AUTHORIZATION_ENABLED': 'false',
        'CSMS_INGRESS_AUTH_ENABLED': 'false',
        'OCPI_TEST_COMMANDS_ENABLED': 'false',
        'SUBSCRIPTION_COLLECTION_ENABLED': 'false',
        'PAYOUT_TEST_ENABLED': 'false', 'EV_BATTERY_SIMULATOR_ENABLED': 'false',
    }
    return [key + '=' + value for key, value in fields.items()]


def container(name, network_id, volume):
    return {
        'Name': '/' + name,
        'Config': {'Image': APP_IMAGE if '-app' in name else DB_IMAGE,
                   'Env': safe_app_env(name) if '-app' in name else safe_db_env(name)},
        'Image': APP_ID if '-app' in name else DB_ID,
        'State': {'Running': True},
        'HostConfig': {
            'NetworkMode': network_id, 'Privileged': False,
            'RestartPolicy': {'Name': 'no', 'MaximumRetryCount': 0},
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

    def test_effective_app_environment_rejects_unsafe_feature_switches(self):
        rows = [container('source-db', S, 'db-source'),
                container('source-app', S, 'app-source'),
                container('target-db', T, 'db-target'),
                container('target-app', T, 'app-target')]
        for index in (1, 3):
            rows[index]['Mounts'][0]['Destination'] = '/var/www/html/storage/app/private'
        args = (S, T, ['source-db', 'source-app'],
                ['target-db', 'target-app'], DB_IMAGE, APP_IMAGE)
        g.validate_containers(rows, *args)
        for name, value in (
            ('APP_ENV', 'production'), ('APP_DEBUG', 'true'),
            ('APP_URL', 'https://real.example.test'),
            ('DB_CONNECTION', 'mysql'), ('DB_HOST', 'target-db'),
            ('DB_DATABASE', 'customer_db'), ('DB_USERNAME', 'postgres'),
            ('DB_URL', 'postgresql://external.invalid/real'),
            ('SESSION_DRIVER', 'redis'), ('SESSION_ENCRYPT', 'false'),
            ('SESSION_SECURE_COOKIE', 'false'), ('CACHE_STORE', 'redis'),
            ('QUEUE_CONNECTION', 'redis'), ('MAIL_MAILER', 'smtp'),
            ('LOG_CHANNEL', 'slack'), ('LOG_LEVEL', 'debug'),
            ('CSMS_SIMULATOR_ENABLED', 'true'),
            ('CSMS_SESSION_BRIDGE_ENABLED', 'true'),
            ('CSMS_FAULT_WORKER_ENABLED', 'true'),
            ('CSMS_COMMAND_WORKER_ENABLED', 'true'),
            ('CSMS_REMOTE_AUTHORIZATION_ENABLED', 'true'),
            ('CSMS_INGRESS_AUTH_ENABLED', 'true'),
            ('OCPI_TEST_COMMANDS_ENABLED', 'true'),
            ('SUBSCRIPTION_COLLECTION_ENABLED', 'true'),
            ('PAYOUT_TEST_ENABLED', 'true'),
            ('EV_BATTERY_SIMULATOR_ENABLED', 'true'),
            ('APP_KEY', 'base64:bad'),
            ('DB_PASSWORD', 'weak'),
        ):
            with self.subTest(setting=name):
                altered = copy.deepcopy(rows)
                prefix = name + '='
                altered[1]['Config']['Env'] = [
                    prefix + value if field.startswith(prefix) else field
                    for field in altered[1]['Config']['Env']
                ]
                with self.assertRaises(g.IsolationError):
                    g.validate_containers(altered, *args)
        for mutation in ('missing', 'duplicate', 'invalid-type',
                         'unexpected-host', 'wrong-role',
                         'missing-log-channel', 'missing-log-level'):
            with self.subTest(mutation=mutation):
                altered = copy.deepcopy(rows)
                values = altered[1]['Config']['Env']
                if mutation == 'missing':
                    altered[1]['Config'].pop('Env')
                elif mutation == 'duplicate':
                    values.append('APP_ENV=production')
                elif mutation == 'invalid-type':
                    values.append({'not': 'a string'})
                elif mutation == 'unexpected-host':
                    altered[1]['Config']['Env'] = [
                        'DB_HOST=production.example' if item.startswith('DB_HOST=') else item
                        for item in values
                    ]
                elif mutation == 'missing-log-channel':
                    altered[1]['Config']['Env'] = [
                        item for item in values if not item.startswith('LOG_CHANNEL=')
                    ]
                elif mutation == 'missing-log-level':
                    altered[1]['Config']['Env'] = [
                        item for item in values if not item.startswith('LOG_LEVEL=')
                    ]
                else:
                    altered[3]['Config']['Env'] = safe_app_env('source-app')
                with self.assertRaises(g.IsolationError):
                    g.validate_containers(altered, *args)

    def test_postgresql_credentials_are_isolated_and_match_their_own_apps(self):
        rows = [container('source-db', S, 'db-source'),
                container('source-app', S, 'app-source'),
                container('target-db', T, 'db-target'),
                container('target-app', T, 'app-target')]
        for index in (1, 3):
            rows[index]['Mounts'][0]['Destination'] = '/var/www/html/storage/app/private'
        args = (S, T, ['source-db', 'source-app'],
                ['target-db', 'target-app'], DB_IMAGE, APP_IMAGE)
        g.validate_containers(rows, *args)
        for container_index, old_value, new_value in (
            (0, 'POSTGRES_DB=ev_recovery_review', 'POSTGRES_DB=customer_db'),
            (2, 'POSTGRES_USER=ev_recovery_review', 'POSTGRES_USER=postgres'),
            (0, 'POSTGRES_PASSWORD=' + 'f'*64, 'POSTGRES_PASSWORD=' + 'e'*64),
            (0, 'POSTGRES_PASSWORD=' + 'f'*64, 'POSTGRES_PASSWORD=weak'),
            (1, 'DB_PASSWORD=' + 'f'*64, 'DB_PASSWORD=' + 'e'*64),
            (3, 'APP_KEY=' + 'base64:' + base64.b64encode(b'k'*32).decode(),
                  'APP_KEY=' + 'base64:' + base64.b64encode(b'z'*32).decode()),
        ):
            with self.subTest(container=container_index, changed=old_value.split('=')[0]):
                modified = copy.deepcopy(rows)
                env = modified[container_index]['Config']['Env']
                env[env.index(old_value)] = new_value
                with self.assertRaises(g.IsolationError):
                    g.validate_containers(modified, *args)

        # CSMS/PostGIS has a separate synthetic DB identity and no Laravel app.
        csms_image = 'postgis/postgis:16-3.5@sha256:' + 'a' * 64
        postgis = [container('source-db', S, 'csms-source'),
                   container('target-db', T, 'csms-target')]
        for row in postgis:
            row['Config']['Image'] = csms_image
            row['Config']['Env'] = [
                value.replace('ev_recovery_review', 'ev_csms_recovery')
                for value in row['Config']['Env']
            ]
        g.validate_containers(postgis, S, T, ['source-db'], ['target-db'], csms_image)
        postgis[1]['Config']['Env'][0] = 'POSTGRES_DB=ev_recovery_review'
        with self.assertRaises(g.IsolationError):
            g.validate_containers(postgis, S, T,
                                  ['source-db'], ['target-db'], csms_image)

    def test_postgresql_initdb_authentication_and_storage_fail_closed(self):
        rows = [container('source-db', S, 'source-pgdata'),
                container('target-db', T, 'target-pgdata')]
        args = (S, T, ['source-db'], ['target-db'], DB_IMAGE)
        g.validate_containers(rows, *args)

        secure = copy.deepcopy(rows)
        secure[0]['Config']['Env'].extend([
            'POSTGRES_HOST_AUTH_METHOD=scram-sha-256',
            'PGDATA=/var/lib/postgresql/data',
        ])
        g.validate_containers(secure, *args)

        for index, value in (
            (0, 'POSTGRES_HOST_AUTH_METHOD=trust'),
            (1, 'POSTGRES_HOST_AUTH_METHOD=md5'),
            (0, 'POSTGRES_INITDB_ARGS=--auth-host=trust'),
            (1, 'POSTGRES_INITDB_ARGS=--auth-local=trust'),
            (0, 'POSTGRES_INITDB_WALDIR=/tmp/unreviewed-wal'),
            (1, 'PGDATA=/tmp/unreviewed-data'),
            (0, 'PGDATA='),
        ):
            with self.subTest(container=index, key=value.split('=')[0]):
                changed = copy.deepcopy(rows)
                changed[index]['Config']['Env'].append(value)
                with self.assertRaises(g.IsolationError):
                    g.validate_containers(changed, *args)

        # The same guard must cover the independent CSMS/PostGIS fixture.
        postgis_image = 'postgis/postgis:16-3.5@sha256:' + 'a' * 64
        postgis = copy.deepcopy(rows)
        for row in postgis:
            row['Config']['Image'] = postgis_image
            row['Config']['Env'] = [
                field.replace('ev_recovery_review', 'ev_csms_recovery')
                for field in row['Config']['Env']
            ]
        g.validate_containers(postgis, S, T,
                              ['source-db'], ['target-db'], postgis_image)
        postgis[0]['Config']['Env'].append('POSTGRES_HOST_AUTH_METHOD=trust')
        with self.assertRaises(g.IsolationError):
            g.validate_containers(postgis, S, T,
                                  ['source-db'], ['target-db'], postgis_image)

    def test_effective_runtime_must_be_running_and_never_restart(self):
        rows = [container('source-db', S, 'source'),
                container('target-db', T, 'target')]
        g.validate_containers(rows, S, T, ['source-db'], ['target-db'], DB_IMAGE)
        for path, invalid in (
            (('State', 'Running'), False),
            (('State', 'Running'), 'true'),
            (('HostConfig', 'RestartPolicy'), {'Name': 'always', 'MaximumRetryCount': 0}),
            (('HostConfig', 'RestartPolicy'), {'Name': 'on-failure', 'MaximumRetryCount': 3}),
            (('HostConfig', 'RestartPolicy'), {}),
            (('HostConfig', 'RestartPolicy'), None),
        ):
            with self.subTest(path=path, value=invalid):
                altered = copy.deepcopy(rows)
                altered[0][path[0]][path[1]] = invalid
                with self.assertRaises(g.IsolationError):
                    g.validate_containers(altered, S, T, ['source-db'],
                                          ['target-db'], DB_IMAGE)

    def test_effective_inspect_errors_do_not_expose_secret_values(self):
        rows = [container('source-db', S, 'db-source'),
                container('source-app', S, 'app-source'),
                container('target-db', T, 'db-target'),
                container('target-app', T, 'app-target')]
        for index in (1, 3):
            rows[index]['Mounts'][0]['Destination'] = '/var/www/html/storage/app/private'
        rows[1]['Config']['Env'].extend([
            'PRIVATE_TEST_SECRET=NEVER_PRINT_THIS_VALUE',
            'CSMS_COMMAND_WORKER_ENABLED=true',
        ])
        output, errors = io.StringIO(), io.StringIO()
        with redirect_stdout(output), redirect_stderr(errors):
            code = g.main(['containers', '--source-network', S,
                           '--target-network', T,
                           '--source-name', 'source-db', '--source-name', 'source-app',
                           '--target-name', 'target-db', '--target-name', 'target-app',
                           '--reviewed-db-image', DB_IMAGE,
                           '--reviewed-app-image', APP_IMAGE],
                          io.BytesIO(json.dumps(rows).encode()))
        self.assertEqual(code, 1)
        self.assertNotIn('NEVER_PRINT_THIS_VALUE', output.getvalue() + errors.getvalue())


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
        rows[0]['Config']['Env'].append('PRIVATE_TEST_TOKEN=SUPER_SECRET_DO_NOT_PRINT')
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
