#!/usr/bin/env python3
"""Import verified GitHub Actions wrapper ZIPs into a development-only EV delivery bundle."""
import argparse
import hashlib
import os
from pathlib import Path
import re
import shutil
import sys
import tarfile
import tempfile
import zipfile

from delivery_bundle import BundleError, create, verify, checksum, MAX_EXPANDED, MAX_ENTRIES

DIGEST = re.compile(r'^[0-9a-f]{64}$')


def check_wrapper(path, expected_sha, member):
    if not isinstance(expected_sha, str) or not DIGEST.fullmatch(expected_sha):
        raise BundleError('Published artifact digest must be SHA-256 hex')
    path = Path(path)
    if path.is_symlink() or not path.is_file():
        raise BundleError('Action artifact must be a regular, non-symlink ZIP')
    real_sha, size = checksum(path)
    if not size or real_sha != expected_sha:
        raise BundleError('Action artifact does not match published SHA-256')
    try:
        with zipfile.ZipFile(path) as container:
            entries = container.infolist()
            if len(entries) != 1 or entries[0].filename != member or entries[0].is_dir():
                raise BundleError('Unrecognized action artifact wrapper contents')
            if entries[0].file_size > MAX_EXPANDED:
                raise BundleError('Action artifact member exceeds safe size limit')
    except (zipfile.BadZipFile, zipfile.LargeZipFile) as exc:
        raise BundleError('Corrupt action artifact wrapper') from exc
    return path


def valid_tar_name(name):
    parts = name.rstrip('/').split('/')
    return bool(name and not name.startswith('/') and '\\' not in name and '\x00' not in name and
                all(part not in ('', '.', '..') for part in parts) and ':' not in parts[0])


def convert_ios_tar_to_zip(ios_wrapper, destination):
    seen = set()
    expanded = 0
    count = 0
    with zipfile.ZipFile(ios_wrapper) as outer:
        with outer.open('hasone-ios-simulator.tar.gz') as compressed:
            with tarfile.open(fileobj=compressed, mode='r|gz') as archive:
                with zipfile.ZipFile(destination, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=6, allowZip64=True) as output:
                    for item in archive:
                        count += 1
                        if count > MAX_ENTRIES or not valid_tar_name(item.name):
                            raise BundleError('Unsafe simulator TAR entry count or name')
                        if item.name in seen:
                            raise BundleError('Duplicate simulator TAR entry')
                        seen.add(item.name)
                        if item.isdir():
                            continue
                        if not item.isfile():
                            raise BundleError('Simulator TAR contains an unsafe link or special file')
                        expanded += item.size
                        if expanded > MAX_EXPANDED:
                            raise BundleError('Simulator TAR exceeds safe expanded size')
                        source = archive.extractfile(item)
                        if source is None:
                            raise BundleError('Simulator TAR member has no readable body')
                        with source, output.open(item.name, 'w', force_zip64=True) as target:
                            shutil.copyfileobj(source, target, length=1024 * 1024)
    os.chmod(destination, 0o600)
    if not seen:
        raise BundleError('Simulator TAR is empty')


def import_actions(output, source_sha, android_wrapper, android_digest, ios_wrapper, ios_digest, workflow_evidence=None):
    android_wrapper = check_wrapper(android_wrapper, android_digest, 'app-debug.apk')
    ios_wrapper = check_wrapper(ios_wrapper, ios_digest, 'hasone-ios-simulator.tar.gz')
    output = Path(output)
    if not output.parent.is_dir() or output.parent.is_symlink():
        raise BundleError('Output parent must already exist as a real directory')
    with tempfile.TemporaryDirectory(prefix='.ev-delivery-import-', dir=output.parent) as work:
        work = Path(work)
        apk = work / 'source-debug.apk'
        with zipfile.ZipFile(android_wrapper) as outer, outer.open('app-debug.apk') as source, open(apk, 'xb') as target:
            shutil.copyfileobj(source, target, length=1024 * 1024)
        os.chmod(apk, 0o600)
        ios = work / 'converted-ios-simulator.zip'
        convert_ios_tar_to_zip(ios_wrapper, ios)
        create(output, source_sha, apk, ios, workflow_evidence=workflow_evidence)
        return verify(output, source_sha)


def main(argv=None):
    parser = argparse.ArgumentParser(description='Offline GitHub Actions artifact importer; demo only, never store signed')
    parser.add_argument('--output', required=True)
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--android-wrapper', required=True)
    parser.add_argument('--android-digest', required=True)
    parser.add_argument('--ios-wrapper', required=True)
    parser.add_argument('--ios-digest', required=True)
    args = parser.parse_args(argv)
    try:
        import_actions(args.output, args.source_sha, args.android_wrapper, args.android_digest, args.ios_wrapper, args.ios_digest)
    except (BundleError, OSError, zipfile.BadZipFile, tarfile.TarError, RuntimeError, EOFError):
        print('FAIL: Actions artifact provenance, archive or delivery integrity invalid; details withheld.', file=sys.stderr)
        return 1
    print('PASS: GitHub artifact checksums and development-only Android/iOS bundle CRCs verified. Build signing, customer journeys and production acceptance remain separate.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
