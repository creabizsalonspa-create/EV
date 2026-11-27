"""Inspect an IPA package without extracting it or claiming signature acceptance."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import plistlib
import re
import stat
import sys
import zipfile


class AuditError(ValueError):
    pass


def require(condition, message):
    if not condition:
        raise AuditError(message)


def digest(path):
    sha = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            sha.update(chunk)
    return sha.hexdigest()


def audit(path, expected_id, version, build):
    require(re.fullmatch(r'[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+){2,}', expected_id)
            and not expected_id.lower().endswith(('.dev', '.debug', '.test')), 'Expected approved release bundle ID')
    require(re.fullmatch(r'[0-9]+(?:\.[0-9]+){0,2}', version), 'Expected release version')
    require(re.fullmatch(r'[0-9]+(?:\.[0-9]+){0,2}', build), 'Expected build number')
    path = Path(path)
    before = digest(path)
    with zipfile.ZipFile(path) as archive:
        entries = {}
        names = set()
        apps = set()
        for entry in archive.infolist():
            name = entry.filename.rstrip('/')
            parts = name.split('/')
            require(name and '\\' not in name and all(p not in ('', '.', '..') for p in parts)
                    and not any(':' in p or any(ord(c) < 32 for c in p) for p in parts), 'Unsafe archive path')
            require(name.casefold() not in names, 'Duplicate or case-colliding archive path')
            names.add(name.casefold())
            mode = stat.S_IFMT(entry.external_attr >> 16)
            require(mode in (0, stat.S_IFREG, stat.S_IFDIR), 'Unsupported special archive entry')
            require(not (entry.flag_bits & 1), 'Encrypted archive entry')
            if len(parts) >= 2 and parts[0] == 'Payload' and parts[1].endswith('.app'):
                apps.add('/'.join(parts[:2]))
            if not entry.is_dir():
                entries[name] = entry
        require(len(apps) == 1, 'Expected exactly one top-level Payload application')
        app = apps.pop()
        info = entries.get(app + '/Info.plist')
        require(info is not None and 0 < info.file_size <= 1024 * 1024, 'Missing or oversized application Info.plist')
        metadata = plistlib.loads(archive.read(info))
        require(isinstance(metadata, dict), 'Invalid application Info.plist')
        require(metadata.get('CFBundleIdentifier') == expected_id, 'Bundle identifier mismatch')
        require(metadata.get('CFBundleShortVersionString') == version, 'Release version mismatch')
        require(metadata.get('CFBundleVersion') == build, 'Build number mismatch')
        require(metadata.get('CFBundlePackageType') == 'APPL'
                and metadata.get('CFBundleSupportedPlatforms') == ['iPhoneOS']
                and metadata.get('DTPlatformName', 'iphoneos') == 'iphoneos', 'Not an iPhoneOS application package')
        minimum = metadata.get('MinimumOSVersion')
        require(isinstance(minimum, str) and re.fullmatch(r'[0-9]+(?:\.[0-9]+){1,2}', minimum), 'Missing minimum iOS version')
        executable = metadata.get('CFBundleExecutable')
        require(isinstance(executable, str) and executable not in ('', '.', '..')
                and PurePosixPath(executable).name == executable and '\\' not in executable, 'Invalid executable name')
        binary = entries.get(app + '/' + executable)
        require(binary is not None and binary.file_size >= 4, 'Missing application executable')
        with archive.open(binary) as stream:
            require(stream.read(4) in (b'\xcf\xfa\xed\xfe', b'\xfe\xed\xfa\xcf', b'\xca\xfe\xba\xbe', b'\xca\xfe\xba\xbf'), 'Unexpected executable format')
        require(app + '/_CodeSignature/CodeResources' in entries, 'Missing code signature resources')
    require(before == digest(path), 'Artifact changed during inspection')
    return {'bundle_id': expected_id, 'version': version, 'build': build,
            'minimum_ios': minimum, 'sha256': before, 'package_structure_verified': True,
            'signature_verified': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('ipa', type=Path)
    parser.add_argument('--bundle-id', required=True)
    parser.add_argument('--version', required=True)
    parser.add_argument('--build', required=True)
    args = parser.parse_args()
    try:
        result = audit(args.ipa, args.bundle_id, args.version, args.build)
    except AuditError as exc:
        print('IPA AUDIT FAILED: ' + str(exc), file=sys.stderr)
        return 1
    except (OSError, ValueError, TypeError, RuntimeError, zipfile.BadZipFile, plistlib.InvalidFileException):
        print('IPA AUDIT FAILED: invalid or unreadable archive', file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
