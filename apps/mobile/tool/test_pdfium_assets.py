"""Fail-closed native dependency verification, without executing a native binary."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

source = Path(__file__).resolve().parents[1] / 'third_party/pdfium_flutter/android/fetch_pdfium.py'
spec = importlib.util.spec_from_file_location('pdfium_assets', source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ArtifactBoundaryTest(unittest.TestCase):
    def test_cached_size_and_checksum_are_enforced(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'archive'
            path.write_bytes(b'synthetic')
            module.verify(path, hashlib.sha256(b'synthetic').hexdigest(), 9)
            for digest, size in [('0' * 64, 9), (hashlib.sha256(b'synthetic').hexdigest(), 8)]:
                with self.assertRaises(ValueError):
                    module.verify(path, digest, size)

    def test_unsupported_abi_cannot_create_output_or_start_a_download(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'output'
            with self.assertRaises(ValueError):
                module.fetch('unrecognized', Path(directory) / 'cache', output)
            self.assertFalse(output.exists())

    def test_only_regular_library_is_materialized_and_archive_paths_are_ignored(self):
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / 'test.tgz'
            with tarfile.open(archive, 'w:gz') as stream:
                for name, body in [('lib/libpdfium.so', b'synthetic library'), ('../escaped', b'untrusted')]:
                    member = tarfile.TarInfo(name)
                    member.size = len(body)
                    stream.addfile(member, io.BytesIO(body))
            output = Path(directory) / 'out'
            module.materialize(archive, output)
            self.assertEqual((output / 'libpdfium.so').read_bytes(), b'synthetic library')
            self.assertFalse((Path(directory) / 'escaped').exists())
            with tarfile.open(archive, 'w:gz') as stream:
                member = tarfile.TarInfo('lib/libpdfium.so')
                member.type = tarfile.SYMTYPE
                member.linkname = '/untrusted'
                stream.addfile(member)
            with self.assertRaises(ValueError):
                module.materialize(archive, output)

    def test_redirects_cannot_downgrade_tls_or_leave_release_origins(self):
        from urllib.request import Request
        handler=module.HttpsReleaseRedirect()
        for url in ('http://github.com/file','https://untrusted.example/file','https://user@github.com/file'):
            with self.assertRaises(ValueError):
                handler.redirect_request(Request('https://github.com/start'),None,302,'Found',{},url)

    def test_pins_cover_only_supported_android_abis(self):
        manifest = json.loads(source.with_name('artifacts.json').read_text())
        self.assertEqual(set(manifest['artifacts']), {'arm64-v8a', 'armeabi-v7a', 'x86', 'x86_64'})
        for artifact in manifest['artifacts'].values():
            self.assertRegex(artifact['sha256'], r'^[a-f0-9]{64}$')
            self.assertGreater(artifact['size'], 1000000)


if __name__ == '__main__':
    unittest.main()
