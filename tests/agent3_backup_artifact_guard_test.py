"""No-Docker backup integrity negative cases."""
import importlib.util, io, tarfile, tempfile, unittest
from unittest.mock import patch
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('guard',ROOT/'scripts/backup-artifact-guard.py')
guard=importlib.util.module_from_spec(spec);spec.loader.exec_module(guard)

def sample_db(path):
    path.write_bytes(b'PGDMP'+b'known-test-fixture')
def sample_tar(path,name='file.txt',link=False):
    with tarfile.open(path,'w:gz') as tar:
        item=tarfile.TarInfo(name)
        if link:
            item.type=tarfile.SYMTYPE
            item.linkname='../../secrets'
            tar.addfile(item)
        else:
            data=b'private synthetic content'
            item.size=len(data);item.mode=0o600
            tar.addfile(item,io.BytesIO(data))

class ArchiveTests(unittest.TestCase):
    def test_run_local_manifest_hmac_authenticates_proof_and_rejects_tampering(self):
        import json
        import os
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            dump, private, manifest = root/'db.dump', root/'private.tar.gz', root/'proof.json'
            keyfile, wrong = root/'local.key', root/'wrong.key'
            sample_db(dump)
            sample_tar(private)
            keyfile.write_bytes(os.urandom(32))
            wrong.write_bytes(os.urandom(32))
            os.chmod(keyfile, 0o600)
            os.chmod(wrong, 0o600)
            args = ['--pg-dump', str(dump), '--private-tar', str(private),
                    '--manifest', str(manifest)]
            signed = args + ['--auth-key-file', str(keyfile)]
            self.assertEqual(guard.main(['record'] + signed), 0)
            self.assertEqual(guard.main(['check'] + signed), 0)
            self.assertEqual(guard.main(['check'] + args), 1)
            self.assertEqual(guard.main(['check'] + args + ['--auth-key-file', str(wrong)]), 1)
            payload = json.loads(manifest.read_text())
            self.assertEqual(payload['authentication'], 'run-local-hmac-sha256')
            self.assertEqual(len(payload['manifest_hmac_sha256']), 64)
            payload['database']['sha256'] = 'a' * 64
            manifest.write_text(json.dumps(payload))
            self.assertEqual(guard.main(['check'] + signed), 1)
            self.assertEqual(guard.main(['record'] + signed), 0)
            private.write_bytes(private.read_bytes() + b'mutation')
            self.assertEqual(guard.main(['check'] + signed), 1)

    def test_run_local_key_permissions_and_symlink_swap_fail_closed(self):
        import os
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            dump, manifest, key = root/'db.dump', root/'proof.json', root/'run.key'
            sample_db(dump)
            key.write_bytes(os.urandom(32))
            os.chmod(key, 0o644)
            args = ['--pg-dump', str(dump), '--manifest', str(manifest),
                    '--auth-key-file', str(key)]
            self.assertEqual(guard.main(['record'] + args), 1)
            self.assertFalse(manifest.exists())
            os.chmod(key, 0o600)
            self.assertEqual(guard.main(['record'] + args), 0)
            link = root/'link.key'
            link.symlink_to(key)
            self.assertEqual(guard.main(['check'] + args[:-1] + [str(link)]), 1)
            key.write_bytes(b'short')
            self.assertEqual(guard.main(['check'] + args), 1)

    def test_manifest_denies_duplicate_nested_keys_and_nonfinite_json(self):
        import json
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            dump, manifest = root/'db.dump', root/'manifest.json'
            sample_db(dump)
            args = ['--pg-dump', str(dump), '--manifest', str(manifest)]
            self.assertEqual(guard.main(['record'] + args), 0)
            original = manifest.read_text()
            for hostile in (
                original.replace('"format":', '"format": "not-reviewed", "format":', 1),
                original.replace('"database": {',
                                 '"database": {"sha256": "incorrect",', 1),
                original.replace('"production_ready": false',
                                 '"production_ready": NaN', 1),
                original.replace('"production_ready": false',
                                 '"production_ready": Infinity', 1),
            ):
                with self.subTest(case=hostile[:70]):
                    manifest.write_text(hostile)
                    self.assertEqual(guard.main(['check'] + args), 1)
            manifest.write_text(original)
            self.assertEqual(guard.main(['check'] + args), 0)

    def test_content_change_detected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);db=root/'db.dump';private=root/'p.tar.gz';manifest=root/'m.json'
            sample_db(db);sample_tar(private)
            args=['--pg-dump',str(db),'--private-tar',str(private),'--manifest',str(manifest)]
            self.assertEqual(guard.main(['record']+args),0)
            self.assertEqual(guard.main(['check']+args),0)
            db.write_bytes(db.read_bytes()+b'changed')
            self.assertEqual(guard.main(['check']+args),1)

    def test_private_archive_replacement_during_validation_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            dump = root / 'db.dump'
            private = root / 'private.tar.gz'
            sample_db(dump)
            sample_tar(private)
            original_inspector = guard.safe_tar

            def mutate_after_read(path):
                result = original_inspector(path)
                with open(path, 'ab') as stream:
                    stream.write(b'changed-during-validation')
                return result

            with patch.object(guard, 'safe_tar', side_effect=mutate_after_read):
                with self.assertRaises(guard.ArchiveError):
                    guard.inspect(dump, private)

    def test_named_pipe_cannot_stall_archive_or_hmac_key_reader(self):
        import os
        if not hasattr(os, 'mkfifo'):
            self.skipTest('FIFO creation requires POSIX')
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            pipe = root/'unexpected.dump'
            os.mkfifo(pipe, 0o600)
            with self.assertRaises(guard.ArchiveError):
                guard.fingerprint(pipe, b'PGDMP')
            with self.assertRaises(guard.ArchiveError):
                guard.read_auth_key(pipe)
            db = root/'db.dump'
            sample_db(db)
            self.assertEqual(guard.main([
                'check', '--pg-dump', str(db),
                '--manifest', str(pipe),
            ]), 1)

    def test_regular_archives_are_read_using_no_follow_file_descriptors(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            dump = root / 'db.dump'
            sample_db(dump)
            self.assertEqual(len(guard.fingerprint(dump, b'PGDMP')['sha256']), 64)
            link = root / 'archive-link.dump'
            link.symlink_to(dump)
            with self.assertRaises(guard.ArchiveError):
                guard.fingerprint(link, b'PGDMP')

    def test_header_and_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);db=root/'db.dump';db.write_bytes(b'BAD')
            with self.assertRaises(guard.ArchiveError):guard.inspect(db)
            sample_db(db);link=root/'link.dump';link.symlink_to(db)
            with self.assertRaises(guard.ArchiveError):guard.inspect(link)

    def test_tar_path_traversal_or_link_rejected(self):
        for name,link in [('../secret',False),('/absolute',False),('file',True)]:
            with self.subTest(name=name),tempfile.TemporaryDirectory() as d:
                root=Path(d);db=root/'db.dump';t=root/'t.tar.gz'
                sample_db(db);sample_tar(t,name,link)
                with self.assertRaises(guard.ArchiveError):guard.inspect(db,t)

    def test_duplicate_member_names_are_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d); db = root / 'db.dump'; archive = root / 'duplicate.tar.gz'
            sample_db(db)
            with tarfile.open(archive, 'w:gz') as output:
                for value in (b'first', b'second'):
                    entry = tarfile.TarInfo('repeated.txt')
                    entry.mode = 0o600
                    entry.size = len(value)
                    output.addfile(entry, io.BytesIO(value))
            with self.assertRaises(guard.ArchiveError):
                guard.inspect(db, archive)

    def test_file_and_parent_path_collisions_rejected_regardless_of_tar_order(self):
        for members in (
            [('config', b'file'), ('config/token', b'child')],
            [('config/token', b'child'), ('config', b'file')],
        ):
            with self.subTest(members=[name for name, _ in members]), tempfile.TemporaryDirectory() as d:
                root = Path(d); dump = root/'db.dump'; private = root/'private.tar.gz'
                sample_db(dump)
                with tarfile.open(private, 'w:gz') as output:
                    for name, payload in members:
                        entry = tarfile.TarInfo(name)
                        entry.mode = 0o600
                        entry.size = len(payload)
                        output.addfile(entry, io.BytesIO(payload))
                with self.assertRaises(guard.ArchiveError):
                    guard.inspect(dump, private)

    def test_private_archive_symlink_is_rejected_before_tar_parsing(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            archive = root / 'private.tar.gz'
            sample_tar(archive)
            link = root / 'swapped.tar.gz'
            link.symlink_to(archive)
            with self.assertRaises(guard.ArchiveError):
                guard.safe_tar(link)

    def test_private_archive_symlink_swap_before_gzip_trailer_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            archive = root / 'private.tar.gz'
            replacement = root / 'original.tar.gz'
            sample_tar(archive)
            original_open = guard.open_regular_archive
            opens = 0

            def replace_before_second_open(path):
                nonlocal opens
                opens += 1
                if opens == 2:
                    archive.rename(replacement)
                    archive.symlink_to(replacement)
                return original_open(path)

            with patch.object(guard, 'open_regular_archive',
                              side_effect=replace_before_second_open):
                with self.assertRaises(guard.ArchiveError):
                    guard.safe_tar(archive)
            self.assertEqual(opens, 2)

    def test_regular_private_archive_remains_accepted(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d); dump = root/'db.dump'; private = root/'private.tar.gz'
            sample_db(dump)
            sample_tar(private, 'agent3-recovery-probe.bin')
            proof = guard.inspect(dump, private)
            self.assertEqual(proof['private']['regular_files'], 1)
            self.assertTrue(proof['private']['gzip_trailer_verified'])

    def test_dot_segments_and_privileged_modes_are_rejected(self):
        for name, mode in [
            ('nested/./secret.txt', 0o600),
            ('private.txt', 0o4755),
            ('private.txt', 0o666),
            ('private.txt', 0o644),
            ('private.txt', 0o640),
        ]:
            with self.subTest(name=name, mode=mode), tempfile.TemporaryDirectory() as d:
                root = Path(d); db = root / 'db.dump'; archive = root / 'test.tar.gz'
                sample_db(db)
                with tarfile.open(archive, 'w:gz') as output:
                    entry = tarfile.TarInfo(name)
                    entry.mode = mode
                    entry.size = 4
                    output.addfile(entry, io.BytesIO(b'test'))
                with self.assertRaises(guard.ArchiveError):
                    guard.inspect(db, archive)

    def test_world_readable_directory_is_denied_in_private_archive(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d); db = root / 'db.dump'; archive = root / 'private.tar.gz'
            sample_db(db)
            with tarfile.open(archive, 'w:gz') as output:
                directory = tarfile.TarInfo('folder')
                directory.type = tarfile.DIRTYPE
                directory.mode = 0o755
                output.addfile(directory)
                payload = b'synthetic-only'
                item = tarfile.TarInfo('folder/private.bin')
                item.size = len(payload)
                item.mode = 0o600
                output.addfile(item, io.BytesIO(payload))
            with self.assertRaises(guard.ArchiveError):
                guard.inspect(db, archive)

    def test_directory_only_archive_is_not_a_private_file_backup(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d); db = root / 'db.dump'; archive = root / 'directory.tar.gz'
            sample_db(db)
            with tarfile.open(archive, 'w:gz') as output:
                entry = tarfile.TarInfo('empty-directory')
                entry.mode = 0o700
                entry.type = tarfile.DIRTYPE
                output.addfile(entry)
            with self.assertRaises(guard.ArchiveError):
                guard.inspect(db, archive)

    def test_archive_members_are_fully_read_and_digest_is_stable(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            database = root / 'db.dump'
            archive = root / 'private.tar.gz'
            sample_db(database)
            sample_tar(archive)
            first = guard.inspect(database, archive)
            second = guard.inspect(database, archive)
            self.assertEqual(first, second)
            self.assertEqual(len(first['private']['expanded_sha256']), 64)
            self.assertTrue(first['private']['full_member_read_verified'])
            self.assertTrue(first['private']['gzip_trailer_verified'])
            self.assertGreater(first['private']['expanded_bytes'], 0)

    def test_truncated_compressed_archive_is_rejected_not_just_its_headers(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            database = root / 'db.dump'
            archive = root / 'private.tar.gz'
            sample_db(database)
            sample_tar(archive)
            original = archive.read_bytes()
            archive.write_bytes(original[:-8])
            with self.assertRaises(guard.ArchiveError):
                guard.inspect(database, archive)
            # A valid tar payload with a corrupted gzip checksum/size trailer
            # must also fail, even when every member was readable.
            broken = bytearray(original)
            broken[-1] ^= 0xFF
            archive.write_bytes(broken)
            with self.assertRaises(guard.ArchiveError):
                guard.inspect(database, archive)

    def test_manifest_symlink_swap_at_open_is_denied(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            dump, private = root/'db.dump', root/'private.tar.gz'
            manifest, original = root/'manifest.json', root/'original.json'
            sample_db(dump)
            sample_tar(private)
            args = ['--pg-dump', str(dump), '--private-tar', str(private),
                    '--manifest', str(manifest)]
            self.assertEqual(guard.main(['record'] + args), 0)
            real_open = guard.os.open
            swapped = False

            def replace_before_open(path, flags, *a, **kw):
                nonlocal swapped
                if str(path) == str(manifest) and not swapped:
                    manifest.rename(original)
                    manifest.symlink_to(original)
                    swapped = True
                return real_open(path, flags, *a, **kw)

            with patch.object(guard.os, 'open', side_effect=replace_before_open):
                self.assertEqual(guard.main(['check'] + args), 1)
            self.assertTrue(swapped)

    def test_oversized_manifest_does_not_get_parsed(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            dump, manifest = root/'db.dump', root/'manifest.json'
            sample_db(dump)
            manifest.write_bytes(b'{' + b' ' * 65536 + b'}')
            self.assertEqual(guard.main(['check', '--pg-dump', str(dump),
                                         '--manifest', str(manifest)]), 1)

    def test_stale_record_removed_on_failure(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);m=root/'manifest.json';m.write_text('{"status":"PASS"}')
            self.assertEqual(guard.main(['record','--pg-dump',str(root/'missing'), '--manifest',str(m)]),1)
            self.assertFalse(m.exists())
if __name__=='__main__':unittest.main()
