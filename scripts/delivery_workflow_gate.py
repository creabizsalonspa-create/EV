#!/usr/bin/env python3
"""Offline, fail-closed GitHub Actions -> HAS.ONE development demo bundle handoff.

Metadata must first be retrieved from the trusted GitHub API by the operator.
It is not cryptographically signed and does not prove build reproducibility.
"""
import argparse
import json
from pathlib import Path
import re
import sys

from delivery_artifact_import import import_actions
from delivery_bundle import BundleError, checksum

REPOSITORY = 'creabizsalonspa-create/EV'
WORKFLOW = 'Hasone customer development builds'
WORKFLOW_ID = 410027022
WORKFLOW_PATH = '.github/workflows/hasone-customer.yml'
SHA40 = re.compile(r'^[0-9a-f]{40}$')
SHA256 = re.compile(r'^sha256:([0-9a-f]{64})$')
ARTIFACTS = {
    'android': 'hasone-customer-android-development',
    'ios': 'hasone-customer-ios-simulator',
}
PROFILES = {
    'hasone_customer': {
        'workflow': WORKFLOW, 'workflow_id': WORKFLOW_ID, 'workflow_path': WORKFLOW_PATH,
        'artifacts': ARTIFACTS, 'ios_member': 'hasone-ios-simulator.tar.gz',
    },
    'ev_shared_native': {
        'workflow': 'Native client validation', 'workflow_id': 397853920,
        'workflow_path': '.github/workflows/mobile.yml',
        'artifacts': {'android': 'creabiz-android-debug', 'ios': 'creabiz-ios-simulator'},
        'ios_member': 'creabiz-ios-simulator.tar.gz',
    },
}


def read_snapshot(file):
    file = Path(file)
    if file.is_symlink() or not file.is_file() or not 1 <= file.stat().st_size <= 4 * 1024**2:
        raise BundleError('Invalid local GitHub metadata snapshot')
    try:
        snapshot = json.loads(file.read_text('utf-8'))
    except (UnicodeError, ValueError) as exc:
        raise BundleError('Unreadable GitHub metadata snapshot') from exc
    if not isinstance(snapshot, dict):
        raise BundleError('GitHub metadata must be an object')
    return snapshot


def verify_workflow(run, artifact_listing, source_sha, run_id, android_file, ios_file, profile='hasone_customer'):
    config = PROFILES.get(profile)
    if config is None:
        raise BundleError('Unknown reviewed GitHub mobile development workflow')
    artifacts = config['artifacts']
    if not isinstance(run, dict):
        raise BundleError('GitHub run must be a metadata object')
    if not isinstance(source_sha, str) or not SHA40.fullmatch(source_sha):
        raise BundleError('Exact 40-character Git revision required')
    if type(run_id) is not int or run_id <= 0 or type(run.get('id')) is not int or run['id'] != run_id:
        raise BundleError('GitHub workflow run ID mismatch')
    if run.get('status') != 'completed' or run.get('conclusion') != 'success' or run.get('name') != config['workflow']:
        raise BundleError('Workflow is not the successful reviewed development build')
    if type(run.get('workflow_id')) is not int or run['workflow_id'] != config['workflow_id'] or \
            run.get('path') != config['workflow_path']:
        raise BundleError('Workflow ID or source path differs from reviewed mobile build workflow')
    if run.get('event') not in ('push', 'pull_request', 'workflow_dispatch'):
        raise BundleError('Unexpected GitHub workflow trigger')
    if run.get('head_sha') != source_sha:
        raise BundleError('Build workflow source does not match handoff source')
    repo = run.get('repository')
    origin = run.get('head_repository')
    if not isinstance(repo, dict) or not isinstance(origin, dict) or \
            repo.get('full_name') != REPOSITORY or origin.get('full_name') != REPOSITORY or \
            type(repo.get('id')) is not int or type(origin.get('id')) is not int:
        raise BundleError('Build workflow must originate within the reviewed repository')
    if not isinstance(run.get('head_branch'), str) or not run['head_branch']:
        raise BundleError('Build workflow branch identity missing')
    if not isinstance(artifact_listing, dict) or not isinstance(artifact_listing.get('artifacts'), list):
        raise BundleError('GitHub workflow artifact listing is incomplete')

    selected = {}
    for item in artifact_listing['artifacts']:
        if not isinstance(item, dict):
            raise BundleError('Invalid GitHub artifact record')
        name = item.get('name')
        if name not in artifacts.values():
            continue
        if name in selected:
            raise BundleError('Duplicate development build artifact name')
        wr = item.get('workflow_run')
        if not isinstance(wr, dict) or item.get('expired') is not False or \
                wr.get('id') != run_id or wr.get('head_sha') != source_sha or \
                wr.get('head_branch') != run['head_branch'] or \
                wr.get('repository_id') != repo['id'] or \
                wr.get('head_repository_id') != origin['id']:
            raise BundleError('Artifact belongs to a different workflow/source or is expired')
        if type(item.get('id')) is not int or item['id'] <= 0 or \
                type(item.get('size_in_bytes')) is not int or item['size_in_bytes'] <= 0:
            raise BundleError('Invalid artifact identity or byte length')
        digest = item.get('digest')
        if not isinstance(digest, str) or not SHA256.fullmatch(digest):
            raise BundleError('Published GitHub artifact has no SHA-256 digest')
        selected[name] = item

    if set(selected) != set(artifacts.values()):
        raise BundleError('Both reviewed Android and iOS simulator artifacts are required')
    if selected[artifacts['android']]['id'] == selected[artifacts['ios']]['id']:
        raise BundleError('Android and iOS artifacts cannot have the same identity')

    for role, file in [('android', android_file), ('ios', ios_file)]:
        file = Path(file)
        if file.is_symlink() or not file.is_file():
            raise BundleError('GitHub artifact wrapper must be a regular file')
        digest, size = checksum(file)
        item = selected[artifacts[role]]
        if size != item['size_in_bytes'] or digest != item['digest'][7:]:
            raise BundleError('Artifact bytes differ from the same-run GitHub API record')
    return {
        'android_digest': selected[artifacts['android']]['digest'][7:],
        'ios_digest': selected[artifacts['ios']]['digest'][7:],
        'run_id': run_id,
        'source_sha': source_sha,
        'android_artifact_id': selected[artifacts['android']]['id'],
        'ios_artifact_id': selected[artifacts['ios']]['id'],
    }


def package_from_workflow(output, source_sha, run_id, run_file, artifacts_file, android_file, ios_file, profile='hasone_customer'):
    run = read_snapshot(run_file)
    listing = read_snapshot(artifacts_file)
    config=PROFILES.get(profile)
    if config is None:
        raise BundleError('Unknown reviewed mobile build workflow')
    proven = verify_workflow(run, listing, source_sha, run_id, android_file, ios_file, profile=profile)
    workflow_evidence = {
        'provider': 'github_actions',
        'repository': REPOSITORY,
        'workflow_name': config['workflow'],
        'workflow_id': config['workflow_id'],
        'workflow_path': config['workflow_path'],
        'run_id': run_id,
        'head_sha': source_sha,
        'android': {'artifact_id': proven['android_artifact_id'],
                    'wrapper_sha256': proven['android_digest']},
        'ios': {'artifact_id': proven['ios_artifact_id'],
                'wrapper_sha256': proven['ios_digest']},
        # Offline metadata is not cryptographically authenticated by this importer.
        'snapshot_authenticated': False,
    }
    return import_actions(
        output, proven['source_sha'], android_file, proven['android_digest'],
        ios_file, proven['ios_digest'], workflow_evidence=workflow_evidence,
        ios_member=config['ios_member'],
    )


def main(argv=None):
    parser = argparse.ArgumentParser(description='Offline GitHub workflow-bound HAS.ONE development artifact handoff')
    parser.add_argument('--output', required=True)
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--run-id', type=int, required=True)
    parser.add_argument('--run-json', required=True)
    parser.add_argument('--artifacts-json', required=True)
    parser.add_argument('--android-wrapper', required=True)
    parser.add_argument('--ios-wrapper', required=True)
    args = parser.parse_args(argv)
    try:
        package_from_workflow(
            args.output, args.source_sha, args.run_id, args.run_json,
            args.artifacts_json, args.android_wrapper, args.ios_wrapper,
        )
    except (BundleError, OSError, RuntimeError, EOFError, ValueError):
        print('FAIL: GitHub workflow-bound development artifacts do not satisfy integrity checks. No private details printed.', file=sys.stderr)
        return 1
    print('PASS: Android/iOS development artifacts match the declared successful GitHub workflow and private delivery bundle checks. Snapshot authenticity, signing and customer acceptance remain independent.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
