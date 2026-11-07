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
            "--clean --if-exists --no-owner --no-privileges --exit-on-error --single-transaction",
            "migration_fingerprint",
            "probe_fingerprint",
            "php artisan migrate:status",
            "restored_http_catalog",
            "private-probe.tar.gz",
            "queue:work --once",
            "artisan schedule:list",
            "post-snapshot",
            "tampered-business.dump",
            "tampered-private.tar.gz",
            "synthetic_private_archive_sha256",
            "PHP_ENCRYPT",
            "PHP_DECRYPT",
            "acceptedWrongKey",
            "Crypt::encryptString",
            "Crypt::decryptString",
            "Recovered APP_KEY could not decrypt source data.",
            "fileperms($p) & 0777",
            "classification': 'disposable staging software only'",
        ):
            self.assertIn(required, code)

    def test_backup_integrity_checked_around_database_and_private_restores(self):
        code = self.source
        db_restore = code.index('pg_restore -U ev_recovery_review')
        private_restore = code.index('tar --no-same-owner --no-same-permissions')
        guard = 'backup-artifact-guard.py" check'
        self.assertIn(guard, code[db_restore-350:db_restore])
        self.assertIn(guard, code[db_restore:db_restore+400])
        self.assertIn(guard, code[private_restore-350:private_restore])
        self.assertIn(guard, code[private_restore:private_restore+400])

    def test_late_private_write_cannot_enter_restored_snapshot(self):
        source = self.source
        name = 'agent3-post-snapshot-private.bin'
        self.assertIn(name, source)
        archive = source.index('private-probe.tar.gz')
        created = source.index('file_put_contents($p, "not-in-private-snapshot"')
        restored = source.index('if (file_exists("/var/www/html/storage/app/private/' +
                                name + '")) exit(1);')
        self.assertLess(archive, created)
        self.assertLess(created, restored)
        self.assertIn("'post_snapshot_private_write_excluded': True", source)

    def test_private_file_restore_cannot_run_as_root_or_preserve_archive_owners(self):
        code = self.source
        self.assertIn('docker exec -i --user www-data "$target_app" sh -ceu', code)
        self.assertIn('umask 077; tar --no-same-owner --no-same-permissions', code)
        self.assertNotIn('docker exec -i "$target_app" tar -C', code)
        self.assertIn('fileperms($p) & 0777', code)

    def test_effective_docker_runtime_must_be_verified_before_any_restore(self):
        code = self.source
        self.assertIn('recovery-runtime-guard.py" networks', code)
        self.assertEqual(code.count('recovery-runtime-guard.py" containers'), 2)
        self.assertLess(code.index('recovery-runtime-guard.py" networks'),
                        code.index('pg_restore -U ev_recovery_review'))
        self.assertLess(code.index('recovery-runtime-guard.py" containers'),
                        code.index('pg_restore -U ev_recovery_review'))
        self.assertIn("'isolated_runtime_verified': True", code)

    def test_no_sensitive_data_in_artifact_schema(self):
        code = self.source
        artifact = code.split("record = {", 1)[1].split("\n}", 1)[0]
        self.assertNotRegex(artifact, re.compile(r"password|secret|app_key|token", re.I))
        self.assertIn("dump_sha256", artifact)
        self.assertIn("laravel_migration_rows", artifact)
        self.assertIn("synthetic_private_archive_sha256", artifact)


if __name__ == "__main__":
    unittest.main()
