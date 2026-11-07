#!/usr/bin/env python3
"""Gate independent same-run synthetic recovery evidence, never production acceptance."""
import argparse
import json
import os
from pathlib import Path
import re
import stat
import sys

class EvidenceError(Exception):
    pass

def require(condition, error):
    if not condition:
        raise EvidenceError(error)

def read_proof(path):
    # Read and validate through the SAME no-follow descriptor. A prior
    # is_symlink()/stat() check is vulnerable to path replacement before read.
    require(hasattr(os, 'O_NOFOLLOW'), 'Secure evidence reads unavailable')
    flags = os.O_RDONLY | getattr(os, 'O_CLOEXEC', 0) | os.O_NOFOLLOW
    try:
        fd = os.open(path, flags)
    except OSError:
        raise EvidenceError('Recovery evidence cannot be opened safely') from None
    with os.fdopen(fd, 'rb') as proof:
        info = os.fstat(proof.fileno())
        require(stat.S_ISREG(info.st_mode) and 0 < info.st_size <= 65536,
                'Recovery evidence must be a bounded regular file')
        payload = proof.read(65537)
        require(len(payload) == info.st_size and len(payload) <= 65536,
                'Recovery evidence changed size while reading')
    try:
        result = json.loads(payload.decode('utf-8'))
    except (ValueError, UnicodeError):
        raise EvidenceError('Recovery evidence must be valid JSON') from None
    require(type(result) is dict, 'Recovery evidence must be a JSON object')
    return result

def valid_hash(value, size):
    return type(value) is str and re.fullmatch('[a-f0-9]{%s}' % size, value) is not None

def inspect_proofs(business, csms, sha, run_id, attempt):
    require(re.fullmatch(r'[a-f0-9]{40}', sha) is not None,
            'Expected Git source SHA invalid')
    require(re.fullmatch(r'[0-9]+', str(run_id)) is not None and
            re.fullmatch(r'[0-9]+', str(attempt)) is not None,
            'Expected workflow provenance invalid')
    provenance = {
        'source_sha': sha, 'workflow_run_id': str(run_id),
        'workflow_run_attempt': str(attempt),
    }
    for label, record in [('business', business), ('csms', csms)]:
        require(record.get('status') == 'PASS', f'{label} recovery did not PASS')
        for field, expected in provenance.items():
            require(record.get(field) == expected,
                    f'{label} evidence run mismatch: {field}')
    for field in ('dump_sha256', 'synthetic_private_archive_sha256'):
        require(valid_hash(business.get(field), 64), f'Missing business archive hash: {field}')
    for field in ('migration_history_md5', 'synthetic_data_md5'):
        require(valid_hash(business.get(field), 32), f'Missing business fingerprint: {field}')
    require(type(business.get('laravel_migration_rows')) is int and
            business['laravel_migration_rows'] > 0,
            'No restored Laravel migration history')
    require(business.get('classification') == 'disposable staging software only' and
            business.get('restored_http_catalog') == '200 and empty' and
            business.get('external_acceptance') == 'not tested' and
            business.get('laravel_encrypted_record_restored') is True and
            business.get('wrong_laravel_key_rejected') is True and
            business.get('synthetic_private_file_restored') is True and
            business.get('post_snapshot_write_excluded') is True and
            business.get('archive_tamper_rejected') is True and
            business.get('private_archive_tamper_rejected') is True and
            business.get('queue_worker_started') is True and
            business.get('scheduler_bootstrapped') is True,
            'Business recovery readiness boundary invalid')
    require(valid_hash(csms.get('database_archive_sha256'), 64) and
            valid_hash(csms.get('synthetic_geospatial_data_md5'), 32),
            'PostGIS archive/data fingerprint invalid')
    require(csms.get('classification') ==
            'disposable synthetic PostGIS recovery, not CitrineOS acceptance',
            'PostGIS acceptance classification invalid')
    require(type(csms.get('recovered_rows')) is int and csms['recovered_rows'] == 2 and
            type(csms.get('srid')) is int and csms['srid'] == 4326 and
            csms.get('post_snapshot_write_excluded') is True and
            csms.get('real_citrineos_schema_restored') is False and
            csms.get('rabbitmq_broker_restored') is False and
            csms.get('physical_charger_or_money') is False and
            csms.get('archive_tamper_rejected') is True,
            'PostGIS recovery or external acceptance boundary invalid')
    return {
        'status': 'PASS',
        'classification': 'two independent synthetic restores; no cross-service consistent snapshot',
        **provenance,
        'business_dump_sha256': business['dump_sha256'],
        'business_private_archive_sha256': business['synthetic_private_archive_sha256'],
        'csms_dump_sha256': csms['database_archive_sha256'],
        'laravel_migrations_restored': business['laravel_migration_rows'],
        'synthetic_postgis_rows_restored': csms['recovered_rows'],
        'cross_service_consistent_snapshot_proven': False,
        'real_merchant_charge_or_hardware_tested': False,
        'production_ready': False,
    }

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--business', required=True, type=Path)
    parser.add_argument('--csms', required=True, type=Path)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--run-id', required=True)
    parser.add_argument('--attempt', required=True)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args(argv)
    args.output.unlink(missing_ok=True)
    try:
        result = inspect_proofs(read_proof(args.business), read_proof(args.csms),
                                args.sha, args.run_id, args.attempt)
    except (OSError, EvidenceError) as exc:
        print(f'FAIL: {exc}', file=sys.stderr)
        return 1
    args.output.parent.mkdir(parents=True, exist_ok=True)
    temp = args.output.with_suffix('.recovery-tmp')
    temp.unlink(missing_ok=True)
    fd = os.open(temp, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w') as output:
        json.dump(result, output, sort_keys=True, indent=2)
        output.write('\n')
    os.replace(temp, args.output)
    print('PASS: same-run dual synthetic recovery evidence; NOT production acceptance')
    return 0

if __name__ == '__main__':
    sys.exit(main())
