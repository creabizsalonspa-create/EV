"""Actual libsodium backup stream tests, enabled after the pinned image build."""
import os
from pathlib import Path
import struct
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IMAGE = os.environ.get('EV_BACKUP_ENCRYPTION_IMAGE')


@unittest.skipUnless(IMAGE, 'Explicit pinned review image required for actual libsodium tests')
class BackupEncryptionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='ev-secretstream-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.write('key', os.urandom(32))
        self.write('plain', b'PGDMP\x00synthetic-private-backup' * 100000)

    def write(self, name, data):
        path = self.root / name
        path.write_bytes(data)
        path.chmod(0o600)
        return path

    def run_tool(self, action, source, output, key='key', expected=0):
        command = ['docker', 'run', '--rm', '--network', 'none', '--read-only',
                   '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                   '--user', f'{os.getuid()}:{os.getgid()}',
                   '--mount', f'type=bind,source={self.root},target=/backup',
                   '--mount', f'type=bind,source={ROOT / "scripts/backup-secretstream.php"},target=/tool.php,readonly',
                   '--entrypoint', 'php', IMAGE, '/tool.php', action,
                   '/backup/' + source, '/backup/' + output, '/backup/' + key]
        result = subprocess.run(command, capture_output=True, timeout=30)
        self.assertEqual(expected, result.returncode, result.stderr.decode())
        self.assertNotIn(b'synthetic-private-backup', result.stdout + result.stderr)
        self.assertFalse(list(self.root.glob('.backup-*.pending')))
        return result

    def test_multichunk_roundtrip_is_private_and_randomized(self):
        self.run_tool('encrypt', 'plain', 'encrypted')
        self.run_tool('encrypt', 'plain', 'other')
        self.assertNotEqual((self.root/'encrypted').read_bytes(), (self.root/'other').read_bytes())
        self.assertNotIn(b'synthetic-private-backup', (self.root/'encrypted').read_bytes())
        self.run_tool('decrypt', 'encrypted', 'restored')
        self.assertEqual((self.root/'plain').read_bytes(), (self.root/'restored').read_bytes())
        self.assertEqual(0o600, (self.root/'restored').stat().st_mode & 0o777)
        self.assertEqual(1, (self.root/'restored').stat().st_nlink)

    def test_empty_backup_roundtrip_requires_authenticated_final_record(self):
        self.write('empty', b'')
        self.run_tool('encrypt', 'empty', 'encrypted')
        self.run_tool('decrypt', 'encrypted', 'restored')
        self.assertEqual(b'', (self.root/'restored').read_bytes())

    def test_wrong_key_tampering_truncation_and_trailing_bytes_publish_no_plaintext(self):
        self.run_tool('encrypt', 'plain', 'encrypted')
        wire = (self.root/'encrypted').read_bytes()
        self.write('wrong-key', os.urandom(32))
        self.run_tool('decrypt', 'encrypted', 'wrong-output', 'wrong-key', expected=1)
        mutations = [wire[:-1], wire[:-21], wire + b'junk', b'wrong' + wire[5:],
                     wire[:100] + bytes([wire[100] ^ 1]) + wire[101:]]
        for number, data in enumerate(mutations):
            with self.subTest(number=number):
                self.write('tampered', data)
                self.run_tool('decrypt', 'tampered', f'bad-{number}', expected=1)
                self.assertFalse((self.root/f'bad-{number}').exists())
        self.assertFalse((self.root/'wrong-output').exists())

    def test_reordered_duplicate_and_oversized_frames_rejected(self):
        self.run_tool('encrypt', 'plain', 'encrypted')
        wire = (self.root/'encrypted').read_bytes()
        start = len(b'CREABIZ-BACKUP-1\n') + 24
        first_length = struct.unpack('!I', wire[start:start+4])[0]
        second = start + 4 + first_length
        second_length = struct.unpack('!I', wire[second:second+4])[0]
        third = second + 4 + second_length
        variants = [wire[:start]+wire[second:third]+wire[start:second]+wire[third:],
                    wire[:second]+wire[start:second]+wire[second:],
                    wire[:start]+struct.pack('!I', 0xffffffff)+wire[start+4:]]
        for number, data in enumerate(variants):
            self.write('tampered', data)
            self.run_tool('decrypt', 'tampered', f'bad-{number}', expected=1)
            self.assertFalse((self.root/f'bad-{number}').exists())

    def test_unsafe_keys_links_fifos_and_existing_output_fail_closed(self):
        self.write('short-key', b'x'*31)
        self.write('long-key', b'x'*33)
        self.write('public-key', b'x'*32).chmod(0o644)
        (self.root/'linked-key').symlink_to(self.root/'key')
        os.link(self.root/'key', self.root/'hard-key')
        os.mkfifo(self.root/'fifo', 0o600)
        for number, key in enumerate(['short-key','long-key','public-key','linked-key','hard-key','fifo']):
            self.run_tool('encrypt', 'plain', f'bad-{number}', key, expected=1)
        (self.root/'hard-key').unlink()
        self.write('existing', b'preserve')
        self.run_tool('encrypt', 'plain', 'existing', expected=1)
        self.assertEqual(b'preserve', (self.root/'existing').read_bytes())
        self.assertFalse(list(self.root.glob('bad-*')))
