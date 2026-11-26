"""Regression gate for isolated CSMS charging GraphQL readiness before OCPP QA.

The expanded 1.6/2.0.1 model/fault matrix reads the local Hasura source.
Merely starting the OCPP gateway does not create the private charging/.env.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class OcppSetupOrderTest(unittest.TestCase):
    def test_graphql_setup_precedes_identity_fault_matrix(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        steps = [
            "      - run: ./scripts/csms-setup",
            "      - run: ./scripts/csms-start",
            "      - run: ./scripts/charging-setup",
            "      - run: npm run test:ocpp",
            "      - run: ./scripts/charging-bridge-setup",
        ]
        positions = [workflow.index(step) for step in steps]
        self.assertEqual(positions, sorted(positions))
        self.assertEqual(workflow.count("      - run: ./scripts/charging-setup"), 1)

    def test_source_uses_private_local_graphql_not_untrusted_network(self):
        charging = (ROOT / "scripts/charging-setup").read_text()
        helper = (ROOT / "tests/helpers/csms-local.cjs").read_text()
        suite = (ROOT / "tests/ocpp.cjs").read_text()
        self.assertIn("services/csms/charging/.env", charging)
        self.assertIn("CHARGING_HASURA_ADMIN_SECRET", charging)
        self.assertIn('127.0.0.1:8095/v1/graphql', helper)
        self.assertIn('const { graph, poll } = require("./helpers/csms-local.cjs");', suite)
        self.assertIn("Only generated local simulator identifiers are permitted", helper)
        self.assertIn("physical_hardware_accepted: false", suite)


if __name__ == "__main__":
    unittest.main()
