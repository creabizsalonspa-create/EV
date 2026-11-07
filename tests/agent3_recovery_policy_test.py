"""Structural safety checks; the hosted Docker drill is the runtime acceptance test."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "staging-recovery-smoke"


class Agent3RecoveryPolicyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SCRIPT.read_text(encoding="utf-8")

    def test_script_parses_as_bash(self):
        subprocess.run(["bash", "-n", str(SCRIPT)], check=True, timeout=10)

    def test_only_run_owned_disposable_resources_are_removed(self):
        code = self.source
        self.assertIn('review_id="ev-recovery-$RANDOM-$$"', code)
        self.assertIn('scratch=$(mktemp -d /tmp/ev-recovery.XXXXXXXX)', code)
        self.assertIn("trap cleanup EXIT", code)
        self.assertIn('rm -f -- "$root/artifacts/agent3-recovery/validation.json"', code)
        self.assertIn('docker rm -fv "$id"', code)
        self.assertIn('docker network rm "$id"', code)
        self.assertNotRegex(code, r"docker\s+(?:system|volume|image)\s+prune")
        self.assertNotIn("docker volume rm", code)
        self.assertNotIn("docker rm -f $(docker", code)

    def test_applications_cannot_reach_external_networks_or_host_ports(self):
        code = self.source
        self.assertEqual(code.count("docker network create --internal"), 2)
        self.assertEqual(code.count("--network \"$source_net\""), 2)
        self.assertEqual(code.count("--network \"$target_net\""), 2)
        self.assertNotIn("--network host", code)
        self.assertNotIn("--privileged", code)
        self.assertNotIn("--mount type=bind", code)
        self.assertNotRegex(code, r"docker run [^\n]*(?: -p | --publish )")
        for flag in (
            "CSMS_SIMULATOR_ENABLED",
            "CSMS_COMMAND_WORKER_ENABLED",
            "CSMS_REMOTE_AUTHORIZATION_ENABLED",
            "OCPI_TEST_COMMANDS_ENABLED",
            "SUBSCRIPTION_COLLECTION_ENABLED",
            "PAYOUT_TEST_ENABLED",
            "EV_BATTERY_SIMULATOR_ENABLED",
        ):
            self.assertIn("'" + flag + "': 'false'", code)

    def test_postgres_and_recovery_fingerprint_gates(self):
        code = self.source
        self.assertRegex(code, r"postgres:17-alpine@sha256:[0-9a-f]{64}")
        for required in (
            "pg_dump -U ev_recovery_review",
            "--format=custom --no-owner --no-privileges",
            "pg_restore -U ev_recovery_review",
            "--clean --if-exists --no-owner --no-privileges --exit-on-error",
            "migration_fingerprint",
            "probe_fingerprint",
            "php artisan migrate:status",
            "restored_http_catalog",
            "private-probe.tar.gz",
            "synthetic_private_archive_sha256",
            "PHP_ENCRYPT",
            "PHP_DECRYPT",
            "Crypt::encryptString",
            "Crypt::decryptString",
            "Recovered APP_KEY could not decrypt source data.",
            "fileperms($p) & 0777",
            "classification': 'disposable staging software only'",
        ):
            self.assertIn(required, code)

    def test_no_sensitive_data_in_artifact_schema(self):
        code = self.source
        artifact = code.split("record = {", 1)[1].split("\n}", 1)[0]
        self.assertNotRegex(artifact, re.compile(r"password|secret|app_key|token", re.I))
        self.assertIn("dump_sha256", artifact)
        self.assertIn("laravel_migration_rows", artifact)
        self.assertIn("synthetic_private_archive_sha256", artifact)


if __name__ == "__main__":
    unittest.main()
