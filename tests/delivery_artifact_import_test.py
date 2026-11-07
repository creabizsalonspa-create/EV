import hashlib
import io
from pathlib import Path
import tarfile
import tempfile
import unittest
import warnings
import zipfile
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from delivery_bundle import BundleError, verify
from delivery_artifact_import import import_actions, check_wrapper, valid_tar_name, main

SHA = 'b' * 40


class ActionsArtifactTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.android = self.root / 'android-wrapper.zip'
        self.ios = self.root / 'ios-wrapper.zip'
        with zipfile.ZipFile(self.android, 'w') as outer:
            inner = io.BytesIO()
            with zipfile.ZipFile(inner, 'w') as z:
                z.writestr('AndroidManifest.xml', 'valid')
                z.writestr('classes.dex', 'test')
            outer.writestr('app-debug.apk', inner.getvalue())
        self.make_ios({'Runner.app/Info.plist': b'plist', 'Runner.app/Runner': b'binary'})

    def make_ios(self, files):
        compressed = io.BytesIO()
        with tarfile.open(fileobj=compressed, mode='w:gz') as tar:
            for name, body in files.items():
                info = tarfile.TarInfo(name)
                info.size = len(body)
                tar.addfile(info, io.BytesIO(body))
        with zipfile.ZipFile(self.ios, 'w') as outer:
            outer.writestr('hasone-ios-simulator.tar.gz', compressed.getvalue())

    def digest(self, path):
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def import_bundle(self):
        return import_actions(self.root / 'bundle', SHA, self.android, self.digest(self.android), self.ios, self.digest(self.ios))

    def test_import_realistic_nested_actions_archives(self):
        result = self.import_bundle()
        self.assertEqual(result['source_sha'], SHA)
        self.assertEqual(verify(self.root/'bundle', SHA)['acceptance']['signed_for_store'], False)

    def test_reject_wrong_published_digest(self):
        with self.assertRaises(BundleError):
            import_actions(self.root / 'bundle', SHA, self.android, '0'*64, self.ios, self.digest(self.ios))
        self.assertFalse((self.root/'bundle').exists())

    def test_reject_wrong_wrapper_member(self):
        with zipfile.ZipFile(self.android, 'w') as z:
            z.writestr('unexpected.apk', 'fake')
        with self.assertRaises(BundleError): check_wrapper(self.android, self.digest(self.android), 'app-debug.apk')

    def test_reject_tar_traversal(self):
        self.make_ios({'../outside.txt': b'bad', 'Runner.app/Info.plist': b'plist'})
        with self.assertRaises(BundleError): self.import_bundle()
        self.assertFalse((self.root/'bundle').exists())

    def test_reject_tar_special_link(self):
        compressed = io.BytesIO()
        with tarfile.open(fileobj=compressed, mode='w:gz') as tar:
            item = tarfile.TarInfo('Runner.app/external')
            item.type = tarfile.SYMTYPE
            item.linkname = '../../outside'
            tar.addfile(item)
        with zipfile.ZipFile(self.ios, 'w') as out: out.writestr('hasone-ios-simulator.tar.gz', compressed.getvalue())
        with self.assertRaises(BundleError): self.import_bundle()

    def test_existing_bundle_is_never_overwritten(self):
        self.import_bundle()
        with self.assertRaises(BundleError): self.import_bundle()

    def test_reject_unsafe_raw_tar_names(self):
        for name in ('../secret', '/root/secret', 'folder/../secret', 'C:/secret', 'folder\\secret'):
            self.assertFalse(valid_tar_name(name))
        self.assertTrue(valid_tar_name('Runner.app/Info.plist'))

    def test_cli_success_and_fail_closed(self):
        opts=['--output',str(self.root/'bundle'),'--source-sha',SHA,
              '--android-wrapper',str(self.android),'--android-digest',self.digest(self.android),
              '--ios-wrapper',str(self.ios),'--ios-digest',self.digest(self.ios)]
        self.assertEqual(main(opts),0)
        self.assertEqual(main(opts),1)

if __name__ == '__main__': unittest.main()
