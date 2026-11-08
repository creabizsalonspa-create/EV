#!/usr/bin/env python3
"""Read-only, untrusted GitHub Actions snapshot consistency gate; never deploys."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys

SHA = re.compile(r'^[a-f0-9]{40}$')
EXPECTED = {'business-platform', 'finance-postgres', 'connector-postgres', 'staging-image'}
WORKFLOW = {'id': 397762279, 'name': 'Platform validation', 'path': '.github/workflows/ci.yml'}
REPO = 'creabizsalonspa-create/EV'
MAX_JSON = 8 * 1024 * 1024

class EvidenceError(Exception):
    pass

def no_duplicate_keys(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise EvidenceError('Duplicate metadata key')
        result[key] = value
    return result

def unsafe_constant(_):
    raise EvidenceError('Nonstandard JSON numeric constant')

def snapshot_directory_chain(parent):
    """Record real ancestor directories without resolving symlinked path components."""
    directory = Path(os.path.abspath(str(parent)))
    chain = []
    while True:
        try:
            info = os.lstat(directory)
        except OSError as exc:
            raise EvidenceError('Metadata ancestor directory inaccessible') from exc
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            raise EvidenceError('Metadata path contains a symlinked ancestor')
        chain.append((directory, info.st_dev, info.st_ino))
        if directory == directory.parent:
            break
        directory = directory.parent
    return tuple(chain)


def assert_directory_chain_unchanged(chain):
    if not chain or snapshot_directory_chain(chain[0][0]) != chain:
        raise EvidenceError('Metadata ancestor directory changed during read')


def stat_identity(info):
    return (info.st_dev, info.st_ino, info.st_size,
            info.st_mtime_ns, info.st_ctime_ns)

def private_json(file):
    file = Path(file)
    if not hasattr(os, 'O_NOFOLLOW') or not hasattr(os, 'O_NONBLOCK'):
        raise EvidenceError('No safe local file access')
    parent = file.parent
    parent_chain = snapshot_directory_chain(parent)
    if os.lstat(parent).st_mode & 0o077:
        raise EvidenceError('Metadata parent must be private')
    try:
        fd = os.open(file, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError as exc:
        raise EvidenceError('Cannot safely open metadata') from exc
    try:
        st = os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or
                st.st_mode & 0o077 or not 2 <= st.st_size <= MAX_JSON):
            raise EvidenceError('Metadata must be bounded, single-link, regular and private')
        raw = os.read(fd, MAX_JSON + 1)
        after = os.fstat(fd)
        try:
            visible = os.lstat(file)
        except OSError as exc:
            raise EvidenceError('Metadata path disappeared during read') from exc
        if (len(raw) != st.st_size or stat_identity(after) != stat_identity(st) or
                not stat.S_ISREG(visible.st_mode) or visible.st_nlink != 1 or
                visible.st_mode & 0o077 or stat_identity(visible) != stat_identity(st)):
            raise EvidenceError('Metadata file changed during read')
        assert_directory_chain_unchanged(parent_chain)
    finally:
        os.close(fd)
    try:
        obj = json.loads(raw.decode('utf-8'), object_pairs_hook=no_duplicate_keys, parse_constant=unsafe_constant)
    except (UnicodeError, ValueError) as exc:
        raise EvidenceError('Invalid JSON metadata') from exc
    if not isinstance(obj, dict):
        raise EvidenceError('Metadata must be an object')
    return obj, hashlib.sha256(raw).hexdigest()

def verify(run, listing, source):
    if not isinstance(source, str) or not SHA.fullmatch(source):
        raise EvidenceError('Exact 40-hex candidate SHA required')
    if not isinstance(run, dict) or not isinstance(listing, dict):
        raise EvidenceError('Missing run or job listing')
    rid, attempt = run.get('id'), run.get('run_attempt')
    if type(rid) is not int or rid <= 0 or type(attempt) is not int or attempt <= 0:
        raise EvidenceError('Run ID or attempt invalid')
    if (type(run.get('workflow_id')) is not int or
            run['workflow_id'] != WORKFLOW['id'] or
            run.get('name') != WORKFLOW['name'] or run.get('path') != WORKFLOW['path']):
        raise EvidenceError('Wrong platform workflow')
    if run.get('head_sha') != source or run.get('status') != 'completed' or run.get('conclusion') != 'success':
        raise EvidenceError('Source-mismatched or unverified workflow run')
    if run.get('event') not in ('pull_request', 'push', 'workflow_dispatch'):
        raise EvidenceError('Unreviewed workflow trigger')
    repository = run.get('repository')
    head_repo = run.get('head_repository')
    if (not isinstance(repository, dict) or not isinstance(head_repo, dict) or
            repository.get('full_name') != REPO or head_repo.get('full_name') != REPO or
            type(repository.get('id')) is not int or repository['id'] <= 0 or
            type(head_repo.get('id')) is not int or head_repo['id'] != repository['id']):
        raise EvidenceError('Workflow originates from another repository')
    jobs = listing.get('jobs')
    if type(listing.get('total_count')) is not int or listing['total_count'] != len(EXPECTED) or not isinstance(jobs, list) or len(jobs) != len(EXPECTED):
        raise EvidenceError('Incomplete, unexpected or paginated job listing')
    names = set()
    job_ids = set()
    for job in jobs:
        if not isinstance(job, dict):
            raise EvidenceError('Malformed job')
        name, jid = job.get('name'), job.get('id')
        if name not in EXPECTED or name in names or type(jid) is not int or jid <= 0 or jid in job_ids:
            raise EvidenceError('Unknown, repeated or invalid job')
        if (type(job.get('run_id')) is not int or job['run_id'] != rid or
                type(job.get('run_attempt')) is not int or job['run_attempt'] != attempt or
                job.get('workflow_name') != WORKFLOW['name'] or
                job.get('head_sha') != source or job.get('status') != 'completed' or
                job.get('conclusion') != 'success'):
            raise EvidenceError('Incomplete, skipped, mismatched or failed job')
        names.add(name)
        job_ids.add(jid)
    if names != EXPECTED:
        raise EvidenceError('Missing mandatory platform validation job')
    return {'schema': 'ev-staging-platform-ci-snapshot-v1', 'source_sha': source,
            'run_id': rid, 'run_attempt': attempt,
            'successful_required_jobs': sorted(names),
            'job_ids': sorted(job_ids), 'run_conclusion': 'success',
            'metadata_source': 'OPERATOR_PROVIDED_UNAUTHENTICATED_GITHUB_JSON',
            'github_live_status_independently_confirmed': False,
            'staging_deployed': False, 'production_accepted': False}

def main(argv=None):
    parser = argparse.ArgumentParser(description='Offline GitHub CI consistency; never verifies GitHub authenticity or deployment')
    for name in ('--run-json', '--jobs-json', '--source-sha', '--output'):
        parser.add_argument(name, required=True)
    args = parser.parse_args(argv)
    try:
        run, run_sha = private_json(args.run_json)
        listing, jobs_sha = private_json(args.jobs_json)
        result = verify(run, listing, args.source_sha)
        result['run_snapshot_sha256'] = run_sha
        result['jobs_snapshot_sha256'] = jobs_sha
        target = Path(args.output)
        if target.parent.is_symlink() or not target.parent.is_dir() or target.parent.stat().st_mode & 0o077:
            raise EvidenceError('Result parent must be private')
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as output:
            json.dump(result, output, sort_keys=True, indent=2)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
    except (EvidenceError, OSError, ValueError, TypeError):
        print('FAIL: platform CI snapshot not safe or complete; never infer deploy approval.', file=sys.stderr)
        return 1
    print('PASS: offline job/run consistency only; confirm current official GitHub status independently.')
    return 0

if __name__ == '__main__':
    sys.exit(main())
