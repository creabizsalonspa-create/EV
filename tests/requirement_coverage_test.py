"""Progress evidence must fail closed; tests never invent feature acceptance."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class CoverageEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.inventory = json.loads((ROOT / 'docs/evidence/requirements/inventory.json').read_text())

    def rejected(self, proposed, message):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'inventory.json'
            path.write_text(json.dumps(proposed))
            result = subprocess.run(['python3', str(ROOT / 'scripts/requirement-coverage'), '--inventory', str(path), '--check'], cwd=ROOT, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn(message, result.stderr)

    def test_all_original_categories_are_mandatory(self):
        self.inventory['categories'].pop()
        self.rejected(self.inventory, 'All42 original categories')

    def test_wrong_source_cannot_reuse_green_evidence(self):
        self.inventory['checkpoints']['pr14']['source_sha'] = '1' * 40
        self.rejected(self.inventory, 'wrong source evidence')

    def test_pending_tests_cannot_count_as_verified_merge(self):
        self.inventory['checkpoints']['pr14']['test_result'] = 'pending'
        self.rejected(self.inventory, 'pr14')

    def test_production_acceptance_is_never_inferred(self):
        self.inventory['production_acceptance'] = True
        self.rejected(self.inventory, 'Production acceptance requires')

    def test_duplicate_atomic_ids_are_rejected(self):
        atoms = self.inventory['categories'][0]['atoms']
        atoms[1]['id'] = atoms[0]['id']
        self.rejected(self.inventory, 'AssertionError')


if __name__ == '__main__':
    unittest.main()
