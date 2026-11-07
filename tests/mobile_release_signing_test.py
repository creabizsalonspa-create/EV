"""Guard against accidentally distributing Android builds with debug keys.

This runs with Python stdlib on Linux before Android tooling is installed.
It is source/config regression evidence, NOT proof of a signed APK or AAB.
"""
from pathlib import Path
import plistlib
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
                    "root", "file", "database", "sharedpref", "external",
                    "device_root", "device_file", "device_database",
                    "device_sharedpref",
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

    def test_local_http_is_debug_only_and_only_for_exact_loopback(self):
        ns = "{http://schemas.android.com/apk/res/android}"
        for app in ("mobile", "hasone-customer"):
            with self.subTest(app=app):
                root = ROOT / "apps" / app / "android/app/src"
                release_manifest = ET.parse(
                    root / "main/AndroidManifest.xml"
                ).getroot().find("application")
                self.assertIsNotNone(release_manifest)
                self.assertIsNone(release_manifest.get(ns + "networkSecurityConfig"))
                debug_manifest = ET.parse(
                    root / "debug/AndroidManifest.xml"
                ).getroot().find("application")
                self.assertIsNotNone(debug_manifest)
                self.assertEqual(
                    "@xml/ev_debug_network_security",
                    debug_manifest.get(ns + "networkSecurityConfig"),
                )
                policy = ET.parse(
                    root / "debug/res/xml/ev_debug_network_security.xml"
                ).getroot()
                self.assertEqual("network-security-config", policy.tag)
                self.assertEqual(
                    "false",
                    policy.find("base-config").get("cleartextTrafficPermitted"),
                )
                configs = policy.findall("domain-config")
                self.assertEqual(1, len(configs))
                self.assertEqual(
                    "true", configs[0].get("cleartextTrafficPermitted")
                )
                domains = configs[0].findall("domain")
                self.assertEqual(
                    {"localhost", "127.0.0.1"},
                    {item.text.strip() for item in domains},
                )
                self.assertTrue(
                    all(item.get("includeSubdomains") == "false"
                        for item in domains)
                )

    def test_ios_photo_picker_has_user_visible_privacy_description(self):
        app = ROOT / "apps/hasone-customer"
        # Profile screen invokes ImagePicker with both camera and gallery.
        profile_source = (
            app / "lib/feature/profile/UI/screen/edit_profile_screen.dart"
        ).read_text()
        self.assertIn("ImageSource.camera", profile_source)
        self.assertIn("ImageSource.gallery", profile_source)
        with (app / "ios/Runner/Info.plist").open("rb") as handle:
            permissions = plistlib.load(handle)
        for key in (
            "NSCameraUsageDescription",
            "NSPhotoLibraryUsageDescription",
            "NSLocationWhenInUseUsageDescription",
        ):
            with self.subTest(key=key):
                value = permissions.get(key)
                self.assertIsInstance(value, str)
                self.assertGreater(len(value.strip()), 20)
                self.assertNotIn("TODO", value.upper())

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
