import copy
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from staging_ci_evidence import EvidenceError, EXPECTED, verify, private_json, main
SHA='a'*40
RID=42430250971
REPO='creabizsalonspa-create/EV'

class PlatformGateTests(unittest.TestCase):
    def setUp(self):
        temp=tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.folder=Path(temp.name)
        self.run={'id':RID,'run_attempt':1,'workflow_id':397762279,'name':'Platform validation','path':'.github/workflows/ci.yml','head_sha':SHA,'status':'completed','conclusion':'success','event':'pull_request',
            'repository':{'full_name':REPO,'id':1435146528},'head_repository':{'full_name':REPO,'id':1435146528}}
        self.jobs={'total_count':4,'jobs':[{'id':100+i,'name':name,'run_id':RID,'run_attempt':1,'head_sha':SHA,'workflow_name':'Platform validation','status':'completed','conclusion':'success'} for i,name in enumerate(sorted(EXPECTED))]}
    def test_happy_case_is_explicitly_not_independent_attestation(self):
        out=verify(self.run,self.jobs,SHA)
        self.assertEqual(out['successful_required_jobs'],sorted(EXPECTED))
        self.assertFalse(out['github_live_status_independently_confirmed'])
        self.assertFalse(out['staging_deployed'])
        self.assertFalse(out['production_accepted'])
    def test_current_queued_ci_must_fail(self):
        for status,conclusion in [('queued',None),('in_progress',None),('completed','failure'),('completed','cancelled'),('completed','skipped')]:
            with self.subTest(status=status):
                with self.assertRaises(EvidenceError):verify(dict(self.run,status=status,conclusion=conclusion),self.jobs,SHA)
    def test_mismatched_sha_or_repository_rejected(self):
        with self.assertRaises(EvidenceError):verify(self.run,self.jobs,'b'*40)
        with self.assertRaises(EvidenceError):verify(dict(self.run,head_repository={'full_name':'fork/EV','id':1}),self.jobs,SHA)
        with self.assertRaises(EvidenceError):verify(dict(self.run,workflow_id=123),self.jobs,SHA)
    def test_job_failures_or_replaced_run_rejected(self):
        for field,value in [('status','queued'),('conclusion','failure'),('conclusion','skipped'),('run_id',RID+1),('run_attempt',2),('head_sha','f'*40),('workflow_name','Other')]:
            bad=copy.deepcopy(self.jobs)
            bad['jobs'][0][field]=value
            with self.subTest(field=field),self.assertRaises(EvidenceError):verify(self.run,bad,SHA)
    def test_pagination_duplicates_extra_jobs_rejected(self):
        for tweak in ('missing','extra','duplicate','total','duplicateid'):
            bad=copy.deepcopy(self.jobs)
            if tweak=='missing':bad['jobs'].pop()
            if tweak=='extra':bad['jobs'].append(dict(bad['jobs'][0],id=999,name='unreviewed'))
            if tweak=='duplicate':bad['jobs'][1]['name']=bad['jobs'][0]['name']
            if tweak=='total':bad['total_count']=5
            if tweak=='duplicateid':bad['jobs'][1]['id']=bad['jobs'][0]['id']
            with self.subTest(tweak=tweak),self.assertRaises(EvidenceError):verify(self.run,bad,SHA)
    def test_snapshot_must_be_private_regular_unique_json(self):
        path=self.folder/'run.json'
        path.write_text(json.dumps(self.run));path.chmod(0o600)
        self.assertEqual(private_json(path)[0]['id'],RID)
        for data in ('{"id":1,"id":2}','{"number":NaN}','["not an object"]'):
            path.write_text(data)
            with self.assertRaises(EvidenceError):private_json(path)
        path.write_text(json.dumps(self.run));path.chmod(0o644)
        with self.assertRaises(EvidenceError):private_json(path)
        path.chmod(0o600)
        alias=self.folder/'alias.json';alias.symlink_to(path)
        with self.assertRaises(EvidenceError):private_json(alias)
        fifo=self.folder/'fifo.json';os.mkfifo(fifo)
        with self.assertRaises(EvidenceError):private_json(fifo)
    def test_workflow_numeric_identity_rejects_equivalent_float(self):
        bad=dict(self.run,workflow_id=float(self.run['workflow_id']))
        with self.assertRaises(EvidenceError):verify(bad,self.jobs,SHA)

    def test_head_repository_id_rejects_equivalent_float(self):
        bad=copy.deepcopy(self.run)
        bad['head_repository']['id']=float(bad['repository']['id'])
        with self.assertRaises(EvidenceError):verify(bad,self.jobs,SHA)

    def test_job_run_id_rejects_equivalent_float(self):
        bad=copy.deepcopy(self.jobs)
        bad['jobs'][0]['run_id']=float(RID)
        with self.assertRaises(EvidenceError):verify(self.run,bad,SHA)

    def test_job_attempt_rejects_boolean_true(self):
        bad=copy.deepcopy(self.jobs)
        bad['jobs'][0]['run_attempt']=True
        with self.assertRaises(EvidenceError):verify(self.run,bad,SHA)

    def test_snapshot_refuses_a_hardlinked_metadata_file(self):
        path=self.folder/'run.json'
        path.write_text(json.dumps(self.run));path.chmod(0o600)
        os.link(path,self.folder/'other-run.json')
        with self.assertRaisesRegex(EvidenceError,'single-link'):
            private_json(path)

    def test_snapshot_refuses_symlinked_grandparent_directory(self):
        real=self.folder/'real'/'private'
        real.mkdir(parents=True,mode=0o700)
        file=real/'run.json'
        file.write_text(json.dumps(self.run));file.chmod(0o600)
        alias=self.folder/'alias'
        alias.symlink_to(self.folder/'real',target_is_directory=True)
        with self.assertRaisesRegex(EvidenceError,'symlinked ancestor'):
            private_json(alias/'private'/'run.json')

    def test_snapshot_refuses_same_content_inode_replacement_during_read(self):
        path=self.folder/'run.json'
        path.write_text(json.dumps(self.run));path.chmod(0o600)
        original=os.read
        def swap(fd,size):
            data=original(fd,size)
            replacement=self.folder/'replacement.json'
            replacement.write_bytes(data);replacement.chmod(0o600)
            replacement.replace(path)
            return data
        with patch('staging_ci_evidence.os.read',side_effect=swap):
            with self.assertRaisesRegex(EvidenceError,'changed during read'):
                private_json(path)

    def test_snapshot_refuses_data_edit_during_read(self):
        path=self.folder/'run.json'
        path.write_text(json.dumps(self.run));path.chmod(0o600)
        original=os.read
        def edit(fd,size):
            data=original(fd,size)
            path.write_bytes(data+b' ')
            path.chmod(0o600)
            return data
        with patch('staging_ci_evidence.os.read',side_effect=edit):
            with self.assertRaisesRegex(EvidenceError,'changed during read'):
                private_json(path)

    def test_snapshot_refuses_parent_replaced_with_symlink_during_read(self):
        parent=self.folder/'private'
        parent.mkdir(mode=0o700)
        path=parent/'run.json'
        path.write_text(json.dumps(self.run));path.chmod(0o600)
        original=os.read
        def swap_parent(fd,size):
            data=original(fd,size)
            moved=self.folder/'previous'
            parent.rename(moved)
            parent.symlink_to(moved,target_is_directory=True)
            return data
        with patch('staging_ci_evidence.os.read',side_effect=swap_parent):
            with self.assertRaisesRegex(EvidenceError,'symlinked ancestor'):
                private_json(path)

    def test_cli_writes_private_new_nonoverwriting_report(self):
        rp, jp, output=[self.folder/x for x in ('run.json','jobs.json','output.json')]
        for path, obj in ((rp,self.run),(jp,self.jobs)):
            path.write_text(json.dumps(obj));path.chmod(0o600)
        args=['--run-json',str(rp),'--jobs-json',str(jp),'--source-sha',SHA,'--output',str(output)]
        self.assertEqual(main(args),0)
        self.assertEqual(output.stat().st_mode & 0o077,0)
        self.assertEqual(main(args),1)
        self.run['status']='queued';rp.write_text(json.dumps(self.run))
        with self.assertRaises(EvidenceError):verify(self.run,self.jobs,SHA)

if __name__=='__main__':unittest.main()
