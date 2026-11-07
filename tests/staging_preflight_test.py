"""Regression coverage for the non-destructive staging configuration gate."""

import base64
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "staging_preflight.py"


class StagingPreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.env_file = Path(self.temp.name) / ".env"
        self.values = {
            "APP_KEY": "base64:" + base64.b64encode(b"K" * 32).decode(),
            "STAGING_HOST": "staging.ev.example.org",
            "ACME_EMAIL": "operations@example.org",
            "DB_PASSWORD": "synthetic-unique-db-credential-1234",
            "STAGING_BIND_IP": "127.0.0.1",
            "EV_IMAGE_TAG": "commit-abc123",
        }

    def run_gate(self, *args):
        self.env_file.write_text("".join(f"{k}={v}\n" for k, v in self.values.items()))
        self.env_file.chmod(0o600)
        return subprocess.run([sys.executable, str(SCRIPT), "--env", str(self.env_file), *args], text=True, capture_output=True, timeout=10)

    def test_private_configuration_passes(self):
        result = self.run_gate()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("no deployment performed", result.stdout)
        self.assertNotIn(self.values["APP_KEY"], result.stdout)

    def test_missing_laravel_key_fails_without_exposing_db_password(self):
        self.values["APP_KEY"] = ""
        result = self.run_gate()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("APP_KEY", result.stderr)
        self.assertNotIn(self.values["DB_PASSWORD"], result.stderr)

    def test_invalid_decoded_key_size_fails(self):
        self.values["APP_KEY"] = "base64:" + base64.b64encode(b"short").decode()
        self.assertNotEqual(self.run_gate().returncode, 0)

    def test_public_binding_requires_explicit_override(self):
        self.values["STAGING_BIND_IP"] = "0.0.0.0"
        self.assertNotEqual(self.run_gate().returncode, 0)
        self.assertEqual(self.run_gate("--allow-public-bind").returncode, 0)

    def test_rejects_placeholder_host(self):
        self.values["STAGING_HOST"] = "localhost"
        self.assertNotEqual(self.run_gate().returncode, 0)

    def test_rejects_invalid_image_tag(self):
        self.values["EV_IMAGE_TAG"] = "demo:latest"
        self.assertNotEqual(self.run_gate().returncode, 0)

    def test_rejects_duplicate_keys(self):
        self.run_gate()
        with self.env_file.open("a") as file:
            file.write("DB_PASSWORD=another-value\n")
        result = subprocess.run([sys.executable, str(SCRIPT), "--env", str(self.env_file)], text=True, capture_output=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Duplicate", result.stderr)
        self.assertNotIn(self.values["DB_PASSWORD"], result.stderr)

    def test_rejects_publicly_readable_file(self):
        self.run_gate()
        self.env_file.chmod(0o644)
        result = subprocess.run([sys.executable, str(SCRIPT), "--env", str(self.env_file)], text=True, capture_output=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)

    def test_rejects_symlink(self):
        self.run_gate()
        alias = Path(self.temp.name) / "alias.env"
        alias.symlink_to(self.env_file)
        result = subprocess.run([sys.executable, str(SCRIPT), "--env", str(alias)], text=True, capture_output=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
