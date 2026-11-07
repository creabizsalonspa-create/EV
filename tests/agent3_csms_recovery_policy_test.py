"""Agent 3 synthetic CitrineOS PostGIS recovery policy checks."""
from pathlib import Path
import re
import subprocess
import tempfile
import shutil
import os
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

    def test_missing_docker_erases_old_pass_without_running_containers(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root/'scripts').mkdir()
            (root/'artifacts/agent3-csms-recovery').mkdir(parents=True)
            script = root/'scripts/csms-recovery-smoke'
            shutil.copyfile(SCRIPT, script)
            artifact = root/'artifacts/agent3-csms-recovery/validation.json'
            artifact.write_text('{"status":"PASS","outdated":true}')
            utilities = root/'safe-path'
            utilities.mkdir()
            for name in ('dirname', 'mkdir', 'rm', 'date'):
                destination = shutil.which(name)
                self.assertIsNotNone(destination)
                (utilities/name).symlink_to(destination)
            # No Docker binary exists in this restricted PATH, even when the
            # CI runner has one. This test never starts or inspects a daemon.
            run = subprocess.run(
                ['/bin/bash', str(script)], env={'PATH': str(utilities)},
                capture_output=True, text=True, timeout=10,
            )
            self.assertNotEqual(run.returncode, 0)
            self.assertFalse(artifact.exists())

    def test_geospatial_proof_and_point_in_time_boundary(self):
        code = self.source
        for guard in (
            "CREATE EXTENSION IF NOT EXISTS postgis",
            "geometry(Point,4326)",
            "ST_SetSRID",
            "ST_SRID",
            "--format=custom --no-owner --no-privileges",
            "--clean --if-exists --no-owner --no-privileges --exit-on-error --single-transaction",
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

    def test_effective_runtime_isolation_is_required_before_restore(self):
        code = self.source
        self.assertIn('recovery-runtime-guard.py" networks', code)
        self.assertEqual(code.count('recovery-runtime-guard.py" containers'), 2)
        self.assertLess(code.index('recovery-runtime-guard.py" containers'),
                        code.index('pg_restore -U ev_csms_recovery'))
        self.assertIn("'isolated_runtime_verified': True", code)

    def test_container_mount_target_enforced_for_postgis(self):
        guard = (ROOT / 'scripts/recovery-runtime-guard.py').read_text()
        self.assertIn("destination = '/var/lib/postgresql/data'", guard)
        self.assertIn("mount.get('Destination') == destination", guard)

    def test_network_inspection_detects_unreviewed_container_peers(self):
        code = self.source
        self.assertEqual(code.count('recovery-runtime-guard.py" networks'), 3)
        self.assertIn('--source-container "$source_db"', code)
        self.assertIn('--target-container "$target_db"', code)

    def test_exact_reviewed_postgis_image_reference_checked(self):
        self.assertEqual(self.source.count('--reviewed-db-image "$image"'), 2)

    def test_timing_span_covers_dump_restore_and_geospatial_checks(self):
        code = self.source
        self.assertLess(code.index('snapshot_started_epoch=$(date -u +%s)'),
                        code.index('pg_dump -U ev_csms_recovery'))
        self.assertLess(code.index('restore_started_epoch=$(date -u +%s)'),
                        code.index('pg_restore -U ev_csms_recovery'))
        self.assertGreater(code.index('drill_completed_epoch=$(date -u +%s)'),
                           code.index('restored_fingerprint'))
        self.assertIn("'drill_completed_epoch': int(drill_end)", code)

    def test_disposable_manifest_hmac_authentication(self):
        code = self.source
        self.assertIn('auth_key="$scratch/backup-hmac.key"', code)
        self.assertIn('stream.write(os.urandom(32))', code)
        self.assertGreaterEqual(code.count('--auth-key-file "$auth_key"'), 5)
        self.assertLess(code.index('auth_key="$scratch/backup-hmac.key"'),
                        code.index('pg_restore -U ev_csms_recovery'))
        self.assertIn("'run_local_manifest_hmac_verified': True", code)

    def test_success_evidence_is_written_atomically(self):
        code = self.source
        self.assertIn('from recovery_proof_writer import publish', code)
        self.assertIn('publish(path, record)', code)
        self.assertNotIn('os.O_TRUNC, 0o600', code)

    def test_redacted_artifact(self):
        record = self.source.split("record = {", 1)[1].split("\n}", 1)[0]
        self.assertNotRegex(record, re.compile(r"secret|password|token|app_key", re.I))
        self.assertIn("database_archive_sha256", record)
        self.assertIn("physical_charger_or_money': False", record)


if __name__ == "__main__":
    unittest.main()
