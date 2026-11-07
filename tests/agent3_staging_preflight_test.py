"""Agent 3 staging policy regressions: invalid settings must fail closed."""
import base64
import importlib.util
from pathlib import Path
import os
import secrets
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('preflight', ROOT/'scripts/staging-release-preflight.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)

def valid():
    return {
        'APP_KEY': 'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),
        'DB_PASSWORD': 'Nonsensitive-LongComplex-FIXTURE-'+secrets.token_hex(20),
        'STAGING_HOST': 'staging.qa.creabiz.example-services.com',
        'ACME_EMAIL': 'operator@creabiz.example-services.com',
        'STAGING_BIND_IP': '127.0.0.1',
        'EV_IMAGE_TAG': 'sha-'+'a'*40,
        **{key:'false' for key in p.DISABLED},
    }

class PreflightTests(unittest.TestCase):
    def test_valid_synthetic_env_and_actual_static_config(self):
        p.validate_env(valid())
        self.assertIn('gateway', p.check_policy(ROOT))
        self.assertIs(p.audit(ROOT)['external_provider_or_production_accepted'], False)

    def test_denies_public_bindings_debug_and_mutable_tags(self):
        changes = [
            ('APP_KEY', 'invalid'), ('DB_PASSWORD', 'password'),
            ('STAGING_HOST', 'localhost'), ('STAGING_HOST', 'example.com'),
            ('STAGING_HOST', 'staging.example.invalid'),
            ('STAGING_BIND_IP', '0.0.0.0'), ('STAGING_BIND_IP', '::'),
            ('EV_IMAGE_TAG', 'latest'), ('EV_IMAGE_TAG', 'local'),
            ('ACME_EMAIL', 'support@example.com'),
            ('APP_DEBUG', 'true'), ('SESSION_SECURE_COOKIE', 'false'),
            ('SESSION_ENCRYPT', 'false'), ('APP_ENV', 'production'),
        ] + [(name, 'true') for name in p.DISABLED]
        for key, value in changes:
            with self.subTest(key=key, value=value):
                env = valid()
                env[key] = value
                with self.assertRaises(p.PreflightError):
                    p.validate_env(env)

    def test_missing_required_fields(self):
        for key in p.REQUIRED:
            env=valid()
            del env[key]
            with self.subTest(key=key), self.assertRaises(p.PreflightError):
                p.validate_env(env)

    def test_file_permissions_duplicates_and_symlink_denied(self):
        with tempfile.TemporaryDirectory() as d:
            file=Path(d)/'env'
            file.write_text('APP_KEY=one\nAPP_KEY=two\n')
            os.chmod(file, 0o600)
            with self.assertRaises(p.PreflightError):
                p.read_env(file)
            file.write_text('APP_KEY=one\n')
            os.chmod(file, 0o644)
            with self.assertRaises(p.PreflightError):
                p.read_env(file)
            link=Path(d)/'link'
            link.symlink_to(file)
            with self.assertRaises(p.PreflightError):
                p.read_env(link)

    def test_symlink_swap_before_open_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            candidate = root/'env'
            safe_copy = root/'source.env'
            candidate.write_text('APP_KEY=not-a-real-key\n')
            os.chmod(candidate, 0o600)
            real_open = p.os.open
            substituted = False

            def substitute(path, flags, *args, **kwargs):
                nonlocal substituted
                if str(path) == str(candidate) and not substituted:
                    candidate.rename(safe_copy)
                    candidate.symlink_to(safe_copy)
                    substituted = True
                return real_open(path, flags, *args, **kwargs)

            with patch.object(p.os, 'open', side_effect=substitute):
                with self.assertRaises(p.PreflightError):
                    p.read_env(candidate)
            self.assertTrue(substituted)

    def test_oversized_and_non_utf8_env_are_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d)/'env'
            path.write_bytes(b'A=' + b'x' * 65536)
            os.chmod(path, 0o600)
            with self.assertRaises(p.PreflightError):
                p.read_env(path)
            path.write_bytes(b'APP_KEY=\xff\xfe')
            with self.assertRaises(p.PreflightError):
                p.read_env(path)

    def test_mutated_compose_policy_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            stage=root/'infra/staging'
            stage.mkdir(parents=True)
            for name in ('compose.yaml','Caddyfile','Dockerfile'):
                (stage/name).write_text((ROOT/'infra/staging'/name).read_text())
            source=(stage/'compose.yaml').read_text()
            (stage/'compose.yaml').write_text(source.replace(p.POSTGRES_IMAGE, 'postgres:17-alpine'))
            with self.assertRaises(p.PreflightError):
                p.check_policy(root)
            (stage/'compose.yaml').write_text(source.replace("SESSION_ENCRYPT: 'true'", "SESSION_ENCRYPT: 'false'"))
            with self.assertRaises(p.PreflightError):
                p.check_policy(root)

if __name__ == '__main__':
    unittest.main()
