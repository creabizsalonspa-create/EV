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
            executable = root/'fake-tools'
            executable.mkdir()
            scratch_base = root/'scratch'
            scratch_base.mkdir()
            # Use real inert file/clock tools but NEVER contact a Docker daemon.
            for name in ('dirname', 'mkdir', 'rm', 'date', 'sha256sum'):
                target = shutil.which(name)
                self.assertIsNotNone(target)
                (executable/name).symlink_to(target)

            mktemp_path = shutil.which('mktemp')
            self.assertIsNotNone(mktemp_path)
            mktemp = executable/'mktemp'
            mktemp.write_text('#!/bin/sh\n'
                'exec "' + mktemp_path + '" -d "$TMP_TEST_SCRATCH/isolation.XXXXXXXX"\n')
            mktemp.chmod(0o700)

            python = executable/'python3'
            python.write_text('#!/bin/sh\n'
                              'printf "partial-sensitive-run-key" > "$2"\n'
                              'exit 74\n')
            python.chmod(0o700)

            docker = executable/'docker'
            docker.write_text('#!/bin/sh\n'
                'if [ "$1" = image ] && [ "$2" = inspect ]; then exit 0; fi\n'
                'printf "unexpected docker access\\n" >> "$DOCKER_ACTIONS"\n'
                'exit 99\n')
            docker.chmod(0o700)
            actions = root/'docker-actions'
            env = {**os.environ, 'PATH': str(executable),
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
