"""Source-gated shared EV customer/vendor native Android and iOS developer builds."""
import hashlib
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from delivery_bundle import BundleError, verify, verify_workflow_reference
from delivery_shared_native_gate import main
from delivery_workflow_gate import verify_workflow, package_from_workflow

SHA='c3af6567afd2d3abcbe89ebcffebd38e55ea0318'
RUN=41362370710
REPO='creabizsalonspa-create/EV'
REPO_ID=1435146528
ARTIFACTS=('creabiz-android-debug','creabiz-ios-simulator')


class SharedNativeHandoffTests(unittest.TestCase):
    def setUp(self):
        tmp=tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root=Path(tmp.name)
        self.android=self.root/'android-actions.zip'
        self.ios=self.root/'ios-actions.zip'
        inner=io.BytesIO()
        with zipfile.ZipFile(inner,'w') as z:
            z.writestr('AndroidManifest.xml','synthetic android manifest')
            z.writestr('classes.dex','synthetic classes')
        with zipfile.ZipFile(self.android,'w') as z:
            z.writestr('app-debug.apk',inner.getvalue())
        ios=io.BytesIO()
        with tarfile.open(fileobj=ios,mode='w:gz') as tar:
            for name,data in {'Runner.app/Info.plist':b'synthetic plist','Runner.app/Runner':b'synthetic simulator'}.items():
                item=tarfile.TarInfo(name)
                item.size=len(data)
                tar.addfile(item,io.BytesIO(data))
        with zipfile.ZipFile(self.ios,'w') as z:
            z.writestr('creabiz-ios-simulator.tar.gz',ios.getvalue())
        self.run={
            'id':RUN,'name':'Native client validation','status':'completed',
            'conclusion':'success','event':'pull_request','head_sha':SHA,
            'workflow_id':397853920,'path':'.github/workflows/mobile.yml',
            'head_branch':'development/fullstack-continuation',
            'repository':{'full_name':REPO,'id':REPO_ID},
            'head_repository':{'full_name':REPO,'id':REPO_ID},
        }
        self.listing={'artifacts':[
            self.artifact(self.android,ARTIFACTS[0],13105979447),
            self.artifact(self.ios,ARTIFACTS[1],13106285302),
        ]}

    def artifact(self,file,name,id):
        data=file.read_bytes()
        return {'id':id,'name':name,'size_in_bytes':len(data),
                'digest':'sha256:'+hashlib.sha256(data).hexdigest(),'expired':False,
                'workflow_run':{
                    'id':RUN,'repository_id':REPO_ID,'head_repository_id':REPO_ID,
                    'head_branch':self.run['head_branch'],'head_sha':SHA}}

    def snapshots(self):
        run,listing=self.root/'run.json',self.root/'artifacts.json'
        run.write_text(json.dumps(self.run))
        listing.write_text(json.dumps(self.listing))
        return run,listing

    def make(self):
        run,listing=self.snapshots()
        return package_from_workflow(self.root/'bundle',SHA,RUN,run,listing,
                                     self.android,self.ios,profile='ev_shared_native')

    def test_exact_shared_native_run_and_artifact_identity(self):
        result=verify_workflow(self.run,self.listing,SHA,RUN,self.android,self.ios,
                               profile='ev_shared_native')
        self.assertEqual(result['android_artifact_id'],13105979447)
        self.assertEqual(result['ios_artifact_id'],13106285302)

    def test_shared_workflow_bundle_has_correct_strict_profile_and_real_members(self):
        self.make()
        manifest=verify(self.root/'bundle',SHA)
        metadata=manifest['workflow_evidence']
        self.assertEqual(metadata['workflow_id'],397853920)
        self.assertEqual(metadata['workflow_path'],'.github/workflows/mobile.yml')
        self.assertEqual(metadata['workflow_name'],'Native client validation')
        self.assertFalse(metadata['snapshot_authenticated'])
        self.assertFalse(manifest['acceptance']['production_accepted'])
        self.assertFalse(manifest['acceptance']['signed_for_store'])
        with zipfile.ZipFile(self.root/'bundle'/'ios-simulator.zip') as zip:
            self.assertIn('Runner.app/Info.plist',zip.namelist())
        with zipfile.ZipFile(self.root/'bundle'/'android-development.apk') as apk:
            self.assertIn('AndroidManifest.xml',apk.namelist())

    def test_legacy_unidentified_shared_native_metadata_is_rejected(self):
        self.make()
        manifest=verify(self.root/'bundle',SHA)
        meta=dict(manifest['workflow_evidence'])
        del meta['workflow_id']
        del meta['workflow_path']
        with self.assertRaises(BundleError):
            verify_workflow_reference(meta,SHA)
        manifest['workflow_evidence']=meta
        (self.root/'bundle'/'manifest.json').write_text(json.dumps(manifest))
        with self.assertRaises(BundleError):verify(self.root/'bundle',SHA)

    def test_hasone_workflow_cannot_be_substituted_for_shared_native(self):
        with self.assertRaises(BundleError):
            verify_workflow(self.run,self.listing,SHA,RUN,self.android,self.ios)
        for wrong in [{'name':'Hasone customer development builds'},
                      {'workflow_id':410027022},
                      {'path':'.github/workflows/hasone-customer.yml'}]:
            changed=dict(self.run,**wrong)
            with self.assertRaises(BundleError):
                verify_workflow(changed,self.listing,SHA,RUN,self.android,self.ios,profile='ev_shared_native')

    def test_mixed_run_repo_fork_and_expired_artifact_reject(self):
        original=self.listing['artifacts'][1]['workflow_run']['id']
        self.listing['artifacts'][1]['workflow_run']['id']=RUN+1
        with self.assertRaises(BundleError):self.make()
        self.listing['artifacts'][1]['workflow_run']['id']=original
        self.listing['artifacts'][1]['expired']=True
        with self.assertRaises(BundleError):self.make()
        self.listing['artifacts'][1]['expired']=False
        self.run['head_repository']['full_name']='unknown/fork'
        with self.assertRaises(BundleError):self.make()
        self.assertFalse((self.root/'bundle').exists())

    def test_wrong_artifact_digest_and_name_reject_without_output(self):
        self.listing['artifacts'][0]['digest']='sha256:'+'f'*64
        with self.assertRaises(BundleError):self.make()
        self.listing['artifacts'][0]['digest']=self.artifact(self.android,ARTIFACTS[0],1)['digest']
        self.listing['artifacts'][0]['name']='hasone-customer-android-development'
        with self.assertRaises(BundleError):self.make()
        self.assertFalse((self.root/'bundle').exists())

    def test_wrong_ios_tar_member_is_rejected(self):
        contents=self.ios.read_bytes()
        with zipfile.ZipFile(self.ios,'w') as z:
            z.writestr('hasone-ios-simulator.tar.gz',b'unreviewed')
        self.listing['artifacts'][1]=self.artifact(self.ios,ARTIFACTS[1],13106285302)
        with self.assertRaises(BundleError):self.make()
        self.assertFalse((self.root/'bundle').exists())

    def test_unknown_profile_rejects_before_output(self):
        with self.assertRaises(BundleError):
            verify_workflow(self.run,self.listing,SHA,RUN,self.android,self.ios,profile='unreviewed')
        run,listing=self.snapshots()
        with self.assertRaises(BundleError):
            package_from_workflow(self.root/'bundle',SHA,RUN,run,listing,
                                  self.android,self.ios,profile='unreviewed')

    def test_cli_source_guard_and_private_nonoverwriting_output(self):
        run,listing=self.snapshots()
        args=['--output',str(self.root/'bundle'),'--source-sha',SHA,
              '--run-id',str(RUN),'--run-json',str(run),'--artifacts-json',str(listing),
              '--android-wrapper',str(self.android),'--ios-wrapper',str(self.ios)]
        self.assertEqual(main(args),0)
        self.assertEqual(main(args),1)
        self.assertFalse(verify(self.root/'bundle',SHA)['acceptance']['signed_for_store'])
        args[args.index('--source-sha')+1]='f'*40
        self.assertEqual(main(args),1)

    def test_source_aligned_stage_report_labels_native_developer_scope(self):
        import datetime as dt
        from delivery_source_alignment import (CHECKS, EXPECTED_ROUTES,
                                               verify_alignment)
        now=dt.datetime.now(dt.timezone.utc)
        self.make()
        stage=self.root/'source-linked-stage.json'
        report={
            'schema':'ev-private-source-linked-staging-acceptance-v1',
            'status':'PRIVATE_SOURCE_LINKED_STAGE_READY','source_sha':SHA,
            'observed_from_utc':(now-dt.timedelta(minutes=2)).isoformat(timespec='milliseconds').replace('+00:00','Z'),
            'observed_until_utc':(now-dt.timedelta(minutes=1)).isoformat(timespec='milliseconds').replace('+00:00','Z'),
            'checks':{x:True for x in CHECKS},
            'runtime_fingerprint_sha256':'a'*64,
            'http_routes':[{'route':path,'passed':True,'status':401 if i>=4 else 200}
                           for i,path in enumerate(EXPECTED_ROUTES)],
            'image_source_label_self_declared':True,
            'independent_build_attestation':False,
            'synthetic_accounts_authenticated':False,
            'simulator_session_completed':False,'live_charger_verified':False,
            'live_ocpi_verified':False,'real_money_moved':False,
            'production_accepted':False,'customer_contract_accepted':False
        }
        stage.write_text(json.dumps(report))
        stage.chmod(0o600)
        summary=verify_alignment(stage,self.root/'bundle',SHA,now)
        self.assertEqual(summary['mobile_artifact_scope'],
                         'EV_SHARED_CUSTOMER_VENDOR_DEVELOPER_REFERENCE_ONLY')
        self.assertEqual(summary['mobile_workflow_name'],'Native client validation')
        self.assertTrue(summary['shared_customer_vendor_developer_bundle_verified'])
        self.assertFalse(summary['mobile_backend_origin_matches_staging_verified'])
        self.assertFalse(summary['customer_native_authenticated_journeys_verified'])
        self.assertFalse(summary['vendor_android_ios_artifacts_verified'])
        self.assertFalse(summary['production_accepted'])

    def test_wrong_commit_is_not_accepted_even_with_valid_artifact_bytes(self):
        with self.assertRaises(BundleError):
            verify_workflow(self.run,self.listing,'f'*40,RUN,self.android,self.ios,profile='ev_shared_native')


if __name__=='__main__':
    unittest.main()
