"""Guard against accidentally distributing Android builds with debug keys.

This runs with Python stdlib on Linux before Android tooling is installed.
It is source/config regression evidence, NOT proof of a signed APK or AAB.
"""
from pathlib import Path
import unittest

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
