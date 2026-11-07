#!/usr/bin/env python3
"""Fail-closed check of disposable Docker recovery runtime; no production acceptance."""
import argparse
import json
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
    try:
        parsed = json.loads(raw)
    except (ValueError, UnicodeError):
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


def validate_containers(rows, source, target, source_names, target_names):
    require(source != target and source_names and target_names,
            'Separate source and target required')
    mapping = {name: source for name in source_names}
    mapping.update({name: target for name in target_names})
    require(len(mapping) == len(source_names) + len(target_names) == len(rows),
            'Duplicate or unexpected recovery container names')
    seen = set()
    volumes = set()
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
        # The script owns each container name: database and application
        # mounts have distinct, reviewed destinations. A changed image with
        # additional VOLUME directives must fail before data restoration.
        container_name = name[1:]
        if '-app-' in container_name or container_name.endswith('-app'):
            destination = '/var/www/html/storage/app/private'
        elif '-db-' in container_name or container_name.endswith('-db'):
            destination = '/var/lib/postgresql/data'
        else:
            raise IsolationError('Unexpected recovery container role')
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
    args = parser.parse_args(argv)
    try:
        inspected = read_inspect(input_stream or sys.stdin.buffer)
        if args.check == 'networks':
            validate_networks(inspected, args.source_network, args.target_network,
                              args.source_container, args.target_container)
        else:
            validate_containers(inspected, args.source_network,
                                args.target_network, args.source_name, args.target_name)
    except IsolationError as error:
        # Never echo Docker inspect JSON, credentials or environment fields.
        print('FAIL: ' + str(error), file=sys.stderr)
        return 1
    print('PASS: disposable recovery runtime isolation only; not production acceptance')
    return 0


if __name__ == '__main__':
    sys.exit(main())
