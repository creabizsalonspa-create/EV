"""Native manifest contracts; source/OS URI handling still needs device CI."""
import pathlib
import plistlib
import unittest
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
ANDROID = '{http://schemas.android.com/apk/res/android}'


class HostedReturnConfig(unittest.TestCase):
    def test_android_registers_only_fixed_custom_return_and_disables_route_autonavigation(self):
        tree = ET.parse(ROOT / 'android/app/src/main/AndroidManifest.xml')
        activity = tree.find('./application/activity')
        self.assertEqual('singleTop', activity.get(ANDROID + 'launchMode'))
        links = [item for item in activity.findall('intent-filter') if any(a.get(ANDROID + 'name') == 'android.intent.action.VIEW' for a in item.findall('action'))]
        self.assertEqual(1, len(links))
        self.assertEqual([{ANDROID + 'scheme': 'creabizev', ANDROID + 'host': 'payments', ANDROID + 'path': '/hosted-return'}], [dict(d.attrib) for d in links[0].findall('data')])
        flags = {m.get(ANDROID + 'name'): m.get(ANDROID + 'value') for m in activity.findall('meta-data')}
        self.assertEqual('false', flags.get('flutter_deeplinking_enabled'))

    def test_ios_registers_custom_signal_without_claiming_https_associations(self):
        with (ROOT / 'ios/Runner/Info.plist').open('rb') as handle:
            info = plistlib.load(handle)
        self.assertFalse(info['FlutterDeepLinkingEnabled'])
        self.assertEqual([['creabizev']], [row['CFBundleURLSchemes'] for row in info['CFBundleURLTypes']])
        # No associated-domains entitlement is fabricated by this custom scheme.
        for file in (ROOT / 'ios').rglob('*.entitlements'):
            with file.open('rb') as handle:
                self.assertNotIn('com.apple.developer.associated-domains', plistlib.load(handle))


if __name__ == '__main__':
    unittest.main()
