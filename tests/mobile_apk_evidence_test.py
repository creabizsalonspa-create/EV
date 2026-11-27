import sys
from pathlib import Path
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from mobile_apk_evidence import EvidenceError, digest, verified_snapshot, verify_backup_resource_refs, verify_single_signer


class MobileApkEvidenceTest(unittest.TestCase):
    def test_snapshot_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'release.apk'
            source.write_bytes(b'original')
            with verified_snapshot(source) as (copy, sha):
                self.assertEqual(sha, digest(source))
                self.assertEqual(copy.read_bytes(), source.read_bytes())
            self.assertFalse(copy.exists())
            for change_original in (True, False):
                with self.subTest(change_original=change_original), self.assertRaises(EvidenceError):
                    with verified_snapshot(source) as (copy, _):
                        (source if change_original else copy).write_bytes(b'mutated' if change_original else b'other')

    def test_manifest_refs(self):
        manifest = 'A: android:fullBackupContent(0x010104eb)=@0x7f140000\nA: android:dataExtractionRules(0x0101063e)=@0x7f140001'
        resources = 'spec resource 0x7f140000 com.example.ev:xml/ev_backup_rules: flags=0x0\nspec resource 0x7f140001 com.example.ev:xml/ev_data_extraction_rules: flags=0x0'
        verify_backup_resource_refs(manifest, resources, 'com.example.ev')
        for bad in (resources.replace('ev_backup_rules', 'other'), resources.replace('com.example.ev', 'foreign.app'), resources + '\n' + resources):
            with self.subTest(bad=bad), self.assertRaises(EvidenceError):
                verify_backup_resource_refs(manifest, bad, 'com.example.ev')
        with self.assertRaises(EvidenceError):
            verify_backup_resource_refs(manifest.replace('0x7f140001', '0x7f140000'), resources, 'com.example.ev')

    def test_signer(self):
        good = 'Signer #1 certificate SHA-256 digest: ' + 'a' * 64
        self.assertEqual('a' * 64, verify_single_signer(good, 'A' * 64))
        for bad in (good + '\nSigner #2 certificate SHA-256 digest: ' + 'b' * 64, good + '\n' + good, good + '\nNumber of signers: 2', good.replace('a' * 64, 'b' * 64)):
            with self.subTest(bad=bad), self.assertRaises(EvidenceError):
                verify_single_signer(bad, 'a' * 64)


if __name__ == '__main__':
    unittest.main()
