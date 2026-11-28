import hashlib
from datetime import datetime
from pathlib import Path
import plistlib
import struct
import tempfile
import unittest
from unittest.mock import patch
import zipfile

from audit_ipa_package import AuditError
import audit_ipa_signature as gate


class IpaSignatureTest(unittest.TestCase):
    bundle = 'com.example.ev'
    team = 'ABC123DEF4'
    cert = b'synthetic certificate bytes'
    pin = hashlib.sha256(cert).hexdigest()

    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.path = Path(temp.name) / 'release.ipa'
        self.info = {'CFBundleIdentifier': self.bundle,
                     'CFBundleShortVersionString': '1.2.3', 'CFBundleVersion': '42',
                     'CFBundlePackageType': 'APPL', 'CFBundleSupportedPlatforms': ['iPhoneOS'],
                     'DTPlatformName': 'iphoneos', 'MinimumOSVersion': '15.0',
                     'CFBundleExecutable': 'Runner'}
        self.entitlements = {'application-identifier': self.team + '.' + self.bundle,
                             'com.apple.developer.team-identifier': self.team,
                             'get-task-allow': False}
        self.calls = []
        self.binary = struct.pack('<8I6I', 0xfeedfacf, 0x0100000c, 0, 2, 1, 24, 0, 0,
                                  0x32, 24, 2, 15 << 16, 18 << 16, 0)
        self.make()

    def make(self, extra=()):
        root = 'Payload/Runner.app/'
        with zipfile.ZipFile(self.path, 'w') as archive:
            archive.writestr(root + 'Info.plist', plistlib.dumps(self.info))
            runner = zipfile.ZipInfo(root + 'Runner')
            runner.create_system = 3
            runner.external_attr = 0o100755 << 16
            archive.writestr(runner, self.binary)
            archive.writestr(root + '_CodeSignature/CodeResources', b'not a real signature')
            for name, value in extra:
                archive.writestr(root + name, value)

    def fake_codesign(self, args):
        self.calls.append(args)
        if args[0] == '--verify':
            self.assertIn('--deep', args)
            self.assertIn('--strict', args)
            self.assertIn('anchor apple generic', args[3])
            self.assertIn(self.team, args[3])
            self.assertIn(self.bundle, args[3])
            self.assertTrue((Path(args[-1]) / 'Runner').stat().st_mode & 0o100)
            return b''
        if args[1].startswith('--extract-certificates='):
            Path(args[1].split('=', 1)[1] + '0').write_bytes(self.cert)
            return b''
        return plistlib.dumps(self.entitlements)

    def verify(self, pin=None):
        with patch.object(gate.sys, 'platform', 'darwin'), \
                patch.object(gate, 'run_codesign', side_effect=self.fake_codesign):
            return gate.verify_signature(self.path, self.bundle, '1.2.3', '42',
                                         self.team, self.pin if pin is None else pin)

    def test_pinned_mock_signature_and_exact_hash(self):
        result = self.verify()
        self.assertTrue(result['signature_verified'])
        self.assertTrue(result['main_executable_device_architecture_verified'])
        self.assertFalse(result['nested_executable_architectures_verified'])
        self.assertFalse(result['provisioning_verified'])
        self.assertFalse(result['store_accepted'])
        self.assertEqual(result['sha256'], hashlib.sha256(self.path.read_bytes()).hexdigest())
        self.assertEqual(result['signing_certificate_sha256'], self.pin)
        self.assertEqual(len(self.calls), 3)

    def test_pinned_profile_uses_same_extracted_signed_bundle(self):
        raw = b'synthetic profile'
        self.make([('embedded.mobileprovision', raw)])
        pin = hashlib.sha256(raw).hexdigest()
        profile = {'TeamIdentifier': [self.team], 'Platform': ['iOS'],
                   'CreationDate': datetime(2020, 1, 1),
                   'ExpirationDate': datetime(2099, 1, 1),
                   'DeveloperCertificates': [self.cert],
                   'Entitlements': {**self.entitlements, 'beta-reports-active': True}}
        def decode(path):
            self.assertEqual(path.parent, Path(self.calls[0][-1]))
            self.assertEqual(path.read_bytes(), raw)
            return profile
        with patch.object(gate.sys, 'platform', 'darwin'), \
                patch.object(gate, 'run_codesign', side_effect=self.fake_codesign), \
                patch('ipa_profile.decode_profile', side_effect=decode):
            result = gate.verify_signature(self.path, self.bundle, '1.2.3', '42',
                                           self.team, self.pin, pin)
        self.assertTrue(result['profile_identity_and_validity_verified'])
        self.assertEqual(result['profile_sha256'], pin)
        self.assertFalse(result['provisioning_verified'])
        self.assertFalse(result['store_accepted'])

    def test_device_platform_and_command_bounds(self):
        original = self.binary
        for index, value in [(1, 0x01000007), (3, 6), (4, 2), (5, 0xffffffff),
                             (8, 0x25), (9, 16), (10, 7), (11, 19 << 16),
                             (12, 14 << 16), (13, 1)]:
            with self.subTest(index=index, value=value):
                fields = list(struct.unpack('<14I', original))
                fields[index] = value
                self.binary = struct.pack('<14I', *fields)
                self.make()
                with self.assertRaises(AuditError):
                    self.verify()
                self.assertEqual(self.calls, [])
        self.binary = original[:-1]
        self.make()
        with self.assertRaises(AuditError):
            self.verify()
        self.assertEqual(self.calls, [])

    def test_no_mac_or_wrong_pin_fails_closed(self):
        with patch.object(gate.sys, 'platform', 'linux'):
            with self.assertRaises(AuditError):
                gate.verify_signature(self.path, self.bundle, '1.2.3', '42', self.team, self.pin)
        with self.assertRaises(AuditError):
            self.verify('0' * 64)

    def test_entitlement_mismatch_and_debug_rejected(self):
        original = self.entitlements.copy()
        for key, value in [('application-identifier', 'BAD123DEF4.' + self.bundle),
                           ('com.apple.developer.team-identifier', 'BAD123DEF4'),
                           ('get-task-allow', True), ('get-task-allow', 0)]:
            with self.subTest(key=key, value=value):
                self.entitlements = {**original, key: value}
                with self.assertRaises(AuditError):
                    self.verify()

    def test_collision_and_oversized_snapshot_rejected_before_codesign(self):
        self.make([('Resource/file', b'a'), ('resource/other', b'b')])
        with self.assertRaises(AuditError):
            self.verify()
        self.assertEqual(self.calls, [])
        self.make()
        with patch.object(gate, 'MAX_ARCHIVE', 10):
            with self.assertRaises(AuditError):
                self.verify()
        self.assertEqual(self.calls, [])

    def test_codesign_rejection_is_not_evidence(self):
        with patch.object(gate.sys, 'platform', 'darwin'), \
                patch.object(gate.subprocess, 'run') as run:
            run.return_value.returncode = 1
            with self.assertRaises(AuditError):
                gate.verify_signature(self.path, self.bundle, '1.2.3', '42', self.team, self.pin)

    def test_mutated_original_is_not_evidence(self):
        original = self.fake_codesign

        def mutate(args):
            output = original(args)
            if args[1] == '--entitlements':
                self.path.write_bytes(b'mutated')
            return output

        with patch.object(gate.sys, 'platform', 'darwin'), \
                patch.object(gate, 'run_codesign', side_effect=mutate):
            with self.assertRaises(AuditError):
                gate.verify_signature(self.path, self.bundle, '1.2.3', '42', self.team, self.pin)


if __name__ == '__main__':
    unittest.main()
