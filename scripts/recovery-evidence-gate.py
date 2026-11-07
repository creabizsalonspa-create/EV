#!/usr/bin/env python3
"""Gate independent same-run synthetic recovery evidence, never production acceptance."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sys

# Resolve the sibling publisher from this reviewed script path. This works
# both when called by CI and when audit tests import this gate from a file.
_writer_spec = importlib.util.spec_from_file_location(
    'agent3_atomic_recovery_writer',
    Path(__file__).with_name('recovery_proof_writer.py'))
_writer = importlib.util.module_from_spec(_writer_spec)
_writer_spec.loader.exec_module(_writer)
publish = _writer.publish

class EvidenceError(Exception):
    pass

def require(condition, error):
    if not condition:
        raise EvidenceError(error)

def read_strict_json(payload):
    def unique(pairs):
        data = {}
        for key, value in pairs:
            require(key not in data, 'Duplicate recovery evidence JSON key')
            data[key] = value
        return data

    def nonfinite(value):
        raise EvidenceError('Non-standard JSON numeric constant in recovery evidence')

    return json.loads(payload.decode('utf-8'), object_pairs_hook=unique,
                      parse_constant=nonfinite)


def read_proof(path):
    # Read and validate through the SAME no-follow descriptor. A prior
    # is_symlink()/stat() check is vulnerable to path replacement before read.
    require(hasattr(os, 'O_NOFOLLOW') and hasattr(os, 'O_NONBLOCK'),
            'Secure nonblocking evidence reads unavailable')
    # FIFO paths must not be able to block before the regular-file fstat.
    flags = (os.O_RDONLY | getattr(os, 'O_CLOEXEC', 0) |
             os.O_NOFOLLOW | os.O_NONBLOCK)
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
        result = read_strict_json(payload)
    except (ValueError, UnicodeError):
        raise EvidenceError('Recovery evidence must be valid JSON') from None
    require(type(result) is dict, 'Recovery evidence must be a JSON object')
    return result

def valid_hash(value, size):
    return type(value) is str and re.fullmatch('[a-f0-9]{%s}' % size, value) is not None

def timing_metrics(record, label):
    # Separate synthetic snapshots cannot measure production RPO or RTO.
    # Verify the five clocks are real integers in chronological order.
    fields = ('drill_started_epoch', 'snapshot_started_epoch',
              'snapshot_completed_epoch', 'restore_started_epoch',
              'drill_completed_epoch')
    ticks = [record.get(field) for field in fields]
    require(all(type(t) is int and 1600000000 <= t <= 4102444800 for t in ticks),
            f'{label} synthetic recovery clock evidence invalid')
    require(all(a <= b for a, b in zip(ticks, ticks[1:])) and
            ticks[-1] - ticks[0] <= 86400,
            f'{label} synthetic recovery clock ordering or duration invalid')
    return {
        f'{label}_synthetic_snapshot_age_seconds': ticks[-1] - ticks[1],
        f'{label}_synthetic_restore_duration_seconds': ticks[-1] - ticks[3],
        f'{label}_synthetic_drill_duration_seconds': ticks[-1] - ticks[0],
    }


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
            business.get('post_snapshot_private_write_excluded') is True and
            business.get('archive_tamper_rejected') is True and
            business.get('run_local_manifest_hmac_verified') is True and
            business.get('single_transaction_restore') is True and
            business.get('isolated_runtime_verified') is True and
            business.get('private_archive_tamper_rejected') is True and
            business.get('queue_worker_started') is True and
            business.get('durable_queue_job_restored_and_executed') is True and
            business.get('post_snapshot_queue_write_excluded') is True and
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
            csms.get('archive_tamper_rejected') is True and
            csms.get('run_local_manifest_hmac_verified') is True and
            csms.get('single_transaction_restore') is True and
            csms.get('isolated_runtime_verified') is True,
            'PostGIS recovery or external acceptance boundary invalid')
    business_timing = timing_metrics(business, 'business')
    csms_timing = timing_metrics(csms, 'csms')
    return {
        'status': 'PASS',
        'classification': 'two independent synthetic restores; no cross-service consistent snapshot',
        **provenance,
        'business_dump_sha256': business['dump_sha256'],
        'business_private_archive_sha256': business['synthetic_private_archive_sha256'],
        'csms_dump_sha256': csms['database_archive_sha256'],
        'laravel_migrations_restored': business['laravel_migration_rows'],
        'synthetic_postgis_rows_restored': csms['recovered_rows'],
        **business_timing,
        **csms_timing,
        'production_rpo_rto_proven': False,
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
    publish(args.output, result)
    print('PASS: same-run dual synthetic recovery evidence; NOT production acceptance')
    return 0

if __name__ == '__main__':
    sys.exit(main())
