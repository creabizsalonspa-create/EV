"""Key-generation failures must clean up the secret before any Docker start."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class EarlyRestoreSecretCleanupTests(unittest.TestCase):
    def test_partial_hmac_key_is_removed_even_when_generator_fails(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            scratch_base = root/'scratch'
            scratch_base.mkdir()
            actions = root/'docker-actions'
            # /tmp is noexec on the authorized test host. Bash *reads* this
            # file via BASH_ENV; it never executes a fake program from /tmp.
            # Functions replace Docker, mktemp and Python without invoking
            # any external Docker daemon or real secret generator.
            fake_shell = root/'isolated-qa-functions.sh'
            fake_shell.write_text('''
docker() {
  if [[ "$1" == image && "$2" == inspect ]]; then return 0; fi
  printf 'unexpected Docker action\\n' >> "$DOCKER_ACTIONS"
  return 99
}
mktemp() {
  /usr/bin/mktemp -d "$TMP_TEST_SCRATCH/isolation.XXXXXXXX"
}
python3() {
  printf 'partial-sensitive-run-key' > "$2"
  return 74
}
''')
            env = {**os.environ,
                   'BASH_ENV': str(fake_shell),
                   'TMP_TEST_SCRATCH': str(scratch_base),
                   'DOCKER_ACTIONS': str(actions)}
            for name, label, args in (
                ('staging-recovery-smoke', 'agent3-recovery',
                 ['creabiz-ev-business:review']),
                ('csms-recovery-smoke', 'agent3-csms-recovery', []),
            ):
                with self.subTest(script=name):
                    project = root/name
                    (project/'scripts').mkdir(parents=True)
                    script = project/'scripts'/name
                    shutil.copyfile(ROOT/'scripts'/name, script)
                    artifact = project/'artifacts'/label/'validation.json'
                    artifact.parent.mkdir(parents=True)
                    artifact.write_text('{"status":"PASS","stale":true}')
                    run = subprocess.run(
                        ['/bin/bash', str(script)] + args,
                        capture_output=True, text=True, timeout=10, env=env,
                    )
                    self.assertEqual(run.returncode, 74, run.stderr)
                    self.assertFalse(artifact.exists())
                    self.assertEqual(list(scratch_base.iterdir()), [])
                    self.assertFalse(actions.exists(), 'No Docker resources may be contacted')


if __name__ == '__main__':
    unittest.main()
