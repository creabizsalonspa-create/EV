"""Progress evidence must fail closed; tests never invent feature acceptance."""
import json
import os
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


    def test_checkpoint_evidence_outside_repository_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = Path(directory) / 'fake-hosted-checkpoint.json'
            checkpoint = self.inventory['checkpoints']['pr14']
            evidence.write_text(json.dumps({
                'source_sha': checkpoint['source_sha'],
                'integrated_sha': checkpoint['merge_sha'],
            }))
            checkpoint['evidence'] = str(evidence)
            self.rejected(self.inventory, 'evidence must stay within repository')

    def test_relative_checkpoint_escape_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = Path(directory) / 'fake-checkpoint.json'
            checkpoint = self.inventory['checkpoints']['pr14']
            evidence.write_text(json.dumps({
                'source_sha': checkpoint['source_sha'],
                'integrated_sha': checkpoint['merge_sha'],
            }))
            checkpoint['evidence'] = os.path.relpath(evidence, ROOT)
            self.rejected(self.inventory, 'evidence must stay within repository')

    def test_original_312_atom_inventory_cannot_silently_shrink(self):
        self.inventory['categories'][0]['atoms'].pop()
        self.rejected(self.inventory, 'Original 312 requirement atoms are mandatory')


    def test_category_rebalance_cannot_change_weighted_denominators(self):
        # Move an item while retaining 312 distinct IDs. Total-size checks
        # alone permit such a change to category weights.
        moved = self.inventory['categories'][0]['atoms'].pop()
        moved['id'] = '2.2.8'
        self.inventory['categories'][1]['atoms'].append(moved)
        self.rejected(self.inventory, 'Original category atom count changed')

    def test_original_atom_identifier_cannot_be_renumbered(self):
        self.inventory['categories'][0]['atoms'][0]['id'] = '2.1.99'
        self.rejected(self.inventory, 'Original atom IDs/order changed')

    def test_atom_reordering_requires_explicit_inventory_review(self):
        atoms = self.inventory['categories'][0]['atoms']
        atoms[0], atoms[1] = atoms[1], atoms[0]
        self.rejected(self.inventory, 'Original atom IDs/order changed')


if __name__ == '__main__':
    unittest.main()
