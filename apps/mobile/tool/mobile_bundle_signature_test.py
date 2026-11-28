"""Exercise the real JDK verifier with ephemeral synthetic bundles, not release keys."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import warnings
import zipfile

ROOT = Path(__file__).resolve().parent
AUDIT = ROOT / "AuditMobileBundleSignature.java"


@unittest.skipUnless(all(shutil.which(tool) for tool in ("java", "javac", "keytool", "jarsigner")), "JDK tools required")
class MobileBundleSignatureTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.addClassCleanup(cls.temp.cleanup)
        cls.root = Path(cls.temp.name)
        cls.run_tool("javac", "-d", str(cls.root), str(AUDIT))
        cls.store = cls.root / "fixture.p12"
        cls.signers = {}
        for alias, name in (("upload", "Synthetic Upload"), ("other", "Other Fixture"), ("debug", "Android Debug")):
            cls.run_tool("keytool", "-J-Djava.security.egd=file:/dev/urandom", "-genkeypair", "-noprompt", "-alias", alias, "-dname", "CN=" + name,
                         "-keyalg", "RSA", "-keysize", "2048", "-validity", "2", "-storetype", "PKCS12",
                         "-keystore", str(cls.store), "-storepass", "fixture-only", "-keypass", "fixture-only")
            cert = cls.run_tool("keytool", "-exportcert", "-alias", alias, "-keystore", str(cls.store), "-storepass", "fixture-only").stdout
            cls.signers[alias] = hashlib.sha256(cert).hexdigest()
        cls.unsigned = cls.root / "unsigned.aab"
        with zipfile.ZipFile(cls.unsigned, "w") as bundle:
            bundle.writestr("BundleConfig.pb", b"synthetic config")
            bundle.writestr("base/manifest/AndroidManifest.xml", b"synthetic manifest")
            bundle.writestr("base/dex/classes.dex", b"synthetic code")
        cls.valid = cls.signed_copy("upload.aab", "upload")

    @staticmethod
    def run_tool(*args):
        # Hosted macOS can be slow to initialize a JDK security provider.
        # Never allow fixture tools to prompt for a password or wait for input.
        return subprocess.run(args, check=True, stdin=subprocess.DEVNULL, capture_output=True, timeout=120)

    @classmethod
    def signed_copy(cls, name, *aliases):
        target = cls.root / name
        shutil.copyfile(cls.unsigned, target)
        for alias in aliases:
            cls.run_tool("jarsigner", "-keystore", str(cls.store), "-storepass", "fixture-only", str(target), alias)
        return target

    def audit(self, bundle, signer="upload"):
        return subprocess.run(("java", "-cp", str(self.root), "AuditMobileBundleSignature", str(bundle), self.signers[signer]), capture_output=True, text=True, timeout=30)

    def test_exact_upload_signer_and_hash(self):
        result = self.audit(self.valid)
        self.assertEqual(result.returncode, 0, result.stderr)
        evidence = json.loads(result.stdout)
        self.assertEqual(evidence, {"signature_verified": True, "signed_entries": 3, "sha256": hashlib.sha256(self.valid.read_bytes()).hexdigest(), "upload_signer_sha256": self.signers["upload"]})

    def test_unsigned_wrong_debug_and_multiple_signers_fail(self):
        for bundle, signer in ((self.unsigned, "upload"), (self.valid, "other"), (self.signed_copy("debug.aab", "debug"), "debug"), (self.signed_copy("multi.aab", "upload", "other"), "upload")):
            with self.subTest(bundle=bundle.name):
                result = self.audit(bundle, signer)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, "")

    def test_tampered_unsigned_appended_and_duplicate_entries_fail(self):
        appended = self.root / "appended.aab"
        shutil.copyfile(self.valid, appended)
        with zipfile.ZipFile(appended, "a") as bundle:
            bundle.writestr("base/dex/unsigned.dex", b"unsigned")
        tampered = self.root / "tampered.aab"
        with zipfile.ZipFile(self.valid) as original, zipfile.ZipFile(tampered, "w") as changed:
            for entry in original.infolist():
                data = original.read(entry)
                changed.writestr(entry, b"modified" if entry.filename == "base/dex/classes.dex" else data)
        duplicate = self.root / "duplicate.aab"
        shutil.copyfile(self.valid, duplicate)
        with warnings.catch_warnings(), zipfile.ZipFile(duplicate, "a") as bundle:
            warnings.simplefilter("ignore", UserWarning)
            bundle.writestr("base/dex/classes.dex", b"synthetic code")
        for bundle in (appended, tampered, duplicate):
            with self.subTest(bundle=bundle.name):
                self.assertNotEqual(self.audit(bundle).returncode, 0)


if __name__ == "__main__":
    unittest.main()
