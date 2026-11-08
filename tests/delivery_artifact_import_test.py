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
from delivery_artifact_import import import_actions, check_wrapper, valid_tar_name, stage_verified_wrapper, main

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

    def test_wrapper_copy_is_private_and_exactly_digest_verified(self):
        frozen=self.root/'immutable-reviewed.zip'
        assert stage_verified_wrapper(self.android, frozen, self.digest(self.android), 'app-debug.apk') == frozen
        self.assertEqual(hashlib.sha256(frozen.read_bytes()).hexdigest(),self.digest(self.android))
        if sys.platform!='win32':
            self.assertEqual(frozen.stat().st_mode & 0o077,0)
        with self.assertRaises(FileExistsError):
            stage_verified_wrapper(self.android, frozen, self.digest(self.android), 'app-debug.apk')

    def test_source_changes_after_freezing_cannot_change_imported_binary(self):
        from unittest.mock import patch
        original=stage_verified_wrapper
        calls=[]
        def after_freeze(source,target,digest,member):
            frozen=original(source,target,digest,member)
            calls.append(str(member))
            # Simulates an untrusted downloader overwriting its path immediately
            # after our checksum-verified descriptor snapshot was secured.
            Path(source).write_bytes(b'replaced by another download')
            return frozen
        with patch('delivery_artifact_import.stage_verified_wrapper',side_effect=after_freeze):
            receipt=self.import_bundle()
        self.assertEqual(calls,['app-debug.apk','hasone-ios-simulator.tar.gz'])
        self.assertEqual(receipt['source_sha'],SHA)
        self.assertEqual(verify(self.root/'bundle',SHA)['acceptance']['signed_for_store'],False)

    def test_invalid_or_replaced_download_fails_before_bundle_creation(self):
        from unittest.mock import patch
        with self.assertRaises(BundleError):
            stage_verified_wrapper(self.android,self.root/'rejected.zip','f'*64,'app-debug.apk')
        self.assertFalse((self.root/'rejected.zip').exists())
        self.android.write_bytes(b'tampered between GitHub check and import')
        with self.assertRaises(BundleError):
            self.import_bundle()
        self.assertFalse((self.root/'bundle').exists())

    def test_symlink_or_fifo_source_cannot_be_staged_as_reviewed_artifact(self):
        import os
        alias=self.root/'alias.zip'
        alias.symlink_to(self.android)
        with self.assertRaises(BundleError):
            stage_verified_wrapper(alias,self.root/'from-link.zip',self.digest(self.android),'app-debug.apk')
        if hasattr(os,'mkfifo'):
            fifo=self.root/'blocking.zip'
            os.mkfifo(fifo,0o600)
            with self.assertRaises(BundleError):
                stage_verified_wrapper(fifo,self.root/'from-fifo.zip','f'*64,'app-debug.apk')
        self.assertFalse((self.root/'from-link.zip').exists())
        self.assertFalse((self.root/'from-fifo.zip').exists())

    def test_source_replaced_with_symlink_at_open_fails_closed(self):
        from unittest.mock import patch
        import os
        real_open=os.open
        changed=[False]
        def substitution(path,flags,*args,**kwargs):
            if str(path)==str(self.android) and not changed[0]:
                changed[0]=True
                previous=self.root/'previous-original.zip'
                self.android.rename(previous)
                self.android.symlink_to(previous)
            return real_open(path,flags,*args,**kwargs)
        with patch('delivery_artifact_import.os.open',side_effect=substitution):
            with self.assertRaises(BundleError):
                stage_verified_wrapper(self.android,self.root/'safe-copy.zip',
                                       self.digest(self.android),'app-debug.apk')
        self.assertTrue(changed[0])
        self.assertFalse((self.root/'safe-copy.zip').exists())

    def test_cli_success_and_fail_closed(self):
        opts=['--output',str(self.root/'bundle'),'--source-sha',SHA,
              '--android-wrapper',str(self.android),'--android-digest',self.digest(self.android),
              '--ios-wrapper',str(self.ios),'--ios-digest',self.digest(self.ios)]
        self.assertEqual(main(opts),0)
        self.assertEqual(main(opts),1)

if __name__ == '__main__': unittest.main()
