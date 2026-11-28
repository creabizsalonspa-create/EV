from datetime import datetime, timezone
import hashlib
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from audit_ipa_package import AuditError
import ipa_profile as gate


class ProfileTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.app = Path(temp.name)
        self.path = self.app / 'embedded.mobileprovision'
        self.path.write_bytes(b'synthetic profile')
        self.pin = hashlib.sha256(self.path.read_bytes()).hexdigest()
        self.team, self.bundle, self.leaf = 'ABC123DEF4', 'com.example.ev', b'leaf'
        self.now = datetime(2026, 11, 27, tzinfo=timezone.utc)
        self.profile = {'TeamIdentifier': [self.team], 'Platform': ['iOS'],
                        'CreationDate': datetime(2026, 1, 1), 'ExpirationDate': datetime(2027, 1, 1),
                        'DeveloperCertificates': [self.leaf], 'Entitlements': {
                            'application-identifier': self.team + '.' + self.bundle,
                            'com.apple.developer.team-identifier': self.team,
                            'get-task-allow': False, 'beta-reports-active': True}}

    def verify(self, **kwargs):
        with patch.object(gate, 'decode_profile', return_value=self.profile):
            return gate.profile_evidence(self.app, self.team, self.bundle, self.leaf,
                                         kwargs.get('pin', self.pin), now=self.now)

    def test_pinned_identity_does_not_claim_cms_or_all_entitlements(self):
        result = self.verify()
        self.assertTrue(result['profile_identity_and_validity_verified'])
        self.assertFalse(result['provisioning_verified'])
        self.assertFalse(result['profile_cms_trust_verified'])
        self.assertFalse(result['profile_all_entitlements_verified'])

    def test_rejects_unapproved_profile(self):
        with patch.object(gate, 'decode_profile') as decode, self.assertRaises(AuditError):
            gate.profile_evidence(self.app, self.team, self.bundle, self.leaf, '0' * 64)
        decode.assert_not_called()

    def test_rejects_mismatched_expired_or_non_store_profile(self):
        original = self.profile.copy()
        for field, value in [('TeamIdentifier', ['BAD123DEF4']), ('Platform', ['OSX']),
                             ('ExpirationDate', datetime(2026, 11, 27)),
                             ('CreationDate', datetime(2026, 12, 1)),
                             ('ProvisionedDevices', []), ('ProvisionsAllDevices', True),
                             ('DeveloperCertificates', [b'other'])]:
            with self.subTest(field=field), self.assertRaises(AuditError):
                self.profile = {**original, field: value}
                self.verify()
        for key, value in [('get-task-allow', True), ('beta-reports-active', False),
                           ('application-identifier', self.team + '.*')]:
            with self.subTest(key=key), self.assertRaises(AuditError):
                self.profile = {**original, 'Entitlements': {**original['Entitlements'], key: value}}
                self.verify()

    def test_mutation_rejected(self):
        def changed(_):
            self.path.write_bytes(b'other')
            return self.profile
        with patch.object(gate, 'decode_profile', side_effect=changed), self.assertRaises(AuditError):
            gate.profile_evidence(self.app, self.team, self.bundle, self.leaf, self.pin, now=self.now)


if __name__ == '__main__':
    unittest.main()
