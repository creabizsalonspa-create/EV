import hashlib
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
import zipfile
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from delivery_bundle import BundleError, verify
from delivery_workflow_gate import verify_workflow, read_snapshot, package_from_workflow, main

SHA = 'a' * 40
RUN_ID = 42348377299
REPO = 'creabizsalonspa-create/EV'
REPO_ID = 1435146528


class VerifiedWorkflowHandoffTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.android = self.root / 'android-wrapper.zip'
        self.ios = self.root / 'ios-wrapper.zip'
        apk = io.BytesIO()
        with zipfile.ZipFile(apk, 'w') as z:
            z.writestr('AndroidManifest.xml', '<manifest/>')
            z.writestr('classes.dex', 'placeholder test')
        with zipfile.ZipFile(self.android, 'w') as z:
            z.writestr('app-debug.apk', apk.getvalue())
        tar = io.BytesIO()
        with tarfile.open(fileobj=tar, mode='w:gz') as z:
            for name, value in {'Runner.app/Info.plist': b'<plist/>', 'Runner.app/Runner': b'simulator'}.items():
                member = tarfile.TarInfo(name)
                member.size = len(value)
                z.addfile(member, io.BytesIO(value))
        with zipfile.ZipFile(self.ios, 'w') as z:
            z.writestr('hasone-ios-simulator.tar.gz', tar.getvalue())
        self.run = {
            'id': RUN_ID, 'name': 'Hasone customer development builds', 'status': 'completed',
            'conclusion': 'success', 'event': 'pull_request', 'head_sha': SHA,
            'head_branch': 'development/fullstack-continuation',
            'repository': {'full_name': REPO, 'id': REPO_ID},
            'head_repository': {'full_name': REPO, 'id': REPO_ID},
        }
        self.listing = {
            'artifacts': [
                self.record('hasone-customer-android-development', self.android, 13569331617),
                self.record('hasone-customer-ios-simulator', self.ios, 13565252105),
            ]
        }

    def record(self, name, file, identity):
        data = file.read_bytes()
        return {
            'name': name, 'id': identity, 'size_in_bytes': len(data),
            'digest': 'sha256:' + hashlib.sha256(data).hexdigest(), 'expired': False,
            'workflow_run': {
                'id': RUN_ID, 'repository_id': REPO_ID,
                'head_repository_id': REPO_ID, 'head_branch': 'development/fullstack-continuation',
                'head_sha': SHA,
            }
        }

    def valid(self):
        return verify_workflow(self.run, self.listing, SHA, RUN_ID, self.android, self.ios)

    def snapshots(self):
        rp, ap = self.root / 'run.json', self.root / 'artifacts.json'
        rp.write_text(json.dumps(self.run))
        ap.write_text(json.dumps(self.listing))
        return rp, ap

    def test_same_successful_source_and_two_artifacts_pass(self):
        result = self.valid()
        self.assertEqual(result['source_sha'], SHA)
        self.assertEqual(result['run_id'], RUN_ID)

    def test_end_to_end_workflow_bound_bundle_creation(self):
        run, artifacts = self.snapshots()
        package_from_workflow(self.root / 'bundle', SHA, RUN_ID, run, artifacts, self.android, self.ios)
        manifest = verify(self.root / 'bundle', SHA)
        self.assertEqual(manifest['acceptance']['production_accepted'], False)
        proof = manifest['workflow_evidence']
        self.assertEqual(proof['run_id'], RUN_ID)
        self.assertEqual(proof['head_sha'], SHA)
        self.assertEqual(proof['android']['artifact_id'], 13569331617)
        self.assertEqual(proof['ios']['artifact_id'], 13565252105)
        self.assertEqual(proof['android']['wrapper_sha256'], self.listing['artifacts'][0]['digest'][7:])
        self.assertEqual(proof['snapshot_authenticated'], False)

    def test_declared_source_must_equal_run_head_sha(self):
        with self.assertRaises(BundleError):
            verify_workflow(self.run, self.listing, 'b' * 40, RUN_ID, self.android, self.ios)

    def test_reject_failed_queued_or_unrelated_workflow(self):
        for status, conclusion, name in [
            ('queued', None, 'Hasone customer development builds'),
            ('completed', 'failure', 'Hasone customer development builds'),
            ('completed', 'success', 'Unrelated workflow'),
        ]:
            test_run = dict(self.run, status=status, conclusion=conclusion, name=name)
            with self.assertRaises(BundleError):
                verify_workflow(test_run, self.listing, SHA, RUN_ID, self.android, self.ios)

    def test_reject_source_from_different_repository_or_event(self):
        for value in ('outsider/fork', 'creabizsalonspa-create/Other'):
            test_run = dict(self.run, head_repository=dict(self.run['head_repository'], full_name=value))
            with self.assertRaises(BundleError):
                verify_workflow(test_run, self.listing, SHA, RUN_ID, self.android, self.ios)
        with self.assertRaises(BundleError):
            verify_workflow(dict(self.run, event='pull_request_target'), self.listing, SHA, RUN_ID, self.android, self.ios)

    def test_reject_mixed_workflow_runs(self):
        self.listing['artifacts'][1]['workflow_run']['id'] += 1
        with self.assertRaises(BundleError): self.valid()

    def test_reject_wrong_source_and_repository_id_in_artifact(self):
        self.listing['artifacts'][0]['workflow_run']['head_sha'] = 'c' * 40
        with self.assertRaises(BundleError): self.valid()
        self.listing['artifacts'][0]['workflow_run']['head_sha'] = SHA
        self.listing['artifacts'][0]['workflow_run']['repository_id'] += 1
        with self.assertRaises(BundleError): self.valid()

    def test_reject_expired_duplicate_or_missing_platform(self):
        self.listing['artifacts'][1]['expired'] = True
        with self.assertRaises(BundleError): self.valid()
        self.listing['artifacts'][1]['expired'] = False
        self.listing['artifacts'].append(self.listing['artifacts'][0].copy())
        with self.assertRaises(BundleError): self.valid()
        self.listing['artifacts'].pop()
        self.listing['artifacts'].pop()
        with self.assertRaises(BundleError): self.valid()

    def test_reject_tampered_download_even_with_consistent_run_metadata(self):
        self.android.write_bytes(self.android.read_bytes() + b'tampered')
        with self.assertRaises(BundleError): self.valid()

    def test_reject_wrong_published_byte_count(self):
        self.listing['artifacts'][0]['size_in_bytes'] += 1
        with self.assertRaises(BundleError): self.valid()

    def test_reject_malformed_digest_and_artifact_id(self):
        self.listing['artifacts'][0]['digest'] = 'sha256:invalid'
        with self.assertRaises(BundleError): self.valid()
        self.listing['artifacts'][0]['digest'] = self.record('hasone-customer-android-development', self.android, 1)['digest']
        self.listing['artifacts'][0]['id'] = self.listing['artifacts'][1]['id']
        with self.assertRaises(BundleError): self.valid()

    def test_reject_invalid_or_symlinked_metadata_file(self):
        with self.assertRaises(BundleError): read_snapshot(self.root / 'absent')
        real = self.root / 'run.json'
        real.write_text(json.dumps(self.run))
        alias = self.root / 'alias'
        alias.symlink_to(real)
        with self.assertRaises(BundleError): read_snapshot(alias)

    def test_cli_refuses_invalid_source_without_creating_output(self):
        run, artifacts = self.snapshots()
        command = [
            '--output', str(self.root / 'bundle'),
            '--source-sha', 'b' * 40, '--run-id', str(RUN_ID),
            '--run-json', str(run), '--artifacts-json', str(artifacts),
            '--android-wrapper', str(self.android), '--ios-wrapper', str(self.ios),
        ]
        self.assertEqual(main(command), 1)
        self.assertFalse((self.root / 'bundle').exists())

    def test_cli_happy_path(self):
        run, artifacts = self.snapshots()
        command = [
            '--output', str(self.root / 'bundle'),
            '--source-sha', SHA, '--run-id', str(RUN_ID),
            '--run-json', str(run), '--artifacts-json', str(artifacts),
            '--android-wrapper', str(self.android), '--ios-wrapper', str(self.ios),
        ]
        self.assertEqual(main(command), 0)
        self.assertEqual(main(command), 1)


    def test_delivery_bundle_refuses_forged_workflow_source_without_tampering_binary(self):
        run, artifacts = self.snapshots()
        bundle = self.root / 'bundle'
        package_from_workflow(bundle, SHA, RUN_ID, run, artifacts, self.android, self.ios)
        file = bundle / 'manifest.json'
        data = json.loads(file.read_text('utf-8'))
        data['workflow_evidence']['head_sha'] = 'b' * 40
        file.write_text(json.dumps(data))
        with self.assertRaises(BundleError):
            verify(bundle, SHA)

    def test_delivery_bundle_refuses_false_signing_or_authenticated_metadata_claim(self):
        run, artifacts = self.snapshots()
        bundle = self.root / 'bundle'
        package_from_workflow(bundle, SHA, RUN_ID, run, artifacts, self.android, self.ios)
        file = bundle / 'manifest.json'
        data = json.loads(file.read_text('utf-8'))
        data['workflow_evidence']['snapshot_authenticated'] = True
        file.write_text(json.dumps(data))
        with self.assertRaises(BundleError):
            verify(bundle, SHA)

    def test_delivery_bundle_refuses_duplicate_github_artifact_identity(self):
        run, artifacts = self.snapshots()
        bundle = self.root / 'bundle'
        package_from_workflow(bundle, SHA, RUN_ID, run, artifacts, self.android, self.ios)
        file = bundle / 'manifest.json'
        data = json.loads(file.read_text('utf-8'))
        data['workflow_evidence']['ios']['artifact_id'] = data['workflow_evidence']['android']['artifact_id']
        file.write_text(json.dumps(data))
        with self.assertRaises(BundleError):
            verify(bundle, SHA)

    def test_delivery_bundle_refuses_forged_git_wrapper_digest_after_import(self):
        run, artifacts = self.snapshots()
        bundle = self.root / 'bundle'
        package_from_workflow(bundle, SHA, RUN_ID, run, artifacts, self.android, self.ios)
        file = bundle / 'manifest.json'
        data = json.loads(file.read_text('utf-8'))
        data['workflow_evidence']['android']['wrapper_sha256'] = 'unverified'
        file.write_text(json.dumps(data))
        with self.assertRaises(BundleError):
            verify(bundle, SHA)


if __name__ == '__main__':
    unittest.main()
