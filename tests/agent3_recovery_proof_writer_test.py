"""Atomic recovery proof publishing never exposes half-written PASS files."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    'recovery_proof_writer', ROOT / 'scripts/recovery_proof_writer.py')
writer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(writer)


class ProofWriterTest(unittest.TestCase):
    def test_published_evidence_is_private_complete_and_no_temp_remains(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'nested' / 'validation.json'
            writer.publish(path, {'status': 'PASS', 'production_ready': False})
            self.assertEqual(json.loads(path.read_text())['status'], 'PASS')
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            self.assertEqual(list(path.parent.glob('*.pending')), [])
            self.assertEqual(list(path.parent.glob('.*.pending')), [])

    def test_interrupted_atomic_replace_erases_old_pass_and_partial_file(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'validation.json'
            path.write_text('{"status":"PASS","old":true}')
            with patch.object(writer.os, 'replace',
                              side_effect=OSError('synthetic crash')):
                with self.assertRaises(OSError):
                    writer.publish(path, {'status': 'PASS', 'new': True})
            self.assertFalse(path.exists())
            self.assertEqual(list(Path(d).glob('.*.pending')), [])

    def test_oversized_and_nonfinite_artifact_never_write_a_success(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'validation.json'
            for value in ('x'*65536, float('nan'), float('inf')):
                path.write_text('{"status":"PASS","old":true}')
                with self.assertRaises(ValueError):
                    writer.publish(path, {'value': value})
                self.assertFalse(path.exists())
            self.assertFalse(list(Path(d).glob('.*.pending')))

    def test_new_publish_replaces_existing_proof_atomically(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'validation.json'
            writer.publish(path, {'status': 'PASS', 'generation': 1})
            writer.publish(path, {'status': 'PASS', 'generation': 2})
            self.assertEqual(json.loads(path.read_text())['generation'], 2)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)


if __name__ == '__main__':
    unittest.main()
