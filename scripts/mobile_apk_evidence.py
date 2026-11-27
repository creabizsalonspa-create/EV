"""Exact-source evidence helpers for the existing compiled APK auditor."""
from contextlib import contextmanager
import hashlib
from pathlib import Path
import re
import shutil
import tempfile


class EvidenceError(ValueError):
    pass


def digest(path):
    with Path(path).open('rb') as stream:
        sha = hashlib.sha256()
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            sha.update(block)
        return sha.hexdigest()


@contextmanager
def verified_snapshot(path):
    path = Path(path)
    before = digest(path)
    with tempfile.TemporaryDirectory(prefix='ev-apk-audit-') as directory:
        snapshot = Path(directory) / 'artifact.apk'
        shutil.copyfile(path, snapshot)
        if digest(snapshot) != before:
            raise EvidenceError('APK changed during snapshot')
        yield snapshot, before
        if digest(snapshot) != before or digest(path) != before:
            raise EvidenceError('APK changed during verification')


def verify_backup_resource_refs(manifest, resources, package):
    for attribute, name in (('fullBackupContent', 'ev_backup_rules'),
                            ('dataExtractionRules', 'ev_data_extraction_rules')):
        refs = re.findall(r'android:' + attribute + r'\([^)]*\)=@0x([0-9a-fA-F]{8})\b', manifest)
        if len(refs) != 1:
            raise EvidenceError('Missing or ambiguous compiled ' + attribute + ' reference')
        matches = re.findall(r'(?mi)^\s*spec resource 0x' + refs[0] + r'\s+([^\s]+)', resources)
        expected = package + ':xml/' + name
        if matches != [expected + ':'] and matches != [expected]:
            raise EvidenceError('Compiled ' + attribute + ' references unexpected resource')


def verify_single_signer(output, expected):
    rows = re.findall(r'(?m)^Signer #(\d+) certificate SHA-256 digest:\s*([0-9a-fA-F]{64})\s*$', output)
    if len(rows) != 1 or rows[0][0] != '1' or rows[0][1].lower() != expected.lower():
        raise EvidenceError('Release APK signing certificate does not match unique approved signer')
    counts = re.findall(r'(?m)^Number of signers:\s*(\d+)\s*$', output)
    if counts and counts != ['1']:
        raise EvidenceError('Release APK must have exactly one signer')
    return rows[0][1].lower()
