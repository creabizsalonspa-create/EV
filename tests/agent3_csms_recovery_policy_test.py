"""Agent 3 synthetic CitrineOS PostGIS recovery policy checks."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "csms-recovery-smoke"


class CsmsRecoveryPolicyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SCRIPT.read_text(encoding="utf-8")

    def test_bash_syntax(self):
        subprocess.run(["bash", "-n", str(SCRIPT)], check=True, timeout=10)

    def test_exactly_pinned_existing_csms_postgis_image(self):
        compose = (ROOT / "services" / "csms" / "compose.yaml").read_text()
        match = re.search(r"postgis/postgis:16-3\.5@sha256:[0-9a-f]{64}", compose)
        self.assertIsNotNone(match)
        self.assertIn(match.group(0), self.source)

    def test_isolation_and_cleanup(self):
        code = self.source
        self.assertIn('review_id="ev-csms-recovery-$RANDOM-$$"', code)
        self.assertIn("scratch=$(mktemp -d /tmp/ev-csms-recovery.XXXXXXXX)", code)
        self.assertEqual(code.count("docker network create --internal"), 2)
        self.assertIn('docker rm -fv "$id"', code)
        self.assertIn('docker network rm "$id"', code)
        self.assertIn("trap cleanup EXIT", code)
        self.assertIn('rm -f -- "$root/artifacts/agent3-csms-recovery/validation.json"', code)
        self.assertNotIn("--network host", code)
        self.assertNotIn("--privileged", code)
        self.assertNotIn("--publish", code)
        self.assertNotRegex(code, r"docker\s+(?:system|image|volume)\s+prune")
        self.assertNotIn("docker volume rm", code)
        self.assertNotIn("--mount type=bind", code)
        self.assertNotIn("docker compose down", code)
        self.assertNotIn("docker volume prune", code)

    def test_geospatial_proof_and_point_in_time_boundary(self):
        code = self.source
        for guard in (
            "CREATE EXTENSION IF NOT EXISTS postgis",
            "geometry(Point,4326)",
            "ST_SetSRID",
            "ST_SRID",
            "--format=custom --no-owner --no-privileges",
            "--clean --if-exists --no-owner --no-privileges --exit-on-error",
            "post-snapshot",
            "source_fingerprint",
            "restored_fingerprint",
            "post_snapshot_write_excluded",
            "tampered-csms.dump",
            "archive_tamper_rejected",
            "real_citrineos_schema_restored': False",
            "rabbitmq_broker_restored': False",
        ):
            self.assertIn(guard, code)

    def test_redacted_artifact(self):
        record = self.source.split("record = {", 1)[1].split("\n}", 1)[0]
        self.assertNotRegex(record, re.compile(r"secret|password|token|app_key", re.I))
        self.assertIn("database_archive_sha256", record)
        self.assertIn("physical_charger_or_money': False", record)


if __name__ == "__main__":
    unittest.main()
