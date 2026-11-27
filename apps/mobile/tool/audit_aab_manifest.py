"""Verify signed AAB structure and compiled base manifest using pinned bundletool."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

ANDROID = '{http://schemas.android.com/apk/res/android}'


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


def inspect_manifest(xml, package, version_code, maps_required=False):
    require(re.fullmatch(r'[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){2,}', package)
            and not package.lower().endswith(('.dev', '.debug', '.test')), 'Approved release package required')
    require(re.fullmatch(r'[1-9][0-9]*', version_code), 'Approved version code required')
    require(len(xml) <= 4 * 1024 * 1024 and '<!' not in xml, 'Unsafe manifest output')
    root = ET.fromstring(xml)
    require(root.tag == 'manifest' and root.get('package') == package, 'Compiled package mismatch')
    require(root.get(ANDROID + 'versionCode') == version_code, 'Compiled version code mismatch')
    apps = root.findall('application')
    sdks = root.findall('uses-sdk')
    require(len(apps) == 1 and len(sdks) == 1, 'Missing or ambiguous application/SDK')
    app = apps[0]
    target = sdks[0].get(ANDROID + 'targetSdkVersion', '')
    require(re.fullmatch(r'[1-9][0-9]*', target), 'Missing target SDK')
    for name in ('allowBackup', 'usesCleartextTraffic'):
        require(app.get(ANDROID + name) == 'false', 'Unsafe compiled ' + name)
    for name in ('debuggable', 'testOnly'):
        require(app.get(ANDROID + name, 'false') == 'false', 'Unsafe compiled ' + name)
    require(ANDROID + 'networkSecurityConfig' not in app.attrib, 'Release network override')
    for name in ('fullBackupContent', 'dataExtractionRules'):
        require(re.fullmatch(r'@(?:xml/[A-Za-z0-9_]+|0x[0-9a-fA-F]{8})', app.get(ANDROID + name, '')),
                'Missing compiled backup resource reference')
    if maps_required:
        keys = [m for m in app.findall('meta-data') if m.get(ANDROID + 'name') == 'com.google.android.geo.API_KEY']
        require(len(keys) == 1, 'Missing or ambiguous Maps key')
        value = keys[0].get(ANDROID + 'value', '').strip()
        require(value and not value.startswith(('@', '$', '{')), 'Missing compiled Maps key')
    return {'package': package, 'version_code': version_code, 'target_sdk': int(target),
            'base_manifest_verified': True, 'compiled_backup_exclusions_verified': False}


def run(command):
    try:
        return subprocess.run(command, check=True, capture_output=True, text=True, timeout=180).stdout
    except (OSError, subprocess.SubprocessError) as exc:
        raise AuditError('Android verification tool failed') from exc


def audit(bundle, jar, jar_sha, signer_sha, package, version_code, maps_required=False):
    require(re.fullmatch(r'[0-9a-fA-F]{64}', jar_sha) and re.fullmatch(r'[0-9a-fA-F]{64}', signer_sha),
            'Approved SHA-256 fingerprints required')
    bundle, jar = Path(bundle), Path(jar)
    require(bundle.is_file() and jar.is_file(), 'Missing bundle or bundletool')
    before = digest(bundle)
    require(digest(jar) == jar_sha.lower(), 'Bundletool fingerprint mismatch')
    signature_source = Path(__file__).with_name('AuditMobileBundleSignature.java')
    with tempfile.TemporaryDirectory(prefix='ev-aab-audit-') as directory:
        snapshot = Path(directory) / 'release.aab'
        tool = Path(directory) / 'bundletool.jar'
        shutil.copyfile(bundle, snapshot)
        shutil.copyfile(jar, tool)
        require(digest(snapshot) == before and digest(tool) == jar_sha.lower(), 'Snapshot integrity mismatch')
        sig = json.loads(run(['java', str(signature_source), str(snapshot), signer_sha.lower()]))
        require(isinstance(sig, dict) and sig.get('signature_verified') is True and sig.get('sha256') == before
                and sig.get('upload_signer_sha256') == signer_sha.lower(), 'Signature evidence mismatch')
        prefix = ['java', '-jar', str(tool)]
        run(prefix + ['validate', '--bundle=' + str(snapshot)])
        xml = run(prefix + ['dump', 'manifest', '--bundle=' + str(snapshot), '--module=base'])
        result = inspect_manifest(xml, package, version_code, maps_required)
        require(digest(snapshot) == before and digest(bundle) == before and digest(tool) == jar_sha.lower()
                and digest(jar) == jar_sha.lower(), 'Artifact or tool changed during verification')
    return {**result, 'sha256': before, 'upload_signer_sha256': signer_sha.lower(),
            'bundletool_sha256': jar_sha.lower(), 'signature_verified': True,
            'bundle_structure_verified': True, 'derived_apks_verified': False, 'store_accepted': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('bundle')
    for name in ('bundletool', 'bundletool-sha256', 'upload-sha256', 'package', 'version-code'):
        parser.add_argument('--' + name, required=True)
    parser.add_argument('--require-maps-key', action='store_true')
    args = parser.parse_args()
    try:
        result = audit(args.bundle, args.bundletool, args.bundletool_sha256, args.upload_sha256,
                       args.package, args.version_code, args.require_maps_key)
    except AuditError as exc:
        print('AAB AUDIT FAILED: ' + str(exc), file=sys.stderr)
        return 1
    except (OSError, ValueError, TypeError, ET.ParseError):
        print('AAB AUDIT FAILED: invalid or unreadable evidence', file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
