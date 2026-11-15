"""Exercise compiled native URL audit with synthetic manifests, never devices."""
import plistlib
from pathlib import Path
import tempfile
import unittest

from check_navigation_build import audit_android, audit_ios

MANIFEST = """<manifest xmlns:android="http://schemas.android.com/apk/res/android">
  <application>
    <activity android:name=".MainActivity" android:exported="true"
              android:launchMode="singleTop">
      <meta-data android:name="flutter_deeplinking_enabled" android:value="false"/>
      <intent-filter>
        <action android:name="android.intent.action.VIEW"/>
        <category android:name="android.intent.category.DEFAULT"/>
        <category android:name="android.intent.category.BROWSABLE"/>
        <data android:scheme="creabizev" android:host="payments"
              android:path="/hosted-return"/>
      </intent-filter>
      <intent-filter>
        <action android:name="android.intent.action.VIEW"/>
        <category android:name="android.intent.category.DEFAULT"/>
        <category android:name="android.intent.category.BROWSABLE"/>
        <data android:scheme="creabizev" android:host="navigate"
              android:pathPrefix="/tab/"/>
      </intent-filter>
      <intent-filter>
        <action android:name="android.intent.action.MAIN"/>
        <category android:name="android.intent.category.LAUNCHER"/>
      </intent-filter>
    </activity>
  </application>
</manifest>"""


class NativeLinkArtifactAudit(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.android = (
            self.root / "build/app/intermediates/merged_manifests/debug"
            / "processDebugMainManifest/AndroidManifest.xml"
        )
        self.android.parent.mkdir(parents=True)
        self.android.write_text(MANIFEST)
        self.ios = self.root / "build/ios/iphonesimulator/Runner.app/Info.plist"
        self.ios.parent.mkdir(parents=True)
        self.ios.write_bytes(plistlib.dumps({
            "CFBundleURLTypes": [{
                "CFBundleURLSchemes": ["creabizev"],
                "CFBundleURLName": "com.creabiz.ev.payment-return",
            }],
            "FlutterDeepLinkingEnabled": False,
        }))

    def test_two_exact_android_view_filters(self):
        self.assertEqual(1, audit_android(self.root))

    def test_android_rejects_extra_browser_or_payment_authority(self):
        for mutated in (
            MANIFEST.replace('android:host="navigate"', 'android:host="admin"'),
            MANIFEST.replace('android:pathPrefix="/tab/"', 'android:pathPrefix="/"'),
            MANIFEST.replace('android:value="false"', 'android:value="true"'),
            MANIFEST.replace('android:exported="true"', 'android:exported="false"'),
            MANIFEST.replace('android:path="/hosted-return"',
                             'android:path="/hosted-return" android:port="443"'),
            MANIFEST.replace('android.intent.category.BROWSABLE',
                             'android.intent.category.HOME'),
            MANIFEST.replace('android.intent.action.VIEW',
                             'android.intent.action.EDIT', 1),
            MANIFEST.replace('android:scheme="creabizev" android:host="navigate"',
                             'android:scheme="https" android:host="navigate"'),
        ):
            with self.subTest(mutated=mutated[-160:]):
                self.android.write_text(mutated)
                with self.assertRaises(AssertionError):
                    audit_android(self.root)
        self.android.write_text(MANIFEST)

    def test_ios_requires_exact_custom_scheme_and_disables_autoroute(self):
        audit_ios(self.root)
        for modified in (
            {"CFBundleURLTypes": [{"CFBundleURLSchemes": ["https"]}],
             "FlutterDeepLinkingEnabled": False},
            {"CFBundleURLTypes": [{"CFBundleURLSchemes": ["creabizev", "other"]}],
             "FlutterDeepLinkingEnabled": False},
            {"CFBundleURLTypes": [{"CFBundleURLSchemes": ["creabizev"]}],
             "FlutterDeepLinkingEnabled": True},
        ):
            with self.subTest(modified=modified):
                self.ios.write_bytes(plistlib.dumps(modified))
                with self.assertRaises(AssertionError):
                    audit_ios(self.root)


if __name__ == "__main__":
    unittest.main()
