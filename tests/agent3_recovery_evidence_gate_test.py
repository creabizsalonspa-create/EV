"""Agent 3 strict same-run recovery-evidence negative tests."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('recover_gate',
    ROOT / 'scripts/recovery-evidence-gate.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
SHA = 'c'*40
PROVENANCE = {'source_sha': SHA, 'workflow_run_id': '1234567',
              'workflow_run_attempt': '1'}

def evidence():
    business = {
        **PROVENANCE, 'status': 'PASS',
        'classification': 'disposable staging software only',
        'dump_sha256': 'a'*64,
        'synthetic_private_archive_sha256': 'b'*64,
        'migration_history_md5': 'c'*32,
        'synthetic_data_md5': 'd'*32,
        'laravel_migration_rows': 23,
        'restored_http_catalog': '200 and empty',
        'external_acceptance': 'not tested',
        'laravel_encrypted_record_restored': True,
        'wrong_laravel_key_rejected': True,
        'synthetic_private_file_restored': True,
        'post_snapshot_write_excluded': True,
        'post_snapshot_private_write_excluded': True,
        'archive_tamper_rejected': True,
        'single_transaction_restore': True,
        'isolated_runtime_verified': True,
        'private_archive_tamper_rejected': True,
        'queue_worker_started': True,
        'scheduler_bootstrapped': True,
    }
    csms = {
        **PROVENANCE, 'status': 'PASS',
        'classification': 'disposable synthetic PostGIS recovery, not CitrineOS acceptance',
        'database_archive_sha256': 'e'*64,
        'synthetic_geospatial_data_md5': 'f'*32,
        'recovered_rows': 2,
        'srid': 4326,
        'post_snapshot_write_excluded': True,
        'real_citrineos_schema_restored': False,
        'rabbitmq_broker_restored': False,
        'physical_charger_or_money': False,
        'archive_tamper_rejected': True,
        'single_transaction_restore': True,
        'isolated_runtime_verified': True,
    }
    return business, csms

class GateTest(unittest.TestCase):
    def test_success_is_never_production_acceptance(self):
        result=gate.inspect_proofs(*evidence(),SHA,'1234567','1')
        self.assertEqual(result['status'],'PASS')
        self.assertFalse(result['production_ready'])
        self.assertFalse(result['cross_service_consistent_snapshot_proven'])

    def test_business_negative_states(self):
        cases=[
            ('status','FAIL'),('source_sha','f'*40),
            ('workflow_run_id','1234568'),('workflow_run_attempt','2'),
            ('dump_sha256','invalid'),('synthetic_private_archive_sha256',None),
            ('migration_history_md5','wrong'),('laravel_migration_rows',True),
            ('laravel_migration_rows',0),('laravel_encrypted_record_restored',False),
            ('wrong_laravel_key_rejected',False),
            ('synthetic_private_file_restored',False),
            ('post_snapshot_write_excluded',False),
            ('post_snapshot_private_write_excluded',False),
            ('archive_tamper_rejected',False),
            ('single_transaction_restore',False),
            ('isolated_runtime_verified',False),
            ('private_archive_tamper_rejected',False),
            ('queue_worker_started',False),
            ('scheduler_bootstrapped',False),('external_acceptance','yes'),
        ]
        for field,value in cases:
            with self.subTest(field=field,value=value):
                b,c=evidence()
                b[field]=value
                with self.assertRaises(gate.EvidenceError):
                    gate.inspect_proofs(b,c,SHA,'1234567','1')

    def test_csms_negative_states(self):
        cases=[
            ('status','PENDING'),('source_sha','f'*40),
            ('workflow_run_id','99'),('workflow_run_attempt','3'),
            ('database_archive_sha256','invalid'),
            ('synthetic_geospatial_data_md5','wrong'),
            ('recovered_rows',True),('recovered_rows',3),('srid',0),
            ('post_snapshot_write_excluded',False),
            ('real_citrineos_schema_restored',True),('rabbitmq_broker_restored',True),
            ('physical_charger_or_money',True),
            ('archive_tamper_rejected',False),
            ('single_transaction_restore',False),
            ('isolated_runtime_verified',False),
        ]
        for field,value in cases:
            with self.subTest(field=field,value=value):
                b,c=evidence()
                c[field]=value
                with self.assertRaises(gate.EvidenceError):
                    gate.inspect_proofs(b,c,SHA,'1234567','1')

    def test_bounded_json_and_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            file=Path(d)/'proof.json'
            file.write_text('not-json')
            with self.assertRaises(gate.EvidenceError):
                gate.read_proof(file)
            link=Path(d)/'link.json'
            link.symlink_to(file)
            with self.assertRaises(gate.EvidenceError):
                gate.read_proof(link)

    def test_symlink_substituted_at_open_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            candidate = root / 'proof.json'
            original = root / 'original.json'
            candidate.write_text('{"status":"PASS"}')
            real_open = gate.os.open
            swapped = False

            def swap_before_open(path, flags, *args, **kwargs):
                nonlocal swapped
                if str(path) == str(candidate) and not swapped:
                    candidate.rename(original)
                    candidate.symlink_to(original)
                    swapped = True
                return real_open(path, flags, *args, **kwargs)

            with patch.object(gate.os, 'open', side_effect=swap_before_open):
                with self.assertRaises(gate.EvidenceError):
                    gate.read_proof(candidate)
            self.assertTrue(swapped)

    def test_oversized_proof_is_rejected_without_parsing(self):
        with tempfile.TemporaryDirectory() as d:
            proof = Path(d) / 'huge.json'
            proof.write_bytes(b'{' + b' ' * 65536 + b'}')
            with self.assertRaises(gate.EvidenceError):
                gate.read_proof(proof)

    def test_failing_rerun_removes_old_pass(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            output=root/'result.json'
            output.write_text('{"status":"PASS"}')
            code=gate.main(['--business',str(root/'missing.json'),
                            '--csms',str(root/'missing2.json'),
                            '--sha',SHA,'--run-id','1234567',
                            '--attempt','1','--output',str(output)])
            self.assertEqual(code,1)
            self.assertFalse(output.exists())

    def test_pass_file_generation(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            b,c=evidence()
            (root/'b.json').write_text(json.dumps(b))
            (root/'c.json').write_text(json.dumps(c))
            result=root/'result.json'
            code=gate.main(['--business',str(root/'b.json'),
                            '--csms',str(root/'c.json'),
                            '--sha',SHA,'--run-id','1234567',
                            '--attempt','1','--output',str(result)])
            self.assertEqual(code,0)
            self.assertFalse(json.loads(result.read_text())['production_ready'])

if __name__=='__main__':
    unittest.main()
