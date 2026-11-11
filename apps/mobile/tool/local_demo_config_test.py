"""Local demo transport stays in debug; release and privacy settings remain intact."""
import pathlib
import plistlib
import unittest
import xml.etree.ElementTree as ET

root = pathlib.Path(__file__).resolve().parents[1]
android = '{http://schemas.android.com/apk/res/android}'

class LocalDemoConfigTest(unittest.TestCase):
    def test_android_only_explicit_loopback_domains_allow_debug_cleartext(self):
        main = ET.parse(root / 'android/app/src/main/AndroidManifest.xml').getroot()
        self.assertNotIn(android + 'networkSecurityConfig', main.find('application').attrib)
        # Main/release explicitly deny cleartext; the debug-only network
        # security config may permit only narrowly scoped loopback domains.
        self.assertEqual(main.find('application').get(android + 'usesCleartextTraffic'), 'false')
        debug = ET.parse(root / 'android/app/src/debug/AndroidManifest.xml').getroot()
        self.assertEqual(debug.find('application').get(android + 'networkSecurityConfig'), '@xml/ev_debug_network_security')
        policy = ET.parse(root / 'android/app/src/debug/res/xml/ev_debug_network_security.xml').getroot()
        self.assertEqual(policy.find('base-config').get('cleartextTrafficPermitted'), 'false')
        groups = policy.findall('domain-config')
        self.assertEqual(len(groups), 1)
        self.assertEqual(groups[0].get('cleartextTrafficPermitted'), 'true')
        self.assertEqual({d.text for d in groups[0].findall('domain')}, {'127.0.0.1', 'localhost'})
        self.assertTrue(all(d.get('includeSubdomains') == 'false' for d in groups[0].findall('domain')))
        self.assertEqual([n.get('src') for n in policy.findall('.//certificates')], ['system'])

    def test_ios_debug_preserves_every_release_privacy_value_and_has_only_local_exception(self):
        main = plistlib.loads((root / 'ios/Runner/Info.plist').read_bytes())
        debug = plistlib.loads((root / 'ios/Runner/Info-Debug.plist').read_bytes())
        self.assertNotIn('NSAppTransportSecurity', main)
        self.assertEqual(debug.pop('NSAppTransportSecurity'), {'NSExceptionDomains': {host: {'NSIncludesSubdomains': False, 'NSExceptionAllowsInsecureHTTPLoads': True} for host in ['localhost', '127.0.0.1']}})
        self.assertEqual(debug, main)
        project = (root / 'ios/Runner.xcodeproj/project.pbxproj').read_text()
        block = project.split('97C147061CF9000F007C117D /* Debug */ = {')[1].split('\n\t\t};')[0]
        self.assertIn('INFOPLIST_FILE = "Runner/Info-Debug.plist";', block)
        self.assertEqual(project.count('INFOPLIST_FILE = Runner/Info.plist;'), 2)  # Profile and Release.
        self.assertEqual(project.count('INFOPLIST_FILE = "Runner/Info-Debug.plist";'), 1)

if __name__ == '__main__':
    unittest.main()
