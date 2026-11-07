"""Guard against accidentally distributing Android builds with debug keys.

This runs with Python stdlib on Linux before Android tooling is installed.
It is source/config regression evidence, NOT proof of a signed APK or AAB.
"""
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
PROJECTS = {
    "EV": ROOT / "apps/mobile/android/app/build.gradle.kts",
    "HASONE": ROOT / "apps/hasone-customer/android/app/build.gradle.kts",
}


class MobileReleaseSigningTest(unittest.TestCase):
    def test_both_android_apps_require_private_release_credentials(self):
        for prefix, gradle_file in PROJECTS.items():
            with self.subTest(app=prefix):
                config = gradle_file.read_text()
                self.assertNotIn('signingConfig = signingConfigs.getByName("debug")', config)
                for suffix in (
                    "STORE_FILE", "STORE_PASSWORD", "KEY_ALIAS", "KEY_PASSWORD"
                ):
                    self.assertIn(
                        f'providers.gradleProperty("{prefix}_ANDROID_{suffix}")',
                        config,
                    )
                self.assertIn('create("release")', config)
                self.assertIn('signingConfig = signingConfigs.getByName("release")', config)
                self.assertIn('releaseSigningComplete', config)

    def test_release_tasks_fail_closed_without_credentials_or_keystore(self):
        for prefix, gradle_file in PROJECTS.items():
            with self.subTest(app=prefix):
                config = gradle_file.read_text()
                self.assertIn('tasks.register("verifyReleaseSigning")', config)
                self.assertIn('if (!releaseSigningComplete)', config)
                self.assertIn('if (!file(releaseStoreFile!!).isFile)', config)
                for task in ('assembleRelease', 'bundleRelease', 'packageRelease'):
                    self.assertIn(f'"{task}"', config)
                self.assertIn('dependsOn(verifyReleaseSigning)', config)

    def test_android_apps_deny_cloud_and_device_transfer_of_credentials(self):
        for app in ("mobile", "hasone-customer"):
            with self.subTest(app=app):
                root = ROOT / "apps" / app / "android/app/src/main"
                manifest = ET.parse(root / "AndroidManifest.xml").getroot()
                ns = "{http://schemas.android.com/apk/res/android}"
                application = manifest.find("application")
                self.assertIsNotNone(application)
                self.assertEqual("false", application.get(ns + "allowBackup"))
                self.assertEqual(
                    "@xml/ev_backup_rules",
                    application.get(ns + "fullBackupContent"),
                )
                self.assertEqual(
                    "@xml/ev_data_extraction_rules",
                    application.get(ns + "dataExtractionRules"),
                )

                backup = ET.parse(
                    root / "res/xml/ev_backup_rules.xml"
                ).getroot()
                self.assertEqual("full-backup-content", backup.tag)
                required_domains = {
                    "root", "file", "database", "sharedpref", "external"
                }
                self.assertEqual(
                    required_domains,
                    {entry.get("domain") for entry in backup.findall("exclude")
                     if entry.get("path") == "."},
                )
                extraction = ET.parse(
                    root / "res/xml/ev_data_extraction_rules.xml"
                ).getroot()
                self.assertEqual("data-extraction-rules", extraction.tag)
                for category in ("cloud-backup", "device-transfer"):
                    section = extraction.find(category)
                    self.assertIsNotNone(section)
                    self.assertEqual(
                        required_domains,
                        {entry.get("domain")
                         for entry in section.findall("exclude")
                         if entry.get("path") == "."},
                    )

    def test_app_identity_is_not_silently_rebranded(self):
        self.assertIn(
            'applicationId = "com.creabiz.creabiz_ev"',
            PROJECTS["EV"].read_text(),
        )
        self.assertIn(
            'applicationId = "com.creabiz.hasone.customer.dev"',
            PROJECTS["HASONE"].read_text(),
        )


if __name__ == "__main__":
    unittest.main()
