"""Offline source alignment must never certify mismatched/unsigned app deliveries."""
import datetime as dt
import io
import hashlib
from unittest.mock import patch
import json
from pathlib import Path
import stat
import sys
import tarfile
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from delivery_bundle import BundleError, create
import delivery_source_alignment as alignment
from delivery_source_alignment import (CHECKS, EXPECTED_ROUTES, SCHEMA,
                                       private_stage_evidence, require_reviewed_stage,
                                       verify_alignment, write_private_report, main)

SHA = 'a'*40
NOW = dt.datetime(2026,11,8,8,0,0,tzinfo=dt.timezone.utc)


class SourceAlignmentTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.stage = self.root / 'staging.json'
        self.apk = self.root / 'debug.apk'
        self.ios = self.root / 'simulator.zip'
        self.mobile = self.root / 'bundle'
        self.out = self.root / 'alignment.json'
        with zipfile.ZipFile(self.apk,'w') as z:
            z.writestr('AndroidManifest.xml','<manifest/>')
            z.writestr('classes.dex','synthetic dex')
        with zipfile.ZipFile(self.ios,'w') as z:
            z.writestr('Runner.app/Info.plist','<plist/>')
            z.writestr('Runner.app/Runner','synthetic simulator')
        self.workflow = {
            'provider':'github_actions','repository':'creabizsalonspa-create/EV',
            'workflow_name':'Hasone customer development builds',
            'workflow_id':410027022,'workflow_path':'.github/workflows/hasone-customer.yml',
            'run_id':42348377299,'head_sha':SHA,
            'android':{'artifact_id':111,'wrapper_sha256':'b'*64},
            'ios':{'artifact_id':222,'wrapper_sha256':'c'*64},
            'snapshot_authenticated':False,
        }
        self.report={
            'schema':'ev-private-source-linked-staging-acceptance-v1',
            'source_sha':SHA,'status':'PRIVATE_SOURCE_LINKED_STAGE_READY',
            'observed_from_utc':(NOW-dt.timedelta(minutes=2)).isoformat(timespec='seconds').replace('+00:00','Z'),
            'observed_until_utc':(NOW-dt.timedelta(minutes=1)).isoformat(timespec='seconds').replace('+00:00','Z'),
            'checks':{k:True for k in CHECKS},
            'runtime_fingerprint_sha256':'d'*64,
            'http_routes':[{'route':route,'status':(401 if i>=4 else 200),'passed':True}
                           for i,route in enumerate(EXPECTED_ROUTES)],
            'image_source_label_self_declared':True,
            'independent_build_attestation':False,
            'synthetic_accounts_authenticated':False,
            'simulator_session_completed':False,
            'live_charger_verified':False,
            'live_ocpi_verified':False,
            'real_money_moved':False,
            'production_accepted':False,
            'customer_contract_accepted':False,
        }
        self.save_stage()
        create(self.mobile,SHA,self.apk,self.ios,workflow_evidence=self.workflow)

    def save_stage(self):
        self.stage.write_text(json.dumps(self.report),encoding='utf-8')
        self.stage.chmod(0o600)

    def verify(self):
        return verify_alignment(self.stage,self.mobile,SHA,NOW)

    def test_positive_source_alignment_has_only_developer_claims(self):
        summary=self.verify()
        self.assertEqual(summary['schema'],SCHEMA)
        self.assertEqual(summary['status'],'DEVELOPMENT_ONLY_SOURCE_ALIGNMENT_VERIFIED')
        self.assertEqual(summary['source_sha'],SHA)
        self.assertEqual(summary['mobile_workflow_run_id'],42348377299)
        self.assertEqual(summary['mobile_artifact_scope'],'HASONE_CUSTOMER_DEVELOPER_REFERENCE_ONLY')
        self.assertEqual(summary['mobile_workflow_name'],'Hasone customer development builds')
        self.assertFalse(summary['shared_customer_vendor_developer_bundle_verified'])
        for key in ('github_snapshot_independently_authenticated','android_store_signed',
                    'ios_device_signed','mobile_backend_origin_matches_staging_verified',
                    'customer_native_authenticated_journeys_verified','vendor_android_ios_artifacts_verified',
                    'physical_device_install_verified','physical_charging_verified','real_money_moved',
                    'production_accepted','customer_contract_accepted'):
            self.assertIs(summary[key],False,key)
        self.assertEqual(len(summary['stage_report_sha256']),64)
        self.assertNotIn('secret',str(summary).lower())

    def test_private_nonoverwriting_summary_and_exact_source(self):
        write_private_report(self.out,self.verify())
        self.assertEqual(stat.S_IMODE(self.out.stat().st_mode),0o600)
        self.assertEqual(json.loads(self.out.read_text())['source_sha'],SHA)
        with self.assertRaises(FileExistsError):
            write_private_report(self.out,self.verify())
        self.assertNotIn('APP_KEY',self.out.read_text())

    def test_mismatched_staging_and_mobile_commits_fail_closed(self):
        self.report['source_sha']='e'*40
        self.save_stage()
        with self.assertRaises(BundleError):self.verify()
        self.report['source_sha']=SHA
        self.save_stage()
        with self.assertRaises(BundleError):
            verify_alignment(self.stage,self.mobile,'f'*40,NOW)

    def test_missing_workflow_bound_metadata_refuses_source_alignment(self):
        import shutil
        shutil.rmtree(self.mobile)
        create(self.mobile,SHA,self.apk,self.ios)
        with self.assertRaises(BundleError):self.verify()

    def test_stale_future_or_unordered_staging_refuses_handoff(self):
        for start,end in [
            (NOW-dt.timedelta(hours=7),NOW-dt.timedelta(hours=6,minutes=1)),
            (NOW-dt.timedelta(minutes=5),NOW-dt.timedelta(minutes=1)),
            (NOW+dt.timedelta(minutes=6),NOW+dt.timedelta(minutes=7)),
            (NOW-dt.timedelta(minutes=1),NOW-dt.timedelta(minutes=2)),
        ]:
            self.report['observed_from_utc']=start.isoformat().replace('+00:00','Z')
            self.report['observed_until_utc']=end.isoformat().replace('+00:00','Z')
            self.save_stage()
            with self.subTest(start=start,end=end),self.assertRaises(BundleError):
                self.verify()

    def test_unsafe_staging_flags_cannot_be_laundered_into_developer_handoff(self):
        for field,value in [
            ('production_accepted',True),('real_money_moved',True),
            ('live_charger_verified',True),('independent_build_attestation',True),
            ('image_source_label_self_declared',False),
            ('customer_contract_accepted','false')
        ]:
            self.report[field]=value
            self.save_stage()
            with self.subTest(field=field),self.assertRaises(BundleError):self.verify()
            self.report[field]=False if field!='image_source_label_self_declared' else True

    def test_missing_or_falsely_passed_staging_checks_refuse_handoff(self):
        for key in CHECKS:
            self.report['checks'][key]=False
            self.save_stage()
            with self.subTest(check=key),self.assertRaises(BundleError):self.verify()
            self.report['checks'][key]=True
        del self.report['checks']['release_interlock_clear']
        self.save_stage()
        with self.assertRaises(BundleError):self.verify()

    def test_unsafe_private_http_response_or_missing_route_refuses_handoff(self):
        for index,status in [(0,503),(1,302),(4,200),(5,500),(6,302)]:
            original=self.report['http_routes'][index]['status']
            self.report['http_routes'][index]['status']=status
            self.save_stage()
            with self.subTest(index=index),self.assertRaises(BundleError):self.verify()
            self.report['http_routes'][index]['status']=original
        self.report['http_routes'].pop()
        self.save_stage()
        with self.assertRaises(BundleError):self.verify()

    def test_report_reordering_duplicate_keys_and_nonfinite_json_rejected(self):
        self.report['http_routes'].reverse()
        self.save_stage()
        with self.assertRaises(BundleError):self.verify()
        self.stage.write_text('{"status":"one","status":"two"}')
        with self.assertRaises(BundleError):private_stage_evidence(self.stage)
        self.stage.write_text('{"bad":NaN}')
        with self.assertRaises(BundleError):private_stage_evidence(self.stage)

    def test_world_readable_staging_evidence_and_symlinks_refuse(self):
        self.stage.chmod(0o644)
        with self.assertRaises(BundleError):self.verify()
        self.stage.chmod(0o600)
        link=self.root/'alias.json'
        link.symlink_to(self.stage)
        with self.assertRaises((BundleError,OSError)):
            private_stage_evidence(link)

    def test_modified_mobile_binary_refuses_even_with_correct_source(self):
        with (self.mobile/'android-development.apk').open('ab') as output:
            output.write(b'tampered')
        with self.assertRaises(BundleError):self.verify()

    def test_cli_source_mismatch_refuses_without_writing_evidence(self):
        args=['--stage-report',str(self.stage),'--mobile-bundle',str(self.mobile),
              '--source-sha','e'*40,'--output',str(self.out)]
        self.assertEqual(main(args),1)
        self.assertFalse(self.out.exists())

    def test_alignment_manifest_digest_matches_bytes_verified(self):
        summary = self.verify()
        expected = hashlib.sha256((self.mobile / 'manifest.json').read_bytes()).hexdigest()
        self.assertEqual(summary['mobile_manifest_sha256'], expected)

    def test_manifest_edit_after_mobile_verification_rejects_unreviewed_digest(self):
        original = alignment.verify_mobile

        def tamper_then_return(*args):
            result = original(*args)
            manifest = self.mobile / 'manifest.json'
            manifest.write_bytes(manifest.read_bytes() + b' ')
            manifest.chmod(0o600)
            return result

        with patch.object(alignment, 'verify_mobile', side_effect=tamper_then_return):
            with self.assertRaisesRegex(BundleError, 'changed during source alignment'):
                self.verify()

    def test_same_content_atomic_manifest_replacement_is_rejected(self):
        original = alignment.verify_mobile

        def replace_then_return(*args):
            result = original(*args)
            manifest = self.mobile / 'manifest.json'
            replacement = self.mobile / 'new-manifest.json'
            replacement.write_bytes(manifest.read_bytes())
            replacement.chmod(0o600)
            replacement.replace(manifest)
            return result

        with patch.object(alignment, 'verify_mobile', side_effect=replace_then_return):
            with self.assertRaisesRegex(BundleError, 'changed during source alignment'):
                self.verify()

    def test_manifest_symlink_is_rejected_before_verification(self):
        manifest = self.mobile / 'manifest.json'
        original = self.mobile / 'original-manifest.json'
        manifest.replace(original)
        manifest.symlink_to(original)
        with self.assertRaises(BundleError):
            self.verify()

    def test_stage_report_age_boundary(self):
        self.assertEqual(require_reviewed_stage(self.report,SHA,NOW),NOW-dt.timedelta(minutes=1))


if __name__=='__main__':
    unittest.main()
