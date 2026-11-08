import json
import datetime as dt
import os
import stat
from pathlib import Path
import tempfile
import unittest
import zipfile
import sys
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from delivery_bundle import BundleError, create, verify, archive_check, main

SHA = 'a' * 40

class DeliveryBundleTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.android = self.root / 'input.apk'
        self.ios = self.root / 'input.zip'
        self.write_zip(self.android, {'AndroidManifest.xml': '<manifest/>', 'classes.dex': 'test'})
        self.write_zip(self.ios, {'Runner.app/Info.plist': '<plist/>', 'Runner.app/Runner': 'binary'})

    @staticmethod
    def write_zip(path, items):
        with zipfile.ZipFile(path, 'w', compression=zipfile.ZIP_DEFLATED) as z:
            for name, value in items.items():
                z.writestr(name, value)

    def bundle(self):
        target = self.root / 'delivery'
        create(target, SHA, self.android, self.ios)
        return target

    def test_create_and_verify_both_platforms(self):
        bundle = self.bundle()
        self.assertEqual(verify(bundle, SHA)['source_sha'], SHA)
        self.assertEqual(len(list(bundle.iterdir())), 3)

    @unittest.skipIf(os.name == 'nt', 'POSIX ownership mode not available')
    def test_verify_refuses_world_readable_bundle_directory(self):
        bundle = self.bundle()
        bundle.chmod(0o755)
        with self.assertRaises(BundleError):
            verify(bundle, SHA)

    @unittest.skipIf(os.name == 'nt', 'POSIX ownership mode not available')
    def test_verify_refuses_world_readable_manifest_or_binary(self):
        for relative in ('manifest.json', 'android-development.apk', 'ios-simulator.zip'):
            bundle = self.root / ('delivery-' + relative.split('.')[0])
            create(bundle, SHA, self.android, self.ios)
            artifact = bundle / relative
            artifact.chmod(0o644)
            with self.subTest(relative=relative), self.assertRaises(BundleError):
                verify(bundle, SHA)

    def test_reject_malformed_or_future_packaging_timestamp(self):
        for label, value in [
            ('non-date', 'unknownZ'),
            ('impossible month', '2026-13-01T00:00:00Z'),
            ('missing timezone', '2026-01-01T00:00:00'),
            ('UTC offset instead of canonical Z', '2026-01-01T00:00:00+00:00'),
            ('future time', (dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ')),
        ]:
            bundle = self.root / ('timestamp-' + label.replace(' ', '-'))
            create(bundle, SHA, self.android, self.ios)
            file = bundle / 'manifest.json'
            m = json.loads(file.read_text())
            m['created_at_utc'] = value
            file.write_text(json.dumps(m))
            with self.subTest(label=label), self.assertRaises(BundleError):
                verify(bundle, SHA)

    def test_reject_wrong_source_sha(self):
        bundle = self.bundle()
        with self.assertRaises(BundleError): verify(bundle, 'b' * 40)

    def test_reject_overwrite(self):
        self.bundle()
        with self.assertRaises(BundleError): create(self.root/'delivery', SHA, self.android, self.ios)

    def test_reject_tampered_digest(self):
        bundle = self.bundle()
        p = bundle/'android-development.apk'
        p.write_bytes(p.read_bytes()+b'junk')
        with self.assertRaises(BundleError): verify(bundle, SHA)

    def test_reject_traversal_archive(self):
        self.write_zip(self.android, {'../outside': 'bad', 'AndroidManifest.xml':'ok'})
        with self.assertRaises(BundleError): create(self.root/'delivery', SHA, self.android, self.ios)
        self.assertFalse((self.root/'delivery').exists())

    def test_reject_duplicate_archive_member(self):
        with zipfile.ZipFile(self.android, 'w') as z:
            z.writestr('AndroidManifest.xml', 'a')
            z.writestr('AndroidManifest.xml', 'b')
        with self.assertRaises(BundleError): archive_check(self.android, 'android-debug')

    def test_reject_missing_platform_identity(self):
        self.write_zip(self.ios, {'Info.plist':'not an app'})
        with self.assertRaises(BundleError): create(self.root/'delivery', SHA, self.android, self.ios)

    def test_reject_symlink_artifact(self):
        link = self.root/'link.apk'
        link.symlink_to(self.android)
        with self.assertRaises(BundleError): create(self.root/'delivery', SHA, link, self.ios)

    def test_reject_manifest_fake_store_signing(self):
        bundle = self.bundle()
        manifest = bundle/'manifest.json'
        data = json.loads(manifest.read_text())
        data['acceptance']['signed_for_store'] = True
        manifest.write_text(json.dumps(data))
        with self.assertRaises(BundleError): verify(bundle, SHA)

    def test_reject_extra_file(self):
        bundle = self.bundle()
        (bundle/'secrets.txt').write_text('unexpected')
        with self.assertRaises(BundleError): verify(bundle, SHA)

    def test_reject_corrupt_archive_crc_even_with_updated_manifest(self):
        with zipfile.ZipFile(self.ios, 'w', compression=zipfile.ZIP_STORED) as z:
            z.writestr('Runner.app/Info.plist', '<plist/>')
            z.writestr('Runner.app/Runner', 'binary')
        bundle = self.bundle()
        artifact = bundle/'ios-simulator.zip'
        damaged = bytearray(artifact.read_bytes())
        pos = damaged.find(b'<plist/>')
        self.assertGreaterEqual(pos, 0)
        damaged[pos] ^= 0x01
        artifact.write_bytes(damaged)
        import hashlib
        mfile = bundle/'manifest.json'
        data = json.loads(mfile.read_text())
        item = next(x for x in data['artifacts'] if x['platform']=='ios-simulator')
        item['sha256'] = hashlib.sha256(damaged).hexdigest()
        mfile.write_text(json.dumps(data))
        with self.assertRaises(BundleError): verify(bundle, SHA)

    def test_duplicate_delivery_manifest_members_rejected_even_if_last_value_is_safe(self):
        bundle = self.bundle()
        manifest = bundle / 'manifest.json'
        original = manifest.read_text()
        # Last JSON key wins with Python's default decoder; fail closed instead.
        manifest.write_text('{"schema":"untrusted",' + original.lstrip()[1:])
        with self.assertRaisesRegex(BundleError, 'Duplicate delivery manifest'):
            verify(bundle, SHA)

    def test_duplicate_nested_acceptance_members_rejected(self):
        bundle = self.bundle()
        manifest = bundle / 'manifest.json'
        original = manifest.read_text()
        expected = '"production_accepted": false'
        self.assertIn(expected, original)
        manifest.write_text(original.replace(
            expected, '"production_accepted": true, ' + expected, 1))
        with self.assertRaisesRegex(BundleError, 'Duplicate delivery manifest'):
            verify(bundle, SHA)

    def test_nan_and_infinity_delivery_manifest_rejected_at_parse_boundary(self):
        for constant in ('NaN', 'Infinity', '-Infinity'):
            bundle = self.root / ('delivery-' + constant.replace('-', 'negative'))
            create(bundle, SHA, self.android, self.ios)
            manifest = bundle / 'manifest.json'
            text = manifest.read_text()
            manifest.write_text(text.replace('"size_bytes": ', '"size_bytes": ' +
                                             constant + ', "size_bytes": ', 1))
            with self.subTest(constant=constant), self.assertRaisesRegex(
                    BundleError, 'Nonstandard numeric constant'):
                verify(bundle, SHA)

    def test_manifest_hardlink_rejected_even_if_bytes_and_modes_match(self):
        bundle = self.bundle()
        original = bundle / 'manifest.json'
        os.link(original, self.root / 'alias-manifest.json')
        with self.assertRaisesRegex(BundleError, 'permissions, type or size'):
            verify(bundle, SHA)

    def test_manifest_atomic_replacement_mid_validation_cannot_be_accepted(self):
        bundle = self.bundle()
        original = bundle / 'manifest.json'
        real_archive_check = archive_check
        changed = []

        def swap(path, platform):
            if not changed:
                changed.append(True)
                fresh = self.root / 'replacement-manifest.json'
                fresh.write_bytes(original.read_bytes())
                fresh.chmod(0o600)
                fresh.replace(original)
            return real_archive_check(path, platform)

        with patch('delivery_bundle.archive_check', side_effect=swap):
            with self.assertRaisesRegex(BundleError, 'changed while verifying'):
                verify(bundle, SHA)
        self.assertTrue(changed)

    def test_manifest_changed_during_crc_verification_cannot_be_accepted(self):
        bundle = self.bundle()
        original = bundle / 'manifest.json'
        real_archive_check = archive_check
        changed = []

        def mutate(path, platform):
            if not changed:
                changed.append(True)
                original.write_bytes(original.read_bytes() + b' ')
                original.chmod(0o600)
            return real_archive_check(path, platform)

        with patch('delivery_bundle.archive_check', side_effect=mutate):
            with self.assertRaisesRegex(BundleError, 'changed while verifying'):
                verify(bundle, SHA)

    def test_developer_artifact_atomic_replace_after_crc_cannot_pass(self):
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        actual_archive_check = archive_check
        count = []

        def replace_after_crc(stream, platform):
            self.assertTrue(hasattr(stream, 'fileno'))
            result = actual_archive_check(stream, platform)
            if platform == 'android-debug' and not count:
                count.append(True)
                replacement = self.root / 'same-bytes-new-inode.apk'
                replacement.write_bytes(apk.read_bytes())
                replacement.chmod(0o600)
                replacement.replace(apk)
            return result

        with patch('delivery_bundle.archive_check', side_effect=replace_after_crc):
            with self.assertRaisesRegex(BundleError, 'changed during hash or CRC'):
                verify(bundle, SHA)
        self.assertTrue(count)

    def test_developer_artifact_byte_mutation_after_crc_rejected(self):
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        actual_archive_check = archive_check

        def change_after_crc(stream, platform):
            result = actual_archive_check(stream, platform)
            if platform == 'android-debug':
                apk.write_bytes(apk.read_bytes() + b'ignored trailing bytes')
                apk.chmod(0o600)
            return result

        with patch('delivery_bundle.archive_check', side_effect=change_after_crc):
            with self.assertRaisesRegex(BundleError, 'changed during hash or CRC'):
                verify(bundle, SHA)

    def test_developer_artifact_symlink_swap_after_hash_rejected(self):
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        actual_archive_check = archive_check
        original = self.root / 'unchanged-apk'

        def swap_path_after_hash(stream, platform):
            if platform == 'android-debug':
                apk.replace(original)
                apk.symlink_to(original)
            return actual_archive_check(stream, platform)

        with patch('delivery_bundle.archive_check', side_effect=swap_path_after_hash):
            with self.assertRaisesRegex(BundleError, 'changed during hash or CRC'):
                verify(bundle, SHA)

    def test_developer_artifact_hardlink_rejected_even_with_valid_checksums(self):
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        os.link(apk, self.root / 'linked-apk')
        with self.assertRaisesRegex(BundleError, 'permissions, size or type'):
            verify(bundle, SHA)

    def test_create_rejects_source_symlink_swap_after_preflight_crc(self):
        old = self.root / 'original-downloaded-apk'
        real_archive_check = archive_check
        switched = []

        def replace_after_preflight(arg, platform):
            result = real_archive_check(arg, platform)
            if platform == 'android-debug' and isinstance(arg, Path) and not switched:
                switched.append(True)
                self.android.replace(old)
                self.android.symlink_to(old)
            return result

        with patch('delivery_bundle.archive_check', side_effect=replace_after_preflight):
            with self.assertRaisesRegex(BundleError, 'cannot be opened securely'):
                create(self.root / 'delivery', SHA, self.android, self.ios)
        self.assertTrue(switched)
        self.assertFalse((self.root / 'delivery').exists())

    def test_create_rejects_source_mutation_during_copy_and_cleans_output(self):
        import shutil
        real_copy = shutil.copyfileobj
        changed = []

        def mutate_after_copy(input_file, output_file, length):
            result = real_copy(input_file, output_file, length)
            if not changed:
                changed.append(True)
                self.android.write_bytes(self.android.read_bytes() + b'junk')
            return result

        with patch('delivery_bundle.shutil.copyfileobj', side_effect=mutate_after_copy):
            with self.assertRaisesRegex(BundleError, 'changed during copy'):
                create(self.root / 'delivery', SHA, self.android, self.ios)
        self.assertTrue(changed)
        self.assertFalse((self.root / 'delivery').exists())

    def test_create_rejects_same_byte_source_replacement_during_copy(self):
        import shutil
        real_copy = shutil.copyfileobj
        changed = []

        def replace_after_copy(input_file, output_file, length):
            result = real_copy(input_file, output_file, length)
            if not changed:
                changed.append(True)
                replacement = self.root / 'new-downloaded-apk'
                replacement.write_bytes(self.android.read_bytes())
                replacement.replace(self.android)
            return result

        with patch('delivery_bundle.shutil.copyfileobj', side_effect=replace_after_copy):
            with self.assertRaisesRegex(BundleError, 'changed during copy'):
                create(self.root / 'delivery', SHA, self.android, self.ios)
        self.assertTrue(changed)
        self.assertFalse((self.root / 'delivery').exists())

    def test_create_rejects_hardlinked_input_despite_valid_archive_crc(self):
        os.link(self.android, self.root / 'download-alias.apk')
        with self.assertRaisesRegex(BundleError, 'single-link file'):
            create(self.root / 'delivery', SHA, self.android, self.ios)
        self.assertFalse((self.root / 'delivery').exists())

    def test_first_platform_atomic_replacement_during_second_audit_rejected(self):
        import delivery_bundle
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        original = delivery_bundle.secure_artifact_audit
        changed = []

        def switch_after_ios_audit(path, platform):
            result = original(path, platform)
            if platform == 'ios-simulator' and not changed:
                changed.append(True)
                replacement = self.root / 'identical-second-apk'
                replacement.write_bytes(apk.read_bytes())
                replacement.chmod(0o600)
                replacement.replace(apk)
            return result

        with patch('delivery_bundle.secure_artifact_audit', side_effect=switch_after_ios_audit):
            with self.assertRaisesRegex(BundleError, 'Previously checked delivery artifact changed'):
                verify(bundle, SHA)
        self.assertTrue(changed)

    def test_first_platform_modified_during_second_audit_rejected(self):
        import delivery_bundle
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        original = delivery_bundle.secure_artifact_audit
        changed = []

        def mutate_after_ios_audit(path, platform):
            result = original(path, platform)
            if platform == 'ios-simulator' and not changed:
                changed.append(True)
                previous = bytearray(apk.read_bytes())
                previous[0] ^= 0x01
                apk.write_bytes(previous)
                apk.chmod(0o600)
            return result

        with patch('delivery_bundle.secure_artifact_audit', side_effect=mutate_after_ios_audit):
            with self.assertRaisesRegex(BundleError, 'Previously checked delivery artifact changed'):
                verify(bundle, SHA)
        self.assertTrue(changed)

    def test_first_platform_symlinked_after_its_audit_rejected(self):
        import delivery_bundle
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        original = delivery_bundle.secure_artifact_audit
        changed = []

        def symlink_after_ios_audit(path, platform):
            result = original(path, platform)
            if platform == 'ios-simulator' and not changed:
                changed.append(True)
                destination = self.root / 'stored-original-apk'
                apk.replace(destination)
                apk.symlink_to(destination)
            return result

        with patch('delivery_bundle.secure_artifact_audit', side_effect=symlink_after_ios_audit):
            with self.assertRaisesRegex(BundleError, 'Previously checked delivery artifact changed'):
                verify(bundle, SHA)
        self.assertTrue(changed)

    def test_first_platform_hardlinked_after_its_audit_rejected(self):
        import delivery_bundle
        bundle = self.bundle()
        apk = bundle / 'android-development.apk'
        original = delivery_bundle.secure_artifact_audit
        changed = []

        def link_after_ios_audit(path, platform):
            result = original(path, platform)
            if platform == 'ios-simulator' and not changed:
                changed.append(True)
                os.link(apk, self.root / 'unexpected-second-link.apk')
            return result

        with patch('delivery_bundle.secure_artifact_audit', side_effect=link_after_ios_audit):
            with self.assertRaisesRegex(BundleError, 'Previously checked delivery artifact changed'):
                verify(bundle, SHA)
        self.assertTrue(changed)

    @staticmethod
    def archive_symlink(name, target):
        member = zipfile.ZipInfo(name)
        member.create_system = 3
        member.external_attr = (stat.S_IFLNK | 0o777) << 16
        return member, target

    def test_android_identity_must_not_be_an_archive_symlink(self):
        manifest_link, value = self.archive_symlink('AndroidManifest.xml', 'classes.dex')
        with zipfile.ZipFile(self.android, 'w') as archive:
            archive.writestr(manifest_link, value)
            archive.writestr('classes.dex', b'body')
        with self.assertRaisesRegex(BundleError, 'Platform identity must be'):
            archive_check(self.android, 'android-debug')

    def test_ios_identity_must_not_be_an_archive_symlink(self):
        plist_link, value = self.archive_symlink('Runner.app/Info.plist', 'Runner')
        with zipfile.ZipFile(self.ios, 'w') as archive:
            archive.writestr(plist_link, value)
            archive.writestr('Runner.app/Runner', b'body')
        with self.assertRaisesRegex(BundleError, 'Platform identity must be'):
            archive_check(self.ios, 'ios-simulator')

    def test_ios_identity_file_cannot_be_empty(self):
        self.write_zip(self.ios, {'Runner.app/Info.plist':'',
                                  'Runner.app/Runner':'body'})
        with self.assertRaisesRegex(BundleError, 'Platform identity must be'):
            archive_check(self.ios, 'ios-simulator')

    def test_reject_oversized_simulator_symlink_before_reading_body(self):
        link, target = self.archive_symlink('Runner.app/Frameworks/Current', 'x' * 4097)
        with zipfile.ZipFile(self.ios, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
            archive.writestr('Runner.app/Info.plist', b'<plist/>')
            archive.writestr('Runner.app/Runner', b'body')
            archive.writestr(link, target)
        with self.assertRaisesRegex(BundleError, 'symlink target exceeds safe length'):
            archive_check(self.ios, 'ios-simulator')

    def test_valid_short_relative_simulator_symlink_remains_allowed(self):
        link, target = self.archive_symlink('Runner.app/Frameworks/Current', 'Versions/A')
        with zipfile.ZipFile(self.ios, 'w') as archive:
            archive.writestr('Runner.app/Info.plist', b'<plist/>')
            archive.writestr('Runner.app/Runner', b'body')
            archive.writestr(link, target)
        self.assertIsNone(archive_check(self.ios, 'ios-simulator'))

    def test_cli_success_and_failure(self):
        bundle = self.bundle()
        self.assertEqual(main(['verify','--bundle',str(bundle),'--expected-source-sha',SHA]),0)
        self.assertEqual(main(['verify','--bundle',str(bundle),'--expected-source-sha','b'*40]),1)

if __name__ == '__main__': unittest.main()
