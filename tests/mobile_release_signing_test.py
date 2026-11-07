"""Guard against accidentally distributing Android builds with debug keys.

This runs with Python stdlib on Linux before Android tooling is installed.
It is source/config regression evidence, NOT proof of a signed APK or AAB.
"""
from pathlib import Path
import plistlib
import re
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
                if prefix == "HASONE":
                    self.assertIn(
                        'tasks.register("verifyReleaseMapsApiKey")', config
                    )
                    self.assertIn(
                        'tasks.register("verifyReleaseApplicationId")', config
                    )
                    self.assertIn(
                        'providers.gradleProperty("HASONE_ANDROID_APPLICATION_ID")',
                        config,
                    )
                    self.assertIn(
                        '!identifier.endsWith(".dev", ignoreCase = true)',
                        config,
                    )
                    self.assertIn(
                        'dependsOn(verifyReleaseSigning, verifyReleaseMapsApiKey, verifyReleaseApplicationId)',
                        config,
                    )
                    self.assertIn(
                        'manifestPlaceholders["mapsApiKey"] = mapsApiKey ?: ""',
                        config,
                    )
                else:
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

    def test_ios_debug_transport_is_loopback_only_and_release_is_unchanged(self):
        for app in ("mobile", "hasone-customer"):
            with self.subTest(app=app):
                root = ROOT / "apps" / app / "ios"
                with (root / "Runner/Info.plist").open("rb") as handle:
                    release = plistlib.load(handle)
                with (root / "Runner/Info-Debug.plist").open("rb") as handle:
                    debug = plistlib.load(handle)
                self.assertNotIn("NSAppTransportSecurity", release)
                ats = debug.pop("NSAppTransportSecurity")
                self.assertEqual(release, debug)
                exceptions = ats["NSExceptionDomains"]
                self.assertEqual(
                    {"localhost", "127.0.0.1"}, set(exceptions)
                )
                for host in exceptions:
                    self.assertIs(
                        False, exceptions[host]["NSIncludesSubdomains"]
                    )
                    self.assertIs(
                        True, exceptions[host][
                            "NSExceptionAllowsInsecureHTTPLoads"
                        ]
                    )
                project = (
                    root / "Runner.xcodeproj/project.pbxproj"
                ).read_text()
                debug_start = project.index(
                    '97C147061CF9000F007C117D /* Debug */ = {'
                )
                release_start = project.index(
                    '97C147071CF9000F007C117D /* Release */ = {',
                    debug_start,
                )
                debug_target = project[debug_start:release_start]
                release_target = project[release_start:]
                self.assertIn(
                    'INFOPLIST_FILE = "Runner/Info-Debug.plist";',
                    debug_target,
                )
                self.assertIn(
                    'INFOPLIST_FILE = Runner/Info.plist;',
                    release_target,
                )

    def test_flutter_ios_minimum_version_matches_runner_and_pods(self):
        for app in ("mobile", "hasone-customer"):
            with self.subTest(app=app):
                root = ROOT / "apps" / app / "ios"
                with (root / "Flutter/AppFrameworkInfo.plist").open("rb") as handle:
                    version = plistlib.load(handle)["MinimumOSVersion"]
                podfile = (root / "Podfile").read_text()
                match = re.search(r"platform :ios, '([0-9.]+)'", podfile)
                self.assertIsNotNone(match)
                self.assertEqual(match.group(1), version)
                project = (
                    root / "Runner.xcodeproj/project.pbxproj"
                ).read_text()
                versions = re.findall(
                    r"IPHONEOS_DEPLOYMENT_TARGET = ([0-9.]+);",
                    project,
                )
                self.assertTrue(versions)
                self.assertEqual({version}, set(versions))

    def test_ios_google_maps_is_initialized_with_untracked_build_setting(self):
        root = ROOT / "apps/hasone-customer/ios"
        delegate = (root / "Runner/AppDelegate.swift").read_text()
        self.assertIn("import GoogleMaps", delegate)
        self.assertIn("GMSServices.provideAPIKey(key)", delegate)
        self.assertIn('object(forInfoDictionaryKey: "GoogleMapsApiKey")', delegate)
        self.assertLess(
            delegate.index("GMSServices.provideAPIKey"),
            delegate.index("GeneratedPluginRegistrant.register"),
        )
        for variant in ("Info.plist", "Info-Debug.plist"):
            with self.subTest(variant=variant):
                with (root / "Runner" / variant).open("rb") as handle:
                    manifest = plistlib.load(handle)
                self.assertEqual(
                    "$(EV_GOOGLE_MAPS_IOS_KEY)", manifest["GoogleMapsApiKey"]
                )
        for variant in ("Debug.xcconfig", "Release.xcconfig"):
            text = (root / "Flutter" / variant).read_text()
            self.assertIn('#include? "MapsKeys.xcconfig"', text)
        self.assertIn(
            "Flutter/MapsKeys.xcconfig", (root / ".gitignore").read_text()
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

    def test_customer_ios_release_uses_private_approved_bundle_id(self):
        project = (
            ROOT / "apps/hasone-customer/ios/Runner.xcodeproj/project.pbxproj"
        ).read_text()
        ids = re.findall(r"PRODUCT_BUNDLE_IDENTIFIER = ([^;]+);", project)
        # Xcode configuration order: Runner Profile, RunnerTests
        # Debug/Release/Profile, Runner Debug, Runner Release.
        self.assertEqual(
            [
                '"$(EV_HASONE_IOS_RELEASE_BUNDLE_ID)"',
                "com.creabiz.hasone.customer.dev.RunnerTests",
                '"$(EV_HASONE_IOS_RELEASE_BUNDLE_ID).RunnerTests"',
                '"$(EV_HASONE_IOS_RELEASE_BUNDLE_ID).RunnerTests"',
                "com.creabiz.hasone.customer.dev",
                '"$(EV_HASONE_IOS_RELEASE_BUNDLE_ID)"',
            ],
            ids,
        )
        release_config = (
            ROOT / "apps/hasone-customer/ios/Flutter/Release.xcconfig"
        ).read_text()
        self.assertIn('#include? "MapsKeys.xcconfig"', release_config)
        self.assertIn(
            "Flutter/MapsKeys.xcconfig",
            (ROOT / "apps/hasone-customer/ios/.gitignore").read_text(),
        )

    def test_debug_android_id_cannot_impersonate_approved_release_id(self):
        gradle = PROJECTS["HASONE"].read_text()
        self.assertIn(
            'applicationId = approvedApplicationId?.takeIf { it.isNotBlank() }',
            gradle,
        )
        self.assertIn(
            '''debug {
            // Even if CI also supplies the approved release ID''',
            gradle,
        )
        self.assertIn('applicationIdSuffix = ".dev"', gradle)

    def test_app_identity_is_not_silently_rebranded(self):
        self.assertIn(
            'applicationId = "com.creabiz.creabiz_ev"',
            PROJECTS["EV"].read_text(),
        )
        hasone = PROJECTS["HASONE"].read_text()
        self.assertIn(
            'val defaultApplicationId = "com.creabiz.hasone.customer.dev"',
            hasone,
        )
        self.assertIn(
            "applicationId = approvedApplicationId?.takeIf { it.isNotBlank() }",
            hasone,
        )
        self.assertIn("?: defaultApplicationId", hasone)
        self.assertIn("verifyReleaseApplicationId", hasone)


if __name__ == "__main__":
    unittest.main()
