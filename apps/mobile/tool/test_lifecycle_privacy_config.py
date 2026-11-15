#!/usr/bin/env python3
"""Source guard for the canonical mobile app's inactive-window privacy fence.

This verifies integration points, not device screenshot behavior. The hosted
Android/iOS builds and physical device checks remain separate release gates.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


def section(path: str, start: str, end: str) -> str:
    source = (ROOT / path).read_text(encoding="utf-8")
    if source.count(start) != 1 or source.count(end) != 1:
        raise AssertionError(f"Missing/ambiguous native lifecycle method in {path}")
    return source.split(start, 1)[1].split(end, 1)[0]


class InactivePrivacySourceTests(unittest.TestCase):
    def test_android_pause_sets_and_resume_clears_secure_window_flag(self):
        path = "android/app/src/main/kotlin/com/creabiz/creabiz_ev/MainActivity.kt"
        paused = section(path, "override fun onPause() {", "override fun onResume() {")
        resumed = section(path, "override fun onResume() {", "override fun onNewIntent(intent: Intent) {")
        self.assertIn("window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)", paused)
        self.assertIn("super.onPause()", paused)
        self.assertIn("window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)", resumed)
        self.assertIn("super.onResume()", resumed)
        self.assertNotIn("clearFlags", paused)

    def test_ios_window_cover_spans_inactive_state_without_route_disposal(self):
        path = "ios/Runner/AppDelegate.swift"
        inactive = section(
            path,
            "override func applicationWillResignActive(_ application: UIApplication) {",
            "override func applicationDidBecomeActive(_ application: UIApplication) {",
        )
        active = section(
            path,
            "override func applicationDidBecomeActive(_ application: UIApplication) {",
            "override func application(_ app: UIApplication, open url: URL",
        )
        for expected in (
            "UIView(frame: activeWindow.bounds)",
            "cover.backgroundColor = .black",
            "activeWindow.addSubview(cover)",
            "inactivePrivacyCover = cover",
        ):
            self.assertIn(expected, inactive)
        self.assertIn("inactivePrivacyCover?.removeFromSuperview()", active)
        self.assertIn("inactivePrivacyCover = nil", active)

    def test_flutter_navigator_is_covered_and_accessibility_is_blocked(self):
        app = (ROOT / "lib/main.dart").read_text(encoding="utf-8")
        self.assertIn("key: const Key('privacy-shield')", app)
        self.assertIn("key: const Key('privacy-content-semantics')", app)
        self.assertIn("excluding: _privacyHidden", app)
        self.assertIn("ignoring: _privacyHidden", app)
        self.assertIn("final hidden = state != AppLifecycleState.resumed;", app)


if __name__ == "__main__":
    unittest.main()
