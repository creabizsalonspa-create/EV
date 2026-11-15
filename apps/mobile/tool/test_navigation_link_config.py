"""Static fail-closed guards for native tab-navigation route wiring."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ANDROID = ROOT / "android/app/src/main/kotlin/com/creabiz/creabiz_ev/MainActivity.kt"
MANIFEST = ROOT / "android/app/src/main/AndroidManifest.xml"
IOS = ROOT / "ios/Runner/AppDelegate.swift"
BRIDGE = ROOT / "lib/navigation_links.dart"
MAIN = ROOT / "lib/main.dart"


class NativeNavigationGuards(unittest.TestCase):
    def test_android_rejects_links_with_identity_and_actions(self):
        source = ANDROID.read_text()
        for required in [
            'uri.scheme != "creabizev"',
            'uri.host != "navigate"',
            'uri.port != -1',
            'uri.userInfo != null',
            'uri.query != null',
            'uri.fragment != null',
            'uri.encodedPath != "/tab/$target"',
            'MethodChannel(\n            flutterEngine.dartExecutor.binaryMessenger, "com.creabiz.ev/navigation"',
            'navigationPending = null',
            'acceptReturn(intent)',
            'acceptNavigation(intent)',
        ]:
            self.assertIn(required, source)
        self.assertIn("android:pathPrefix=\"/tab/\"", MANIFEST.read_text())
        self.assertIn('android:host="payments"', MANIFEST.read_text())

    def test_ios_keeps_existing_payment_route_and_strict_tab_path(self):
        source = IOS.read_text()
        for required in [
            'parts.scheme == "creabizev"',
            'parts.host == "navigate"',
            'parts.user == nil',
            'parts.password == nil',
            'parts.port == nil',
            'parts.query == nil',
            'parts.fragment == nil',
            'parts.percentEncodedPath == "/tab/\\(target)"',
            'self?.navigationPending = nil',
            'acceptReturn(url) || acceptNavigation(url)',
        ]:
            self.assertIn(required, source)
        self.assertIn('"com.creabiz.ev/payment_return"', source)
        self.assertIn('"com.creabiz.ev/navigation"', source)

    def test_flutter_checks_active_identity_before_navigation(self):
        source = MAIN.read_text()
        for required in [
            "account?.id != owner.id",
            "account?.role != owner.role",
            "fence == null || !fence()",
            "NativeNavigationBridge.pending.removeListener(_onNavigationAvailable)",
        ]:
            self.assertIn(required, source)
        parser = BRIDGE.read_text()
        self.assertIn("navigationTabs.indexOf(target)", parser)
        self.assertIn("return index < 0 ? null : index;", parser)
        self.assertNotIn("payments/authorize", parser)


if __name__ == "__main__":
    unittest.main()
