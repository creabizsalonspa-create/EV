"""Verify the exact exported IPA's Apple code signature on a macOS release runner.

This is an artifact-side gate, not a signing, provisioning, device or store
acceptance claim. Requires an independently approved identity and leaf pin.
"""
import argparse
import hashlib
import json
from pathlib import Path
import plistlib
import re
import shutil
import subprocess
import sys
import tempfile
import unicodedata
import zipfile

from audit_ipa_package import AuditError, audit, digest, require
from ipa_device_architecture import device_architecture

MAX_ARCHIVE = 2 * 1024 ** 3
MAX_EXPANDED = 3 * 1024 ** 3
MAX_ENTRIES = 100000
CODESIGN = '/usr/bin/codesign'


def snapshot(source, target):
    total = 0
    with source.open('rb') as src, target.open('xb') as dst:
        for chunk in iter(lambda: src.read(1024 * 1024), b''):
            total += len(chunk)
            require(total <= MAX_ARCHIVE, 'IPA exceeds release audit limit')
            dst.write(chunk)
    require(total > 0, 'Empty IPA')


def extract_app(path, root):
    """Extract only the already structurally audited flat application bundle."""
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        require(len(entries) <= MAX_ENTRIES, 'Too many archive entries')
        require(sum(e.file_size for e in entries) <= MAX_EXPANDED,
                'Expanded IPA exceeds release audit limit')
        aliases = {}
        apps = set()
        for e in entries:
            parts = e.filename.rstrip('/').split('/')
            for i in range(1, len(parts) + 1):
                original = '/'.join(parts[:i])
                key = unicodedata.normalize('NFD', original).casefold()
                require(aliases.setdefault(key, original) == original,
                        'Case or Unicode-colliding archive path')
            if len(parts) >= 2 and parts[0] == 'Payload' and parts[1].endswith('.app'):
                apps.add('/'.join(parts[:2]))
        require(len(apps) == 1, 'Expected exactly one application bundle')
        app = apps.pop()
        for e in entries:
            name = e.filename.rstrip('/')
            if name != app and not name.startswith(app + '/'):
                continue
            dest = root / name
            require(dest.resolve().is_relative_to(root.resolve()), 'Unsafe bundle path')
            if e.is_dir():
                dest.mkdir(parents=True, exist_ok=True)
                continue
            dest.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(e) as src, dest.open('xb') as dst:
                shutil.copyfileobj(src, dst, 1024 * 1024)
            dest.chmod(0o700 if (e.external_attr >> 16) & 0o111 else 0o600)
        return root / app


def run_codesign(args):
    try:
        completed = subprocess.run([CODESIGN, *args], capture_output=True,
                                   timeout=180, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise AuditError('Apple codesign unavailable or timed out') from exc
    require(completed.returncode == 0, 'Apple codesign rejected the application')
    return completed.stdout


def verify_signature(source, bundle_id, version, build, team_id, leaf_sha256):
    require(sys.platform == 'darwin', 'Signature verification requires macOS')
    require(re.fullmatch(r'[A-Z0-9]{10}', team_id), 'Expected approved Apple team ID')
    require(re.fullmatch(r'[a-fA-F0-9]{64}', leaf_sha256),
            'Expected independently approved signing certificate SHA-256')
    # audit() validates the bundle identifier before it is used in a requirement.
    source = Path(source)
    with tempfile.TemporaryDirectory(prefix='ev-ipa-signature-') as temp:
        root = Path(temp)
        frozen = root / 'artifact.ipa'
        snapshot(source, frozen)
        evidence = audit(frozen, bundle_id, version, build)
        app = extract_app(frozen, root / 'bundle')
        architecture = device_architecture(app)
        requirement = ('anchor apple generic and identifier "' + bundle_id +
                       '" and certificate leaf[subject.OU] = "' + team_id + '"')
        run_codesign(['--verify', '--deep', '--strict', '-R=' + requirement, str(app)])
        cert_prefix = root / 'certificate-'
        run_codesign(['--display', '--extract-certificates=' + str(cert_prefix), str(app)])
        leaf = root / 'certificate-0'
        require(leaf.is_file() and 0 < leaf.stat().st_size <= 1024 * 1024,
                'Missing signing certificate')
        actual_leaf = hashlib.sha256(leaf.read_bytes()).hexdigest()
        require(actual_leaf == leaf_sha256.lower(), 'Signing certificate pin mismatch')
        try:
            entitlements = plistlib.loads(
                run_codesign(['--display', '--entitlements', ':-', str(app)]))
        except (ValueError, TypeError, plistlib.InvalidFileException) as exc:
            raise AuditError('Invalid signed application entitlements') from exc
        require(isinstance(entitlements, dict), 'Invalid signed application entitlements')
        require(entitlements.get('application-identifier') == team_id + '.' + bundle_id,
                'Signed application identifier mismatch')
        require(entitlements.get('com.apple.developer.team-identifier') == team_id,
                'Signed team identifier mismatch')
        require(entitlements.get('get-task-allow', False) is False,
                'Debug entitlement is not permitted')
        require(digest(frozen) == evidence['sha256'], 'Frozen artifact changed')
        require(digest(source) == evidence['sha256'], 'Original artifact changed')
        return {**evidence, **architecture, 'signature_verified': True, 'team_id': team_id,
                'signing_certificate_sha256': actual_leaf,
                'debug_entitlement_disabled': True,
                'provisioning_verified': False, 'store_accepted': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('ipa', type=Path)
    parser.add_argument('--bundle-id', required=True)
    parser.add_argument('--version', required=True)
    parser.add_argument('--build', required=True)
    parser.add_argument('--team-id', required=True)
    parser.add_argument('--leaf-sha256', required=True)
    args = parser.parse_args()
    try:
        result = verify_signature(args.ipa, args.bundle_id, args.version, args.build,
                                  args.team_id, args.leaf_sha256)
    except AuditError as exc:
        print('IPA SIGNATURE AUDIT FAILED: ' + str(exc), file=sys.stderr)
        return 1
    except (OSError, ValueError, TypeError, RuntimeError, zipfile.BadZipFile):
        print('IPA SIGNATURE AUDIT FAILED: invalid or unreadable artifact', file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
