"""Disposable one-shot external watchdog policy tests; no Laravel/chargers or networks."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest

HERE = Path(__file__).resolve().parent
SUPERVISOR = HERE.parent / 'scripts' / 'agent3-watchdog-supervisor.py'
BASE = {
    'status': 'observed_normal', 'signals': [], 'read_only': True,
    'automatic_retry': False, 'external_alert_delivery': False,
    'all_workers_verified': False, 'charger_connectivity_verified': False,
    'production_readiness_verified': False,
}


class IndependentWatchdogTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.artisan = self.root / 'artisan'
        self.calls = self.root / 'invocations'

    def execute(self, payload=None, code=0, *, sleep=0, raw=None, private_stderr=''):
        source = textwrap.dedent('''\
            import json, pathlib, sys, time
            pathlib.Path(sys.argv[1]).write_text(
                pathlib.Path(sys.argv[1]).read_text() + 'x'
                if pathlib.Path(sys.argv[1]).exists() else 'x')
            assert sys.argv[2] == 'operations:watchdog-check'
            time.sleep(SLEEP)
            sys.stderr.write(STDERR)
            sys.stdout.write(OUTPUT)
            sys.exit(CODE)
        ''')
        output = json.dumps(payload if payload is not None else BASE) if raw is None else raw
        source = source.replace('SLEEP', repr(sleep)).replace('STDERR', repr(private_stderr))
        source = source.replace('OUTPUT', repr(output)).replace('CODE', str(code))
        self.artisan.write_text(source)
        # Python runtime invokes the fake artisan once. No mock provider is contacted.
        args = [sys.executable, str(SUPERVISOR), '--runtime', sys.executable,
                '--artisan', str(self.artisan), '--timeout', '1']
        # Fake script needs an invocation marker before the command literal.
        # The supervisor always supplies exactly one literal argument, so
        # substitute a safe wrapper that embeds the marker path instead.
        self.artisan.write_text(source.replace('sys.argv[1]', repr(str(self.calls)))
                                  .replace('sys.argv[2]', 'sys.argv[1]'))
        return subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              text=True, check=False, timeout=5)

    def test_normal_observation_succeeds_with_no_production_claim(self):
        result = self.execute()
        self.assertEqual(result.returncode, 0)
        self.assertEqual(json.loads(result.stdout), BASE)
        self.assertEqual(self.calls.read_text(), 'x')

    def test_real_operational_attention_remains_nonzero_and_sanitized(self):
        payload = {**BASE, 'status': 'needs_attention',
                   'signals': ['incident_scanner_stale', 'email_queue_overdue']}
        result = self.execute(payload, code=1)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), payload)
        self.assertEqual(self.calls.read_text(), 'x')

    def test_success_exit_with_attention_or_failure_exit_with_normal_is_never_green(self):
        for payload, code in [({**BASE, 'status': 'needs_attention', 'signals': ['queue_backlog']}, 0),
                              (BASE, 1)]:
            with self.subTest(code=code):
                result = self.execute(payload, code=code)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(json.loads(result.stdout)['signals'], ['watchdog_probe_unavailable'])

    def test_malformed_duplicate_unknown_and_private_stdout_never_leak(self):
        for raw in ['{"status":"observed_normal","status":"needs_attention"}',
                    'PRIVATE CUSTOMER EMAIL: someone@example.com',
                    json.dumps({**BASE, 'signals': ['SECRET-CHARGER-ID']}),
                    json.dumps({**BASE, 'production_readiness_verified': True}),
                    'x' * 5000]:
            with self.subTest(raw=raw[:30]):
                result = self.execute(raw=raw, private_stderr='PASSWORD-DO-NOT-PRINT')
                self.assertEqual(result.returncode, 1)
                self.assertNotIn('PASSWORD', result.stdout + result.stderr)
                self.assertNotIn('CUSTOMER', result.stdout + result.stderr)
                self.assertNotIn('SECRET-CHARGER', result.stdout + result.stderr)
                self.assertEqual(json.loads(result.stdout)['signals'], ['watchdog_probe_unavailable'])

    def test_timed_out_child_is_not_restarted_or_reported_healthy(self):
        result = self.execute(sleep=2)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout)['signals'], ['watchdog_probe_unavailable'])
        self.assertEqual(self.calls.read_text(), 'x')

    def test_missing_runtime_and_invalid_timeout_fail_closed(self):
        for runtime, timeout in [('/missing-runtime', '1'), (sys.executable, '0'),
                                 (sys.executable, '31')]:
            args = [sys.executable, str(SUPERVISOR), '--runtime', runtime,
                    '--artisan', str(self.artisan), '--timeout', timeout]
            r = subprocess.run(args, capture_output=True, text=True, check=False)
            self.assertEqual(r.returncode, 1)
            self.assertEqual(json.loads(r.stdout)['signals'], ['watchdog_probe_unavailable'])


if __name__ == '__main__':
    unittest.main()
