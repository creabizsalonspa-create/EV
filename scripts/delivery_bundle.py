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
MAX_SYMLINK_TARGET_BYTES = 4096
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
                # Encrypted members and unknown compression formats cannot be
                # fully CRC-audited without special credentials or codecs.
                if entry.flag_bits & 0x1:
                    raise BundleError('Encrypted delivery archive member is not auditable')
                if entry.compress_type not in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED,
                                               zipfile.ZIP_BZIP2, zipfile.ZIP_LZMA):
                    raise BundleError('Unsupported delivery archive compression')
                expanded += entry.file_size
                if expanded > MAX_EXPANDED:
                    raise BundleError('Expanded archive exceeds safe limit')
                member_mode = (entry.external_attr >> 16) & 0o170000
                # A symbolic link (or empty directory) named like a platform
                # identity file cannot establish that the APK/app is present.
                identity_file = (name == 'AndroidManifest.xml' if platform == 'android-debug'
                                 else bool(re.search(r'(^|/)[^/]+\.app/Info\.plist$', name)))
                if identity_file and (entry.is_dir() or
                                      member_mode not in (0, stat.S_IFREG) or
                                      entry.file_size == 0):
                    raise BundleError('Platform identity must be a nonempty regular ZIP member')
                if member_mode == stat.S_IFLNK:
                    # Frameworks in simulator builds can contain relative
                    # symlinks. Bound the uncompressed read before extraction.
                    if entry.file_size < 1 or entry.file_size > MAX_SYMLINK_TARGET_BYTES:
                        raise BundleError('Archive symlink target exceeds safe length')
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
    except (zipfile.BadZipFile, zipfile.LargeZipFile, UnicodeDecodeError, EOFError,
            RuntimeError, NotImplementedError) as exc:
        raise BundleError('Corrupt, encrypted or unsupported ZIP/APK archive') from exc


def secure_artifact_audit(path, platform):
    """Hash and fully validate a private ZIP from one non-following descriptor.

    Re-opening by path between checksum and CRC could inspect two different
    artifacts. Reject replaced paths or changed inode metadata afterwards.
    """
    if not hasattr(os, 'O_NOFOLLOW') or not hasattr(os, 'O_NONBLOCK'):
        raise BundleError('Secure artifact inspection unavailable')
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError as exc:
        raise BundleError('Cannot securely open delivery artifact') from exc
    with os.fdopen(descriptor, 'rb') as input_file:
        before = os.fstat(input_file.fileno())
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or
                before.st_size < 1 or before.st_size > MAX_EXPANDED or
                (os.name != 'nt' and before.st_mode & 0o077)):
            raise BundleError('Delivery artifact permissions, size or type invalid')
        digest = hashlib.sha256()
        length = 0
        while block := input_file.read(1024 * 1024):
            digest.update(block)
            length += len(block)
            if length > MAX_EXPANDED:
                raise BundleError('Delivery artifact exceeds audit size limit')
        if length != before.st_size:
            raise BundleError('Delivery artifact size changed during hash')
        input_file.seek(0)
        archive_check(input_file, platform)
        after = os.fstat(input_file.fileno())
        try:
            current = os.lstat(path)
        except OSError as exc:
            raise BundleError('Delivery artifact path disappeared') from exc
        fields = ('st_dev', 'st_ino', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
        if (not stat.S_ISREG(current.st_mode) or current.st_nlink != 1 or
                (os.name != 'nt' and current.st_mode & 0o077) or
                any(getattr(before, field) != getattr(after, field) or
                    getattr(before, field) != getattr(current, field)
                    for field in fields)):
            raise BundleError('Delivery artifact changed during hash or CRC inspection')
        return digest.hexdigest(), length, tuple(getattr(before, field) for field in fields)


def assert_artifact_identity_stable(path, identity):
    """Recheck one previously audited artifact at the end of bundle review.

    Every platform must still refer to the same inode verified with SHA and
    CRC, even if another platform was checked afterward.
    """
    try:
        current = os.lstat(path)
    except OSError as exc:
        raise BundleError('Previously checked delivery artifact is missing') from exc
    fields = ('st_dev', 'st_ino', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    if (not stat.S_ISREG(current.st_mode) or current.st_nlink != 1 or
            (os.name != 'nt' and current.st_mode & 0o077) or
            tuple(getattr(current, field) for field in fields) != identity):
        raise BundleError('Previously checked delivery artifact changed during bundle review')


def verify_workflow_reference(workflow, source_sha):
    """Fail closed on bundled provenance claims without implying signed GitHub attestation."""
    expected = {'provider', 'repository', 'workflow_name', 'run_id', 'head_sha',
                'android', 'ios', 'snapshot_authenticated'}
    identified = {'workflow_id', 'workflow_path'}
    # Earlier developer bundles carry only the workflow name; they remain
    # verifiable but newer imports must bind the reviewed workflow identity.
    if not isinstance(workflow, dict) or set(workflow) not in (expected, expected | identified):
        raise BundleError('Invalid optional workflow evidence schema')
    expected_workflows = {
        'Hasone customer development builds': (410027022, '.github/workflows/hasone-customer.yml'),
        'Native client validation': (397853920, '.github/workflows/mobile.yml'),
    }
    workflow_identity = expected_workflows.get(workflow.get('workflow_name'))
    if workflow['provider'] != 'github_actions' or workflow['repository'] != 'creabizsalonspa-create/EV' or \
            workflow_identity is None or \
            type(workflow['run_id']) is not int or workflow['run_id'] <= 0 or \
            workflow['head_sha'] != source_sha or workflow['snapshot_authenticated'] is not False:
        raise BundleError('Unverifiable workflow provenance or false authentication claim')
    if workflow['workflow_name'] == 'Native client validation' and not identified <= set(workflow):
        raise BundleError('Shared native developer build must include exact workflow ID and source path')
    if identified <= set(workflow) and (
            type(workflow['workflow_id']) is not int or
            workflow['workflow_id'] != workflow_identity[0] or
            workflow['workflow_path'] != workflow_identity[1]):
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


def validate_created_at_utc(value):
    # This is the local *packaging* timestamp, not GitHub's build time and
    # not independently attested. Still reject nonsensical or future claims.
    if not isinstance(value, str) or not re.fullmatch(
            r'[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z', value):
        raise BundleError('Bundle creation time must be an exact UTC ISO timestamp')
    try:
        parsed = dt.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=dt.timezone.utc)
    except ValueError as exc:
        raise BundleError('Invalid bundle creation date or clock time') from exc
    if parsed > dt.datetime.now(dt.timezone.utc) + dt.timedelta(minutes=5):
        raise BundleError('Bundle claims to be created in the future')


def _strict_json_pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise BundleError('Duplicate delivery manifest JSON member')
        result[key] = value
    return result


def _reject_json_constant(_value):
    raise BundleError('Nonstandard numeric constant in delivery manifest')


def secure_manifest_snapshot(folder):
    """Read one owner-only manifest descriptor, without following symlinks.

    Returns parsed JSON and a byte/file-identity snapshot. Repeating this after
    all artifact checks detects replacements during verification.
    """
    root = Path(folder)
    name = root / 'manifest.json'
    if not hasattr(os, 'O_NOFOLLOW') or not hasattr(os, 'O_NONBLOCK'):
        raise BundleError('Secure manifest inspection unavailable')
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError as exc:
        raise BundleError('Delivery manifest cannot be safely opened') from exc
    try:
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or
                not 2 <= before.st_size <= 64 * 1024 or
                (os.name != 'nt' and before.st_mode & 0o077)):
            raise BundleError('Delivery manifest permissions, type or size invalid')
        data = bytearray()
        while len(data) < before.st_size + 1:
            part = os.read(fd, min(16 * 1024, before.st_size + 1 - len(data)))
            if not part:
                break
            data.extend(part)
        after = os.fstat(fd)
        if len(data) != before.st_size or any(
                getattr(before, field) != getattr(after, field) for field in (
                    'st_dev', 'st_ino', 'st_size', 'st_mtime_ns', 'st_ctime_ns')):
            raise BundleError('Delivery manifest changed during inspection')
        try:
            actual = os.lstat(name)
        except OSError as exc:
            raise BundleError('Delivery manifest path disappeared') from exc
        if not stat.S_ISREG(actual.st_mode) or actual.st_nlink != 1 or any(
                getattr(actual, field) != getattr(before, field) for field in (
                    'st_dev', 'st_ino', 'st_size')):
            raise BundleError('Delivery manifest path changed after opening')
        digest = hashlib.sha256(data).hexdigest()
        try:
            manifest = json.loads(data.decode('utf-8'),
                                  object_pairs_hook=_strict_json_pairs,
                                  parse_constant=_reject_json_constant)
        except (ValueError, UnicodeError) as exc:
            raise BundleError('Invalid delivery manifest JSON') from exc
        return manifest, (digest, before.st_dev, before.st_ino, before.st_size,
                          before.st_mtime_ns, before.st_ctime_ns)
    finally:
        os.close(fd)


def verify(folder, expected_source=None):
    root = Path(folder)
    if root.is_symlink() or not root.is_dir():
        raise BundleError('Bundle must be a directory, not a symlink')
    # A valid digest does not make publicly readable handoff data private.
    # Every exported bundle is created owner-only and should remain so.
    if os.name != 'nt' and root.stat().st_mode & 0o077:
        raise BundleError('Delivery bundle directory must be owner-only')
    manifest, baseline = secure_manifest_snapshot(root)
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
    validate_created_at_utc(manifest['created_at_utc'])
    if not isinstance(manifest['artifacts'], list) or len(manifest['artifacts']) != 2:
        raise BundleError('Bundle requires one Android and one iOS simulator artifact')
    seen = set()
    audited_identities = {}
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
        actual_digest, actual_size, identity = secure_artifact_audit(root / name, platform)
        if actual_digest != item['sha256'] or actual_size != item['size_bytes']:
            raise BundleError('Artifact bytes differ from manifest')
        audited_identities[name] = identity
    if {p.name for p in root.iterdir()} != {'manifest.json', 'android-development.apk', 'ios-simulator.zip'}:
        raise BundleError('Unexpected files in delivery bundle')
    manifest_after, final_identity = secure_manifest_snapshot(root)
    if final_identity != baseline or manifest_after != manifest:
        raise BundleError('Delivery manifest changed while verifying bundle')
    for name, identity in audited_identities.items():
        assert_artifact_identity_stable(root / name, identity)
    return manifest


def secure_copy_developer_source(source, target, platform):
    """Validate and copy the same source inode; never follow a replaced symlink.

    This does not authenticate the source's GitHub provenance. It prevents a
    local path switch between preflight and the private output write.
    """
    if not hasattr(os, 'O_NOFOLLOW') or not hasattr(os, 'O_NONBLOCK'):
        raise BundleError('Secure developer source copying unavailable')
    try:
        source_fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError as exc:
        raise BundleError('Developer source file cannot be opened securely') from exc
    with os.fdopen(source_fd, 'rb') as input_file:
        before = os.fstat(input_file.fileno())
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or
                not 1 <= before.st_size <= MAX_EXPANDED):
            raise BundleError('Developer source must be a bounded regular single-link file')
        # The CRC and copied bytes must originate from this same immutable fd.
        archive_check(input_file, platform)
        input_file.seek(0)
        # Create mode 0600 on the initial syscall, rather than exposing a
        # default-mode output until a later chmod (even inside a 0700 folder).
        output_fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                            os.O_NOFOLLOW, 0o600)
        with os.fdopen(output_fd, 'wb') as output_file:
            shutil.copyfileobj(input_file, output_file, length=1024 * 1024)
            output_file.flush()
            os.fsync(output_file.fileno())
        after = os.fstat(input_file.fileno())
        try:
            current = os.lstat(source)
        except OSError as exc:
            raise BundleError('Developer source path removed during copy') from exc
        fields = ('st_dev', 'st_ino', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
        if (not stat.S_ISREG(current.st_mode) or current.st_nlink != 1 or
                any(getattr(before, field) != getattr(after, field) or
                    getattr(before, field) != getattr(current, field)
                    for field in fields)):
            raise BundleError('Developer source file changed during copy')

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
            secure_copy_developer_source(original, dest, platform)
            digest, size = checksum(dest)
            artifacts.append({'platform': platform, 'path': name, 'sha256': digest, 'size_bytes': size})
        manifest = {'schema': SCHEMA, 'source_sha': source_sha,
                    'created_at_utc': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00', 'Z'),
                    'acceptance': {'kind': 'development_demo_only', 'production_accepted': False, 'signed_for_store': False},
                    'artifacts': artifacts}
        if workflow_evidence is not None:
            verify_workflow_reference(workflow_evidence, source_sha)
            manifest['workflow_evidence'] = workflow_evidence
        # Manifest must also be owner-only from the first write, not chmodded
        # after potentially exposing an incomplete security/provenance record.
        manifest_fd = os.open(target / 'manifest.json',
                              os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                              os.O_NOFOLLOW, 0o600)
        with os.fdopen(manifest_fd, 'w', encoding='utf-8') as out:
            json.dump(manifest, out, indent=2, sort_keys=True)
            out.write('\n')
            out.flush()
            os.fsync(out.fileno())
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
