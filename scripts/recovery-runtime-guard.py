#!/usr/bin/env python3
"""Fail-closed check of disposable Docker recovery runtime; no production acceptance."""
import argparse
import base64
import binascii
import json
import re
import sys


class IsolationError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise IsolationError(message)


def read_inspect(stream):
    # Docker inspect includes environment secrets. Never print or save payloads.
    raw = stream.read(1048577)
    require(len(raw) <= 1048576, 'Docker inspection data exceeds one MiB')

    def unique_pairs(pairs):
        obj = {}
        for key, value in pairs:
            require(key not in obj, 'Ambiguous duplicate Docker inspection field')
            obj[key] = value
        return obj

    def reject_nonfinite(_value):
        raise IsolationError('Nonstandard Docker inspection numeric constant')

    try:
        parsed = json.loads(raw, object_pairs_hook=unique_pairs,
                            parse_constant=reject_nonfinite)
    except (ValueError, UnicodeError, RecursionError):
        # A malformed inspect response must never reveal its content, which
        # may contain DB credentials passed through Config.Env.
        raise IsolationError('Invalid Docker inspection JSON') from None
    require(isinstance(parsed, list) and 2 <= len(parsed) <= 4,
            'Unexpected inspected runtime object count')
    return parsed


def validate_networks(rows, source, target, source_members=None, target_members=None):
    require(isinstance(source, str) and isinstance(target, str) and
            source != target and len(source) >= 12 and len(target) >= 12,
            'Distinct Docker network identifiers required')
    require(len(rows) == 2, 'Two recovery networks required')
    ids = set()
    expected = {source: source_members, target: target_members}
    members_requested = source_members is not None or target_members is not None
    if members_requested:
        require(isinstance(source_members, list) and isinstance(target_members, list)
                and all(isinstance(value, str) and len(value) == 64
                        and all(ch in '0123456789abcdef' for ch in value)
                        for value in source_members + target_members)
                and len(source_members) <= 2 and len(target_members) <= 2
                and len(set(source_members + target_members)) ==
                    len(source_members) + len(target_members),
                'Review expected run-owned Docker container IDs')
    for row in rows:
        require(isinstance(row, dict), 'Malformed Docker network inspection')
        identifier = row.get('Id')
        require(identifier in (source, target) and identifier not in ids,
                'Unrecognized or repeated Docker network')
        ids.add(identifier)
        require(row.get('Internal') is True and
                row.get('Driver') == 'bridge' and
                row.get('Ingress') is not True and
                row.get('Attachable') is not True and
                row.get('Scope') == 'local',
                'Recovery network is not an isolated local bridge')
        attached = row.get('Containers')
        require(isinstance(attached, dict),
                'Recovery network membership unavailable')
        desired = expected[identifier] if members_requested else []
        require(set(attached) == set(desired),
                'Unreviewed container attached to disposable recovery network')
    require(ids == {source, target}, 'Missing isolated recovery network')


def parse_environment(config):
    """Read bounded Docker env without printing values or embedded secrets."""
    raw = config.get('Env')
    require(isinstance(raw, list) and len(raw) <= 256,
            'Effective recovery application environment unavailable')
    entries = {}
    for item in raw:
        require(type(item) is str and 0 < len(item) <= 4096 and '=' in item,
                'Malformed effective recovery application environment')
        name, value = item.split('=', 1)
        require(re.fullmatch(r'[A-Za-z_][A-Za-z_0-9]*', name) is not None
                and name not in entries,
                'Duplicate or invalid effective recovery application setting')
        entries[name] = value
    return entries


def validate_app_environment(config, container_name):
    """Require effective isolated app settings, not merely requested input."""
    entries = parse_environment(config)
    expected = {
        'APP_ENV': 'staging',
        'APP_DEBUG': 'false',
        'APP_URL': 'https://recovery.example.invalid',
        'DB_CONNECTION': 'pgsql',
        'DB_HOST': (container_name.replace('-app-', '-db-')
                    if '-app-' in container_name else container_name[:-4] + '-db'),
        'DB_PORT': '5432',
        'DB_DATABASE': 'ev_recovery_review',
        'DB_USERNAME': 'ev_recovery_review',
        'DB_URL': '',
        'SESSION_DRIVER': 'database',
        'SESSION_ENCRYPT': 'true',
        'SESSION_SECURE_COOKIE': 'true',
        'CACHE_STORE': 'database',
        'QUEUE_CONNECTION': 'database',
        'CSMS_SIMULATOR_ENABLED': 'false',
        'CSMS_SESSION_BRIDGE_ENABLED': 'false',
        'CSMS_FAULT_WORKER_ENABLED': 'false',
        'CSMS_COMMAND_WORKER_ENABLED': 'false',
        'CSMS_REMOTE_AUTHORIZATION_ENABLED': 'false',
        'CSMS_INGRESS_AUTH_ENABLED': 'false',
        'OCPI_TEST_COMMANDS_ENABLED': 'false',
        'SUBSCRIPTION_COLLECTION_ENABLED': 'false',
        'PAYOUT_TEST_ENABLED': 'false',
        'EV_BATTERY_SIMULATOR_ENABLED': 'false',
        'MAIL_MAILER': 'log',
    }
    for key, value in expected.items():
        require(entries.get(key) == value,
                'Unsafe effective recovery application setting: ' + key)
    secret = entries.get('APP_KEY', '')
    try:
        key_bytes = base64.b64decode(
            secret[7:], validate=True) if secret.startswith('base64:') else b''
    except (ValueError, binascii.Error):
        key_bytes = b''
    require(len(key_bytes) == 32,
            'Recovery synthetic encryption key is missing or invalid')
    require(re.fullmatch(r'[a-f0-9]{64}', entries.get('DB_PASSWORD', '')) is not None,
            'Recovery synthetic database credential is missing or invalid')
    return entries


def validate_db_environment(config, reviewed_image):
    """Require a synthetic database role with strong, verifiable credentials."""
    entries = parse_environment(config)
    expected_name = ('ev_csms_recovery' if
                     reviewed_image.startswith('postgis/postgis:') else
                     'ev_recovery_review')
    require(entries.get('POSTGRES_DB') == expected_name and
            entries.get('POSTGRES_USER') == expected_name,
            'Recovery database identity differs from synthetic source')
    require(re.fullmatch(r'[a-f0-9]{64}', entries.get('POSTGRES_PASSWORD', '')) is not None,
            'Recovery database credential missing or invalid')
    return entries


def validate_containers(rows, source, target, source_names, target_names,
                        reviewed_db_image=None, reviewed_app_image=None):
    require(source != target and source_names and target_names,
            'Separate source and target required')
    mapping = {name: source for name in source_names}
    mapping.update({name: target for name in target_names})
    require(len(mapping) == len(source_names) + len(target_names) == len(rows),
            'Duplicate or unexpected recovery container names')
    seen = set()
    volumes = set()
    image_ids = {}
    database_env = {}
    application_env = {}
    for row in rows:
        require(isinstance(row, dict), 'Malformed Docker container inspection')
        name = row.get('Name')
        require(isinstance(name, str) and name.startswith('/') and
                name[1:] in mapping and name not in seen,
                'Unrecognized or repeated recovery container')
        seen.add(name)
        netid = mapping[name[1:]]
        host = row.get('HostConfig')
        state = row.get('NetworkSettings')
        require(isinstance(host, dict) and isinstance(state, dict),
                'Incomplete Docker isolation configuration')
        container_name = name[1:]
        if '-app-' in container_name or container_name.endswith('-app'):
            role = 'app'
            reviewed = reviewed_app_image
        elif '-db-' in container_name or container_name.endswith('-db'):
            role = 'db'
            reviewed = reviewed_db_image
        else:
            raise IsolationError('Unexpected recovery container role')
        config = row.get('Config')
        require(isinstance(config, dict) and
                isinstance(config.get('Image'), str),
                'Unverifiable container image reference')
        require(isinstance(row.get('State'), dict) and
                row['State'].get('Running') is True,
                'Recovery container is not running')
        restart = host.get('RestartPolicy')
        require(isinstance(restart, dict) and restart.get('Name') == 'no' and
                restart.get('MaximumRetryCount', 0) == 0,
                'Recovery container has an unsafe restart policy')
        if role == 'app':
            require(netid not in application_env,
                    'Duplicate application role on recovery network')
            application_env[netid] = validate_app_environment(config, container_name)
        else:
            require(netid not in database_env and isinstance(reviewed_db_image, str),
                    'Unexpected database role on recovery network')
            database_env[netid] = validate_db_environment(config, reviewed_db_image)
        if reviewed_db_image is not None:
            require(isinstance(reviewed, str) and config['Image'] == reviewed,
                    'Recovery container image deviates from reviewed input')
        image_id = row.get('Image')
        require(isinstance(image_id, str) and len(image_id) == 71 and
                image_id.startswith('sha256:') and
                all(c in '0123456789abcdef' for c in image_id[7:]),
                'Recovery container image ID is not a complete immutable digest')
        if role in image_ids:
            require(image_id == image_ids[role],
                    'Source and target of same recovery role use different image IDs')
        else:
            image_ids[role] = image_id
        attachments = state.get('Networks')
        require(isinstance(attachments, dict) and len(attachments) == 1,
                'Unexpected additional network attachment')
        attached_name, attached = next(iter(attachments.items()))
        require(isinstance(attached, dict) and attached.get('NetworkID') == netid,
                'Container attached to an unreviewed network')
        # Docker may report either the passed ID or the resolved network
        # name. Both are valid only for the single verified NetworkID.
        require(host.get('NetworkMode') in (netid, attached_name),
                'Container escaped its dedicated network')
        ports = state.get('Ports')
        require(ports is None or isinstance(ports, dict),
                'Invalid container port publication metadata')
        require(host.get('PortBindings') in (None, {}) and
                all(value is None for value in (ports or {}).values()),
                'Recovery container has a host-published port')
        require(host.get('Privileged') is False and
                host.get('CapAdd') in (None, []) and
                host.get('Devices') in (None, []) and
                host.get('Binds') in (None, []) and
                host.get('ExtraHosts') in (None, []) and
                host.get('Links') in (None, []),
                'Recovery container has host access or elevated privileges')
        require(host.get('PidMode') not in ('host',) and
                host.get('IpcMode') not in ('host',) and
                host.get('UsernsMode') not in ('host',) and
                host.get('CgroupnsMode') not in ('host',) and
                host.get('UTSMode') not in ('host',),
                'Recovery container shares host namespaces')
        require(host.get('SecurityOpt') in (None, []) and
                host.get('DeviceRequests') in (None, []) and
                host.get('DeviceCgroupRules') in (None, []),
                'Recovery container has non-reviewed security or device privileges')
        # The same reviewed role determines both the image and mount target;
        # source and restored target must use byte-identical image IDs.
        if role == 'app':
            destination = '/var/www/html/storage/app/private'
        else:
            destination = '/var/lib/postgresql/data'
        mounts = row.get('Mounts')
        require(isinstance(mounts, list) and len(mounts) == 1,
                'Exactly one recovery volume required for each container')
        mount = mounts[0]
        require(isinstance(mount, dict) and mount.get('Type') == 'volume'
                and mount.get('Destination') == destination
                and mount.get('RW') is True,
                'Recovery volume not writable at its reviewed destination')
        volume_name = mount.get('Name')
        require(isinstance(volume_name, str) and bool(volume_name) and
                volume_name not in volumes,
                'Recovery source and target must not share a volume')
        volumes.add(volume_name)
    require(set(database_env) == {source, target},
            'Both isolated recovery databases must be present')
    require(database_env[source]['POSTGRES_PASSWORD'] !=
            database_env[target]['POSTGRES_PASSWORD'],
            'Source and target recovery database credentials must differ')
    for netid, app in application_env.items():
        require(app['DB_PASSWORD'] == database_env[netid]['POSTGRES_PASSWORD'],
                'Recovery application database credential does not match its isolated DB')
    if application_env:
        require(set(application_env) == {source, target} and
                application_env[source]['APP_KEY'] == application_env[target]['APP_KEY'],
                'Both isolated recovery apps must preserve the same synthetic key')
    require(seen == {'/' + n for n in mapping},
            'Recovery runtime inspection was incomplete')


def main(argv=None, input_stream=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('check', choices=('networks', 'containers'))
    parser.add_argument('--source-network', required=True)
    parser.add_argument('--target-network', required=True)
    parser.add_argument('--source-name', action='append', default=[])
    parser.add_argument('--target-name', action='append', default=[])
    parser.add_argument('--source-container', action='append')
    parser.add_argument('--target-container', action='append')
    parser.add_argument('--reviewed-db-image')
    parser.add_argument('--reviewed-app-image')
    args = parser.parse_args(argv)
    try:
        inspected = read_inspect(input_stream or sys.stdin.buffer)
        if args.check == 'networks':
            validate_networks(inspected, args.source_network, args.target_network,
                              args.source_container, args.target_container)
        else:
            require(isinstance(args.reviewed_db_image, str) and
                    bool(args.reviewed_db_image) and
                    (not any('-app-' in n for n in
                             args.source_name + args.target_name) or
                     bool(args.reviewed_app_image)),
                    'Both reviewed runtime image references must be supplied')
            validate_containers(inspected, args.source_network,
                                args.target_network, args.source_name, args.target_name,
                                args.reviewed_db_image, args.reviewed_app_image)
    except IsolationError as error:
        # Never echo Docker inspect JSON, credentials or environment fields.
        print('FAIL: ' + str(error), file=sys.stderr)
        return 1
    print('PASS: disposable recovery runtime isolation only; not production acceptance')
    return 0


if __name__ == '__main__':
    sys.exit(main())
