#!/usr/bin/env python3
"""Create/verify a private, development-only EV mobile demonstration bundle.

No network, credentials, Git operations, Android signing, or deployment.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import zipfile

SCHEMA = 'ev-development-delivery-v1'
FILES = {'android-debug': ('android-development.apk', '.apk'),
         'ios-simulator': ('ios-simulator.zip', '.zip')}
SHA = re.compile(r'^[0-9a-f]{40}$')
DIGEST = re.compile(r'^[0-9a-f]{64}$')
MAX_ENTRIES = 100_000
MAX_EXPANDED = 8 * 1024**3


class BundleError(Exception):
    pass


def regular_private_file(path):
    path = Path(path)
    if path.is_symlink() or not path.is_file():
        raise BundleError('Input must be a regular non-symlink file')
    if path.stat().st_size < 1:
        raise BundleError('Empty delivery artifact')
    return path


def checksum(path):
    digest = hashlib.sha256()
    size = 0
    with open(path, 'rb') as file:
        while data := file.read(1024 * 1024):
            size += len(data)
            digest.update(data)
    return digest.hexdigest(), size


def archive_check(path, platform):
    try:
        with zipfile.ZipFile(path) as archive:
            files = archive.infolist()
            if not files or len(files) > MAX_ENTRIES:
                raise BundleError('Archive entry count outside safe bounds')
            names = set()
            expanded = 0
            has_apk = False
            has_ios = False
            for entry in files:
                name = entry.filename
                parts = name.rstrip('/').split('/')
                if not name or '\\' in name or '\x00' in name or name.startswith('/') or \
                        any(p in ('', '.', '..') for p in parts) or ':' in parts[0]:
                    raise BundleError('Unsafe archive member path')
                if name in names:
                    raise BundleError('Duplicate archive member')
                names.add(name)
                expanded += entry.file_size
                if expanded > MAX_EXPANDED:
                    raise BundleError('Expanded archive exceeds safe limit')
                member_mode = (entry.external_attr >> 16) & 0o170000
                if member_mode == stat.S_IFLNK:
                    # Frameworks in simulator builds can have internal relative symlinks.
                    link = archive.read(entry).decode('utf-8', errors='strict')
                    if not link or link.startswith('/') or '\\' in link or '\x00' in link:
                        raise BundleError('Unsafe archive symlink')
                    resolved = parts[:-1]
                    for part in link.split('/'):
                        if part in ('', '.'):
                            continue
                        if part == '..':
                            if not resolved:
                                raise BundleError('Archive symlink escapes bundle root')
                            resolved.pop()
                        else:
                            resolved.append(part)
                elif member_mode not in (0, stat.S_IFREG, stat.S_IFDIR):
                    raise BundleError('Unsupported archive special file')
                has_apk |= name == 'AndroidManifest.xml'
                has_ios |= bool(re.search(r'(^|/)[^/]+\.app/Info\.plist$', name))
            if platform == 'android-debug' and not has_apk:
                raise BundleError('APK missing AndroidManifest.xml')
            if platform == 'ios-simulator' and not has_ios:
                raise BundleError('Simulator archive missing an app Info.plist')
            if archive.testzip() is not None:
                raise BundleError('Archive CRC verification failed')
    except (zipfile.BadZipFile, zipfile.LargeZipFile, UnicodeDecodeError, EOFError) as exc:
        raise BundleError('Corrupt or unsupported ZIP/APK archive') from exc


def verify_workflow_reference(workflow, source_sha):
    """Fail closed on bundled provenance claims without implying signed GitHub attestation."""
    expected = {'provider', 'repository', 'workflow_name', 'run_id', 'head_sha',
                'android', 'ios', 'snapshot_authenticated'}
    identified = {'workflow_id', 'workflow_path'}
    # Earlier developer bundles carry only the workflow name; they remain
    # verifiable but newer imports must bind the reviewed workflow identity.
    if not isinstance(workflow, dict) or set(workflow) not in (expected, expected | identified):
        raise BundleError('Invalid optional workflow evidence schema')
    if workflow['provider'] != 'github_actions' or workflow['repository'] != 'creabizsalonspa-create/EV' or \
            workflow['workflow_name'] != 'Hasone customer development builds' or \
            type(workflow['run_id']) is not int or workflow['run_id'] <= 0 or \
            workflow['head_sha'] != source_sha or workflow['snapshot_authenticated'] is not False:
        raise BundleError('Unverifiable workflow provenance or false authentication claim')
    if identified <= set(workflow) and (
            type(workflow['workflow_id']) is not int or
            workflow['workflow_id'] != 410027022 or
            workflow['workflow_path'] != '.github/workflows/hasone-customer.yml'):
        raise BundleError('Wrong GitHub mobile workflow identity')
    ids = set()
    for role in ('android', 'ios'):
        data = workflow[role]
        if not isinstance(data, dict) or set(data) != {'artifact_id', 'wrapper_sha256'} or \
                type(data['artifact_id']) is not int or data['artifact_id'] <= 0 or \
                not isinstance(data['wrapper_sha256'], str) or not DIGEST.fullmatch(data['wrapper_sha256']):
            raise BundleError('Invalid workflow platform artifact metadata')
        ids.add(data['artifact_id'])
    if len(ids) != 2:
        raise BundleError('Duplicate GitHub workflow artifact IDs')


def verify(folder, expected_source=None):
    root = Path(folder)
    if root.is_symlink() or not root.is_dir():
        raise BundleError('Bundle must be a directory, not a symlink')
    manifest_path = regular_private_file(root / 'manifest.json')
    if manifest_path.stat().st_size > 64 * 1024:
        raise BundleError('Manifest is unexpectedly large')
    try:
        manifest = json.loads(manifest_path.read_text('utf-8'))
    except (ValueError, UnicodeError) as exc:
        raise BundleError('Invalid manifest JSON') from exc
    core = {'schema', 'source_sha', 'created_at_utc', 'acceptance', 'artifacts'}
    if not isinstance(manifest, dict) or set(manifest) not in (core, core | {'workflow_evidence'}):
        raise BundleError('Manifest fields do not match approved schema')
    if manifest['schema'] != SCHEMA or not isinstance(manifest['source_sha'], str) or not SHA.fullmatch(manifest['source_sha']):
        raise BundleError('Invalid delivery schema or source reference')
    if expected_source is not None and manifest['source_sha'] != expected_source:
        raise BundleError('Package source does not match requested checkpoint')
    if manifest['acceptance'] != {'kind': 'development_demo_only', 'production_accepted': False, 'signed_for_store': False}:
        raise BundleError('Unsafe acceptance or signing claim')
    if 'workflow_evidence' in manifest:
        verify_workflow_reference(manifest['workflow_evidence'], manifest['source_sha'])
    if not isinstance(manifest['created_at_utc'], str) or not manifest['created_at_utc'].endswith('Z'):
        raise BundleError('Invalid bundle creation timestamp')
    if not isinstance(manifest['artifacts'], list) or len(manifest['artifacts']) != 2:
        raise BundleError('Bundle requires one Android and one iOS simulator artifact')
    seen = set()
    for item in manifest['artifacts']:
        if not isinstance(item, dict) or set(item) != {'platform', 'path', 'sha256', 'size_bytes'}:
            raise BundleError('Unexpected artifact metadata')
        platform = item['platform']
        if platform not in FILES or platform in seen:
            raise BundleError('Duplicate or unknown artifact role')
        seen.add(platform)
        name = FILES[platform][0]
        if item['path'] != name or not isinstance(item['sha256'], str) or not DIGEST.fullmatch(item['sha256']) or \
                type(item['size_bytes']) is not int or item['size_bytes'] < 1:
            raise BundleError('Invalid artifact path, size, or digest metadata')
        artifact = regular_private_file(root / name)
        actual_digest, actual_size = checksum(artifact)
        if actual_digest != item['sha256'] or actual_size != item['size_bytes']:
            raise BundleError('Artifact bytes differ from manifest')
        archive_check(artifact, platform)
    if {p.name for p in root.iterdir()} != {'manifest.json', 'android-development.apk', 'ios-simulator.zip'}:
        raise BundleError('Unexpected files in delivery bundle')
    return manifest


def create(output, source_sha, android, ios, workflow_evidence=None):
    if not SHA.fullmatch(source_sha):
        raise BundleError('Source reference must be an exact 40-character commit SHA')
    source = {'android-debug': regular_private_file(android), 'ios-simulator': regular_private_file(ios)}
    for platform, artifact in source.items():
        archive_check(artifact, platform)
    target = Path(output)
    if not target.parent.is_dir() or target.parent.is_symlink():
        raise BundleError('Output parent must already exist as a real directory')
    # Exclusive mkdir makes the operation non-destructive, even on concurrent invocations.
    try:
        target.mkdir(mode=0o700, exist_ok=False)
    except FileExistsError as exc:
        raise BundleError('Output directory already exists; never overwrite delivery bundles') from exc
    try:
        artifacts = []
        for platform, original in source.items():
            name = FILES[platform][0]
            dest = target / name
            with open(original, 'rb') as input_file, open(dest, 'xb') as output_file:
                shutil.copyfileobj(input_file, output_file, length=1024*1024)
            os.chmod(dest, 0o600)
            digest, size = checksum(dest)
            artifacts.append({'platform': platform, 'path': name, 'sha256': digest, 'size_bytes': size})
        manifest = {'schema': SCHEMA, 'source_sha': source_sha,
                    'created_at_utc': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00', 'Z'),
                    'acceptance': {'kind': 'development_demo_only', 'production_accepted': False, 'signed_for_store': False},
                    'artifacts': artifacts}
        if workflow_evidence is not None:
            verify_workflow_reference(workflow_evidence, source_sha)
            manifest['workflow_evidence'] = workflow_evidence
        with open(target / 'manifest.json', 'x', encoding='utf-8') as out:
            json.dump(manifest, out, indent=2, sort_keys=True)
            out.write('\n')
        os.chmod(target / 'manifest.json', 0o600)
        verify(target, source_sha)
    except BaseException:
        shutil.rmtree(target)
        raise
    return manifest


def main(argv=None):
    parser = argparse.ArgumentParser(description='Offline EV development-delivery bundle integrity check; no signing or deployment')
    modes = parser.add_subparsers(dest='command', required=True)
    create_args = modes.add_parser('create', help='Copy verified Android APK and iOS simulator ZIP into a NEW private bundle')
    create_args.add_argument('--output', required=True)
    create_args.add_argument('--source-sha', required=True)
    create_args.add_argument('--android', required=True)
    create_args.add_argument('--ios', required=True)
    verify_args = modes.add_parser('verify', help='Verify bundle contents and full ZIP CRC without changes')
    verify_args.add_argument('--bundle', required=True)
    verify_args.add_argument('--expected-source-sha', required=True)
    args = parser.parse_args(argv)
    try:
        if args.command == 'create':
            create(args.output, args.source_sha, args.android, args.ios)
        else:
            verify(args.bundle, args.expected_source_sha)
    except (BundleError, OSError, PermissionError):
        print('FAIL: delivery bundle content, source, archive or filesystem integrity requires review; private paths withheld.', file=sys.stderr)
        return 1
    print('PASS: offline development-only bundle integrity verified; build provenance, real devices, store signing and production acceptance require separate evidence.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
