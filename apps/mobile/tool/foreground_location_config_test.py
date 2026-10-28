"""Guard the foreground-only native permission/release boundary."""
import pathlib
import plistlib
import unittest
import xml.etree.ElementTree as ET

root = pathlib.Path(__file__).resolve().parents[1]

class ForegroundPermissionTest(unittest.TestCase):
    def test_android_requests_no_background_location_or_tracking_service(self):
        manifest = ET.parse(root / 'android/app/src/main/AndroidManifest.xml')
        names = {n.attrib['{http://schemas.android.com/apk/res/android}name'] for n in manifest.findall('uses-permission')}
        self.assertIn('android.permission.ACCESS_COARSE_LOCATION', names)
        self.assertIn('android.permission.ACCESS_FINE_LOCATION', names)
        self.assertNotIn('android.permission.ACCESS_BACKGROUND_LOCATION', names)
        self.assertNotIn('android.permission.FOREGROUND_SERVICE_LOCATION', names)

    def test_ios_has_localized_when_in_use_only_and_preserves_plugin_build_settings(self):
        info = plistlib.loads((root / 'ios/Runner/Info.plist').read_bytes())
        self.assertTrue(info['NSLocationWhenInUseUsageDescription'])
        self.assertNotIn('NSLocationAlwaysAndWhenInUseUsageDescription', info)
        self.assertNotIn('NSLocationAlwaysUsageDescription', info)
        self.assertNotIn('location', info.get('UIBackgroundModes', []))
        podfile = (root / 'ios/Podfile').read_text()
        self.assertIn('flutter_additional_ios_build_settings(target)', podfile)
        self.assertIn("target.name == 'geolocator_apple'", podfile)
        self.assertIn('BYPASS_PERMISSION_LOCATION_ALWAYS=1', podfile)
        project = (root / 'ios/Runner.xcodeproj/project.pbxproj').read_text()
        for locale in ['en', 'ar']:
            text = (root / f'ios/Runner/{locale}.lproj/InfoPlist.strings').read_text()
            self.assertIn('NSLocationWhenInUseUsageDescription', text)
            self.assertIn(f'{locale}.lproj/InfoPlist.strings', project)
        self.assertIn('InfoPlist.strings in Resources', project)

if __name__ == '__main__':
    unittest.main()
