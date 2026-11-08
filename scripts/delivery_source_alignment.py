#!/usr/bin/env python3
"""Offline source-alignment gate for private staging review and developer builds.

Never infer production release readiness, binary authenticity or real hardware/
payment acceptance from unsigned local evidence.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys

from delivery_bundle import BundleError, verify as verify_mobile

SCHEMA = 'ev-private-development-source-alignment-v1'
STAGE_SCHEMA = 'ev-private-source-linked-staging-acceptance-v1'
STAGE_STATUS = 'PRIVATE_SOURCE_LINKED_STAGE_READY'
EXPECTED_ROUTES = ('/up', '/', '/api/stations', '/api/v1/stations',
                   '/api/me', '/api/v1/me', '/api/admin/overview')
CHECKS = ('clean_checkout', 'release_interlock_clear',
          'private_environment', 'resolved_compose',
          'immutable_running_source', 'private_https_routes',
          'private_environment_stable_after_http', 'stable_runtime_after_http')
MAX_STAGE_AGE = dt.timedelta(hours=6)
MAX_STAGE_SPAN = dt.timedelta(minutes=3)
SHA = re.compile(r'^[a-f0-9]{40}$')
DIGEST = re.compile(r'^[a-f0-9]{64}$')
UTC = re.compile(r'^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,3})?Z$')


def utc(value):
    if not isinstance(value, str) or not UTC.fullmatch(value):
        raise BundleError('Invalid private staging UTC observation')
    try:
        parsed = dt.datetime.fromisoformat(value.replace('Z', '+00:00'))
        if parsed.utcoffset() != dt.timedelta(0):
            raise ValueError('not UTC')
        return parsed
    except ValueError as exc:
        raise BundleError('Invalid private staging UTC observation') from exc


def no_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise BundleError('Duplicate stage evidence key')
        result[key] = value
    return result


def private_stage_evidence(path):
    file = Path(path)
    parent = file.parent
    if parent.is_symlink() or not parent.is_dir():
        raise BundleError('Private stage evidence must have a real parent')
    if os.name != 'nt' and parent.stat().st_mode & 0o077:
        raise BundleError('Private stage evidence parent must be owner-only')
    # Open the exact inode once: do not follow symlinks or wait on a FIFO.
    if not hasattr(os, 'O_NOFOLLOW') or not hasattr(os, 'O_NONBLOCK'):
        raise BundleError('Secure private evidence access is not available')
    fd = os.open(file, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or not 2 <= info.st_size <= 64*1024 or (
                os.name != 'nt' and info.st_mode & 0o077):
            raise BundleError('Unsafe private stage evidence file')
        data = os.read(fd, 64*1024+1)
        if len(data) != info.st_size:
            raise BundleError('Stage evidence changed during reading')
    finally:
        os.close(fd)
    try:
        report = json.loads(data.decode('utf-8'), object_pairs_hook=no_duplicates,
                            parse_constant=lambda _: (_ for _ in ()).throw(BundleError('Nonfinite JSON')))
    except (ValueError, UnicodeError) as exc:
        raise BundleError('Private stage report JSON invalid') from exc
    if not isinstance(report, dict):
        raise BundleError('Stage report must be an object')
    return report, hashlib.sha256(data).hexdigest()


def require_reviewed_stage(report, source_sha, now):
    if report.get('schema') != STAGE_SCHEMA or report.get('status') != STAGE_STATUS or (
            report.get('source_sha') != source_sha) or not SHA.fullmatch(source_sha):
        raise BundleError('Staging evidence is not for the requested source SHA')
    checks = report.get('checks')
    if not isinstance(checks, dict) or set(checks) != set(CHECKS) or any(
            checks[key] is not True for key in CHECKS):
        raise BundleError('Missing private staging safety checks')
    if report.get('image_source_label_self_declared') is not True or (
            report.get('independent_build_attestation') is not False):
        raise BundleError('Staging report claims unsupported source attestation')
    for field in ('synthetic_accounts_authenticated', 'simulator_session_completed',
                  'live_charger_verified', 'live_ocpi_verified', 'real_money_moved',
                  'production_accepted', 'customer_contract_accepted'):
        if report.get(field) is not False:
            raise BundleError('Staging evidence contains an unsupported acceptance claim')
    if not isinstance(report.get('runtime_fingerprint_sha256'), str) or not DIGEST.fullmatch(
            report['runtime_fingerprint_sha256']):
        raise BundleError('Staging runtime identity fingerprint missing')
    routes = report.get('http_routes')
    if not isinstance(routes, list) or len(routes) != len(EXPECTED_ROUTES):
        raise BundleError('Stage HTTPS evidence is incomplete')
    for i, path in enumerate(EXPECTED_ROUTES):
        row = routes[i]
        if not isinstance(row, dict) or set(row) != {'route', 'status', 'passed'} or (
                row['route'] != path) or row['passed'] is not True or type(row['status']) is not int:
            raise BundleError('Stage route evidence corrupted or reordered')
        if row['status'] not in ((401, 403) if i >= 4 else (200,)):
            raise BundleError('Stage route does not meet read-only access policy')
    start = utc(report.get('observed_from_utc'))
    end = utc(report.get('observed_until_utc'))
    if end < start or end-start > MAX_STAGE_SPAN or (
            end > now + dt.timedelta(minutes=5)) or now-end > MAX_STAGE_AGE:
        raise BundleError('Stage observation is stale, future-dated or too long')
    return end


def verify_alignment(stage_file, mobile_dir, source_sha, now=None):
    if not isinstance(source_sha, str) or not SHA.fullmatch(source_sha):
        raise BundleError('Exact Git source revision required')
    if now is None:
        now = dt.datetime.now(dt.timezone.utc)
    if not isinstance(now, dt.datetime) or now.utcoffset() != dt.timedelta(0):
        raise BundleError('A timezone-aware UTC reference is required')
    mobile = verify_mobile(mobile_dir, source_sha)
    # Only the workflow-bound developer packager can be used for combined
    # delivery. Raw user-labeled APK/ZIP bundles do not prove CI origin.
    workflow = mobile.get('workflow_evidence')
    if not isinstance(workflow, dict) or set(workflow) != {
            'provider','repository','workflow_name','workflow_id',
            'workflow_path','run_id','head_sha','android','ios',
            'snapshot_authenticated'} or workflow.get('snapshot_authenticated') is not False:
        raise BundleError('Workflow-bound development artifact evidence required')
    report, stage_digest = private_stage_evidence(stage_file)
    observed = require_reviewed_stage(report, source_sha, now)
    manifest_path = Path(mobile_dir) / 'manifest.json'
    manifest_digest = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
    return {
        'schema': SCHEMA,
        'status': 'DEVELOPMENT_ONLY_SOURCE_ALIGNMENT_VERIFIED',
        'source_sha': source_sha,
        'stage_report_sha256': stage_digest,
        'mobile_manifest_sha256': manifest_digest,
        'stage_runtime_fingerprint_sha256': report['runtime_fingerprint_sha256'],
        'mobile_workflow_run_id': workflow['run_id'],
        'stage_observed_until_utc': observed.isoformat().replace('+00:00', 'Z'),
        'checked_at_utc': now.isoformat(timespec='seconds').replace('+00:00', 'Z'),
        'private_stage_source_label_self_declared': True,
        'github_snapshot_independently_authenticated': False,
        'android_store_signed': False,
        'ios_device_signed': False,
        'physical_charging_verified': False,
        'real_money_moved': False,
        'production_accepted': False,
        'customer_contract_accepted': False,
    }


def write_private_report(file, result):
    path = Path(file)
    parent = path.parent
    if parent.is_symlink() or not parent.is_dir() or (
            os.name != 'nt' and parent.stat().st_mode & 0o077):
        raise BundleError('Output parent must be a real owner-only directory')
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
    if hasattr(os, 'O_NOFOLLOW'):
        flags |= os.O_NOFOLLOW
    descriptor = os.open(path, flags, 0o600)
    try:
        with os.fdopen(descriptor, 'w', encoding='utf-8') as output:
            json.dump(result, output, indent=2, sort_keys=True)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(description='Source-align private staging and unsigned developer mobile evidence; never certifies production')
    parser.add_argument('--stage-report', required=True)
    parser.add_argument('--mobile-bundle', required=True)
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args(argv)
    try:
        result = verify_alignment(args.stage_report, args.mobile_bundle, args.source_sha)
        write_private_report(args.output, result)
    except (BundleError, OSError, ValueError, TypeError):
        print('FAIL: staging and mobile developer evidence are not verified for the same source. No private data printed.', file=sys.stderr)
        return 1
    print('PASS: private staging and development-only mobile artifacts share the exact Git source. No hardware, payments, signing or production acceptance.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
