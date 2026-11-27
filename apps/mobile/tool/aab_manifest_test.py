import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from audit_aab_manifest import AuditError, audit, inspect_manifest

XML = '''<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="com.example.ev" android:versionCode="42">
<uses-sdk android:targetSdkVersion="35"/><application android:allowBackup="false" android:usesCleartextTraffic="false" android:fullBackupContent="@xml/ev_backup_rules" android:dataExtractionRules="@0x7f010001"/>
</manifest>'''


class AabManifestTest(unittest.TestCase):
    def test_manifest(self):
        result = inspect_manifest(XML, 'com.example.ev', '42')
        self.assertTrue(result['base_manifest_verified'])
        self.assertFalse(result['compiled_backup_exclusions_verified'])
        for old, new in [('com.example.ev', 'com.other.ev'), ('versionCode="42"', 'versionCode="43"'),
                         ('allowBackup="false"', 'allowBackup="true"'), ('usesCleartextTraffic="false"', 'usesCleartextTraffic="true"'),
                         ('<application ', '<application android:debuggable="true" '),
                         ('<application ', '<application android:testOnly="@bool/test" '),
                         ('<application ', '<application android:networkSecurityConfig="@xml/network" '),
                         ('@xml/ev_backup_rules', 'false'), ('targetSdkVersion="35"', 'targetSdkVersion="bad"'),
                         ('<uses-sdk', '<application/><uses-sdk')]:
            with self.subTest(new=new), self.assertRaises(AuditError):
                inspect_manifest(XML.replace(old, new), 'com.example.ev', '42')
        with self.assertRaises(AuditError):
            inspect_manifest(XML, 'com.example.ev', '42', True)
        maps = XML.replace('/>\n</manifest>', '><meta-data android:name="com.google.android.geo.API_KEY" android:value="fixture-key"/></application>\n</manifest>')
        self.assertTrue(inspect_manifest(maps, 'com.example.ev', '42', True)['base_manifest_verified'])

    def test_orchestration_and_pins(self):
        with tempfile.TemporaryDirectory() as directory:
            bundle, tool = Path(directory) / 'app.aab', Path(directory) / 'tool.jar'
            bundle.write_bytes(b'synthetic bundle')
            tool.write_bytes(b'synthetic tool')
            h = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
            signer = 'a' * 64
            signature = {'signature_verified': True, 'sha256': h(bundle), 'upload_signer_sha256': signer}
            args = (bundle, tool, h(tool), signer, 'com.example.ev', '42')
            with patch('audit_aab_manifest.run', side_effect=[json.dumps(signature), '', XML]) as run:
                result = audit(*args)
                self.assertTrue(result['bundle_structure_verified'])
                self.assertFalse(result['derived_apks_verified'])
                self.assertEqual(result['sha256'], h(bundle))
                self.assertEqual(run.call_args_list[1].args[0][3], 'validate')
                self.assertEqual(run.call_args_list[2].args[0][3:5], ['dump', 'manifest'])
            with patch('audit_aab_manifest.run') as run, self.assertRaises(AuditError):
                audit(bundle, tool, 'b' * 64, signer, 'com.example.ev', '42')
            run.assert_not_called()
            with patch('audit_aab_manifest.run', return_value=json.dumps({**signature, 'sha256': '0' * 64})), self.assertRaises(AuditError):
                audit(*args)
            def changed(command):
                if 'validate' in command:
                    bundle.write_bytes(b'changed')
                    return ''
                return XML if 'dump' in command else json.dumps(signature)
            with patch('audit_aab_manifest.run', side_effect=changed), self.assertRaises(AuditError):
                audit(*args)


if __name__ == '__main__':
    unittest.main()
