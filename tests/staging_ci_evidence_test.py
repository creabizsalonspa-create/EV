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

    def private_cli_args(self, output):
        rp, jp = self.folder/'run.json', self.folder/'jobs.json'
        for name, data in ((rp, self.run), (jp, self.jobs)):
            name.write_text(json.dumps(data))
            name.chmod(0o600)
        return ['--run-json', str(rp), '--jobs-json', str(jp),
                '--source-sha', SHA, '--output', str(output)]

    def test_partial_json_write_never_publishes_report(self):
        output = self.folder/'partial-report.json'
        args = self.private_cli_args(output)

        def interrupted_dump(payload, stream, **kwargs):
            stream.write('{"partial":"never publish"')
            raise OSError('synthetic interrupted write')

        with patch('staging_ci_evidence.json.dump', side_effect=interrupted_dump):
            self.assertEqual(main(args), 1)
        self.assertFalse(output.exists())
        self.assertEqual(list(self.folder.glob('.ev-ci-report-*.tmp')), [])

    def test_complete_private_json_exists_before_atomic_publication(self):
        output = self.folder/'full-report.json'
        args = self.private_cli_args(output)
        real_link = os.link
        inspections = []

        def inspect_link(source, destination, **kwargs):
            source_stat = os.stat(source, dir_fd=kwargs['src_dir_fd'],
                                  follow_symlinks=False)
            inspections.append(source_stat.st_mode & 0o777)
            with os.fdopen(os.open(source, os.O_RDONLY,
                                   dir_fd=kwargs['src_dir_fd']), 'r') as file:
                payload = json.load(file)
            self.assertFalse(output.exists())
            self.assertEqual(destination, output.name)
            self.assertFalse(payload['production_accepted'])
            self.assertFalse(payload['github_live_status_independently_confirmed'])
            return real_link(source, destination, **kwargs)

        with patch('staging_ci_evidence.os.link', side_effect=inspect_link):
            self.assertEqual(main(args), 0)
        self.assertEqual(inspections, [0o600])
        self.assertTrue(output.is_file())
        self.assertEqual(list(self.folder.glob('.ev-ci-report-*.tmp')), [])

    def test_existing_report_or_symlink_is_not_overwritten(self):
        output = self.folder/'existing-report.json'
        args = self.private_cli_args(output)
        sentinel = self.folder/'sentinel.json'
        sentinel.write_text('must remain unchanged')
        sentinel.chmod(0o600)
        output.symlink_to(sentinel)
        self.assertEqual(main(args), 1)
        self.assertEqual(sentinel.read_text(), 'must remain unchanged')
        self.assertTrue(output.is_symlink())
        self.assertEqual(list(self.folder.glob('.ev-ci-report-*.tmp')), [])

    def test_failed_atomic_publication_cleans_private_temporary_file(self):
        output = self.folder/'failed-report.json'
        args = self.private_cli_args(output)
        with patch('staging_ci_evidence.os.link', side_effect=OSError('no free inodes')):
            self.assertEqual(main(args), 1)
        self.assertFalse(output.exists())
        self.assertEqual(list(self.folder.glob('.ev-ci-report-*.tmp')), [])

    def test_parent_replacement_during_report_write_cannot_publish(self):
        parent = self.folder/'private'
        parent.mkdir(mode=0o700)
        output = parent/'report.json'
        args = self.private_cli_args(output)
        real_dump = json.dump

        def swap_parent(payload, stream, **kwargs):
            real_dump(payload, stream, **kwargs)
            moved = self.folder/'original-private'
            parent.rename(moved)
            parent.symlink_to(moved, target_is_directory=True)

        with patch('staging_ci_evidence.json.dump', side_effect=swap_parent):
            self.assertEqual(main(args), 1)
        self.assertFalse((self.folder/'original-private'/'report.json').exists())
        self.assertEqual(list((self.folder/'original-private').glob('.ev-ci-report-*.tmp')), [])

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
