import hashlib
from pathlib import Path
import plistlib
import stat
import tempfile
import unittest
import warnings
import zipfile

from audit_ipa_package import AuditError, audit


class IpaPackageTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.path = Path(temp.name) / 'fixture.ipa'
        self.info = {'CFBundleIdentifier': 'com.example.ev', 'CFBundleShortVersionString': '1.2.3',
                     'CFBundleVersion': '42', 'CFBundlePackageType': 'APPL',
                     'CFBundleSupportedPlatforms': ['iPhoneOS'], 'DTPlatformName': 'iphoneos',
                     'MinimumOSVersion': '15.0', 'CFBundleExecutable': 'Runner'}

    def make(self, changes=None, omit=None, extra=None):
        root = 'Payload/Runner.app/'
        files = {root + 'Info.plist': plistlib.dumps({**self.info, **(changes or {})}),
                 root + 'Runner': b'\xcf\xfa\xed\xfe' + b'fixture',
                 root + '_CodeSignature/CodeResources': b'fixture not a valid signature'}
        if omit:
            del files[root + omit]
        with zipfile.ZipFile(self.path, 'w') as archive:
            for name, value in files.items():
                archive.writestr(name, value)
            if extra:
                with warnings.catch_warnings():
                    warnings.simplefilter('ignore', UserWarning)
                    archive.writestr(*extra)

    def verify(self):
        return audit(self.path, 'com.example.ev', '1.2.3', '42')

    def test_identity_and_hash_without_signature_claim(self):
        self.make()
        evidence = self.verify()
        self.assertEqual(evidence['sha256'], hashlib.sha256(self.path.read_bytes()).hexdigest())
        self.assertTrue(evidence['package_structure_verified'])
        self.assertFalse(evidence['signature_verified'])
        self.assertEqual(evidence['minimum_ios'], '15.0')

    def test_wrong_identity_version_build_and_simulator_rejected(self):
        for changes in ({'CFBundleIdentifier': 'com.other.ev'}, {'CFBundleVersion': '43'},
                        {'CFBundleShortVersionString': '1.2.4'}, {'CFBundleSupportedPlatforms': ['iPhoneSimulator']},
                        {'DTPlatformName': 'iphonesimulator'}, {'CFBundleExecutable': '../Runner'}):
            with self.subTest(changes=changes):
                self.make(changes)
                with self.assertRaises(AuditError):
                    self.verify()
        self.make()
        with self.assertRaises(AuditError):
            audit(self.path, 'com.example.DEV', '1.2.3', '42')

    def test_missing_required_files(self):
        for name in ('Info.plist', 'Runner', '_CodeSignature/CodeResources'):
            with self.subTest(name=name):
                self.make(omit=name)
                with self.assertRaises(AuditError):
                    self.verify()

    def test_unsafe_archive_paths_duplicate_apps_and_special_entries(self):
        symlink = zipfile.ZipInfo('Payload/Runner.app/link')
        symlink.create_system = 3
        symlink.external_attr = (stat.S_IFLNK | 0o777) << 16
        for name in ('../outside', '/absolute', 'Payload/Runner.app/Info.plist',
                     'payload/runner.app/info.plist', 'Payload/Other.app/Info.plist', symlink):
            with self.subTest(name=name):
                self.make(extra=(name, b'fixture'))
                with self.assertRaises(AuditError):
                    self.verify()


if __name__ == '__main__':
    unittest.main()
