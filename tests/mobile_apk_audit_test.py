"""Regression tests for the compiled mobile APK security audit."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts/audit_mobile_apk.py"
spec = importlib.util.spec_from_file_location("mobile_apk_audit", MODULE)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)

BADGING = """package: name='com.creabiz.hasone.customer.dev' versionCode='1'
sdkVersion:'24'
targetSdkVersion:'36'
"""
MANIFEST = """E: application
  A: android:allowBackup(0x01010280)=(type 0x12)0x0
  A: android:fullBackupContent(0x010104eb)=@0x7f140000
  A: android:usesCleartextTraffic(0x010104ec)=(type 0x12)0x0
  A: android:dataExtractionRules(0x0101063e)=@0x7f140001
  E: meta-data
    A: android:name(0x01010003)="com.google.android.geo.API_KEY" (Raw: "com.google.android.geo.API_KEY")
    A: android:value(0x01010024)="" (Raw: "")
"""
EXPECTED = "com.creabiz.hasone.customer.dev"


class MobileApkAuditTests(unittest.TestCase):
    def audit(self, badging=BADGING, manifest=MANIFEST, **kwargs):
        return audit.audit_manifest(
            badging, manifest, expected_package=kwargs.pop("package", EXPECTED),
            **kwargs,
        )

    def test_protected_debug_package_is_accepted(self):
        result = self.audit()
        self.assertEqual(EXPECTED, result["package"])
        self.assertEqual(36, result["target_sdk"])
        self.assertTrue(result["backup_disabled"])
        self.assertTrue(result["remote_cleartext_disabled"])

    def test_unexpected_package_is_rejected(self):
        with self.assertRaisesRegex(audit.AuditError, "identity"):
            self.audit(package="com.example.foreign")

    def test_missing_or_enabled_backup_is_rejected(self):
        for text in (
            MANIFEST.replace(
                "android:allowBackup(0x01010280)=(type 0x12)0x0",
                "android:allowBackup(0x01010280)=(type 0x12)0xffffffff",
            ),
            MANIFEST.replace("  A: android:dataExtractionRules(0x0101063e)=@0x7f140001", ""),
        ):
            with self.subTest(manifest=text):
                with self.assertRaises(audit.AuditError):
                    self.audit(manifest=text)

    def test_cleartext_enabled_is_rejected(self):
        unsafe = MANIFEST.replace(
            "android:usesCleartextTraffic(0x010104ec)=(type 0x12)0x0",
            "android:usesCleartextTraffic(0x010104ec)=(type 0x12)0xffffffff",
        )
        with self.assertRaisesRegex(audit.AuditError, "usesCleartextTraffic"):
            self.audit(manifest=unsafe)

    def test_missing_maps_key_is_rejected_when_explicitly_required(self):
        with self.assertRaisesRegex(audit.AuditError, "Google Maps"):
            self.audit(maps_required=True)

    def test_configured_maps_key_is_accepted_without_returning_key(self):
        manifest = MANIFEST.replace(
            'android:value(0x01010024)=""',
            'android:value(0x01010024)="synthetic-only-credential"',
        )
        result = self.audit(manifest=manifest, maps_required=True)
        self.assertNotIn("synthetic-only-credential", str(result))

    def test_release_cannot_use_development_identity(self):
        sha = "a" * 64
        with self.assertRaisesRegex(audit.AuditError, "nonproduction"):
            self.audit(
                mode="release", expected_signer_sha256=sha,
                signer_output="Signer #1 certificate SHA-256 digest: " + sha,
            )

    def test_release_requires_known_signer_and_matching_cert(self):
        prod_badging = BADGING.replace(EXPECTED, "com.creabiz.hasone.customer")
        with self.assertRaisesRegex(audit.AuditError, "approved signing"):
            self.audit(badging=prod_badging, package="com.creabiz.hasone.customer",
                       mode="release")
        with self.assertRaisesRegex(audit.AuditError, "does not match"):
            self.audit(
                badging=prod_badging, package="com.creabiz.hasone.customer",
                mode="release", expected_signer_sha256="a" * 64,
                signer_output="Signer #1 certificate SHA-256 digest: " + "b" * 64,
            )
        result = self.audit(
            badging=prod_badging, package="com.creabiz.hasone.customer",
            mode="release", expected_signer_sha256="a" * 64,
            signer_output="Signer #1 certificate SHA-256 digest: " + "a" * 64,
        )
        self.assertEqual("a" * 64, result["approved_signer_digest"])

    def test_missing_target_sdk_is_rejected(self):
        text = BADGING.replace("targetSdkVersion:'36'\n", "")
        with self.assertRaisesRegex(audit.AuditError, "target Android SDK"):
            self.audit(badging=text)


if __name__ == "__main__":
    unittest.main()
