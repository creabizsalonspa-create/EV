import json
import os
from pathlib import Path
import tempfile
import unittest
import zipfile
import sys
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

    def test_cli_success_and_failure(self):
        bundle = self.bundle()
        self.assertEqual(main(['verify','--bundle',str(bundle),'--expected-source-sha',SHA]),0)
        self.assertEqual(main(['verify','--bundle',str(bundle),'--expected-source-sha','b'*40]),1)

if __name__ == '__main__': unittest.main()
