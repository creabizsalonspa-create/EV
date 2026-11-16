#!/usr/bin/env python3
"""Independent fail-closed observer of an approved Laravel watchdog CLI; no external delivery."""

import argparse
import json
import os
from pathlib import Path
import subprocess
import sys

MAX_OUTPUT_BYTES = 4096
ALLOWED_SIGNALS = frozenset({
    'monitoring_disabled', 'heartbeat_store_unavailable',
    'queue_worker_stale', 'queue_worker_unknown',
    'scheduler_stale', 'scheduler_unknown',
    'queue_metrics_unknown', 'queue_failed_jobs', 'queue_backlog',
    'queue_reserved_stale', 'email_handoff_metrics_unknown',
    'email_handoff_needs_review', 'email_queue_overdue',
    'incident_store_unavailable', 'incident_scanner_stale',
    'incident_scanner_unknown', 'recorded_incidents_open',
    'watchdog_unavailable',
})
REQUIRED = frozenset({
    'status', 'signals', 'read_only', 'automatic_retry',
    'external_alert_delivery', 'all_workers_verified',
    'charger_connectivity_verified', 'production_readiness_verified',
})


def _duplicates_forbidden(pairs):
    record = {}
    for key, value in pairs:
        if key in record:
            raise ValueError('duplicate JSON field')
        record[key] = value
    return record


def _reject_nonfinite(_value):
    raise ValueError('invalid JSON number')


def _validated(raw, exit_code):
    if not raw or len(raw) > MAX_OUTPUT_BYTES:
        raise ValueError('watchdog output size invalid')
    record = json.loads(raw.decode('utf-8'), object_pairs_hook=_duplicates_forbidden,
                        parse_constant=_reject_nonfinite)
    if type(record) is not dict or set(record) != REQUIRED:
        raise ValueError('watchdog schema invalid')
    if (record['read_only'] is not True or record['automatic_retry'] is not False
            or record['external_alert_delivery'] is not False
            or record['all_workers_verified'] is not False
            or record['charger_connectivity_verified'] is not False
            or record['production_readiness_verified'] is not False):
        raise ValueError('unapproved readiness or side-effect claim')
    signals = record['signals']
    if (type(signals) is not list or len(signals) > len(ALLOWED_SIGNALS)
            or any(type(signal) is not str or signal not in ALLOWED_SIGNALS
                   for signal in signals) or len(set(signals)) != len(signals)):
        raise ValueError('invalid watchdog signals')
    state = record['status']
    if ((state == 'observed_normal' and exit_code == 0 and not signals)
            or (state == 'needs_attention' and exit_code == 1 and signals)):
        return record
    raise ValueError('watchdog status and process exit disagree')


def _fallback():
    return {
        'status': 'needs_attention', 'signals': ['watchdog_probe_unavailable'],
        'read_only': True, 'automatic_retry': False,
        'external_alert_delivery': False, 'all_workers_verified': False,
        'charger_connectivity_verified': False, 'production_readiness_verified': False,
    }


def check(runtime, artisan, timeout):
    runtime = Path(runtime)
    artisan = Path(artisan)
    if (not runtime.is_absolute() or not artisan.is_absolute()
            or not runtime.is_file() or not os.access(runtime, os.X_OK)
            or not artisan.is_file() or artisan.name != 'artisan'):
        return _fallback()
    try:
        result = subprocess.run(
            [str(runtime), str(artisan), 'operations:watchdog-check'],
            cwd=str(artisan.parent), stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, timeout=timeout, check=False,
        )
        return _validated(result.stdout, result.returncode)
    except (OSError, subprocess.TimeoutExpired, ValueError, UnicodeError):
        # The original stdout/stderr can contain private stack traces or secrets.
        # Never print it, infer success, restart jobs or replay operations.
        return _fallback()


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runtime', required=True, help='Absolute approved PHP CLI path')
    parser.add_argument('--artisan', required=True, help='Absolute application artisan path')
    parser.add_argument('--timeout', type=int, default=20, help='One-shot timeout: 1-30 seconds')
    args = parser.parse_args(argv)
    result = check(args.runtime, args.artisan, args.timeout) if 1 <= args.timeout <= 30 else _fallback()
    print(json.dumps(result, separators=(',', ':'), allow_nan=False))
    return 0 if result['status'] == 'observed_normal' else 1


if __name__ == '__main__':
    sys.exit(main())
