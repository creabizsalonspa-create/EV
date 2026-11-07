"""No-Docker backup integrity negative cases."""
import importlib.util, io, tarfile, tempfile, unittest
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
    def test_content_change_detected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);db=root/'db.dump';private=root/'p.tar.gz';manifest=root/'m.json'
            sample_db(db);sample_tar(private)
            args=['--pg-dump',str(db),'--private-tar',str(private),'--manifest',str(manifest)]
            self.assertEqual(guard.main(['record']+args),0)
            self.assertEqual(guard.main(['check']+args),0)
            db.write_bytes(db.read_bytes()+b'changed')
            self.assertEqual(guard.main(['check']+args),1)

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

    def test_stale_record_removed_on_failure(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);m=root/'manifest.json';m.write_text('{"status":"PASS"}')
            self.assertEqual(guard.main(['record','--pg-dump',str(root/'missing'), '--manifest',str(m)]),1)
            self.assertFalse(m.exists())
if __name__=='__main__':unittest.main()
