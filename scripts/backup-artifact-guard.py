#!/usr/bin/env python3
"""Synthetic dump integrity guard: not a signature, encryption or production backup."""
import argparse, gzip, hashlib, hmac, importlib.util, json, os, stat, sys, tarfile
from contextlib import contextmanager
from pathlib import Path

# A failed or interrupted backup manifest must not leave a visible PASS
# candidate. Load the same atomic publisher used by combined DR evidence,
# even when this script is imported directly by isolated audit tests.
_publisher_spec = importlib.util.spec_from_file_location(
    'agent3_backup_manifest_writer',
    Path(__file__).with_name('recovery_proof_writer.py'))
_publisher = importlib.util.module_from_spec(_publisher_spec)
_publisher_spec.loader.exec_module(_publisher)
publish = _publisher.publish

class ArchiveError(Exception):
    pass

def require(ok, reason):
    if not ok:
        raise ArchiveError(reason)

@contextmanager
def open_regular_archive(path):
    """Open a bounded regular archive without following a swapped symlink."""
    require(hasattr(os, 'O_NOFOLLOW') and hasattr(os, 'O_NONBLOCK'),
            'Secure nonblocking archive reads unavailable')
    # Opening a FIFO for reading blocks *before* fstat can reject its type.
    # Nonblocking open is ignored for regular files but prevents this DoS.
    flags = (os.O_RDONLY | getattr(os, 'O_CLOEXEC', 0) |
             os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        fd = os.open(path, flags)
    except OSError as error:
        raise ArchiveError(f'Archive cannot be opened safely: {error.strerror}') from None
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and 0 < info.st_size <= 8 * 1024**3,
                'Archive type or size invalid')
        yield stream

def fingerprint(path, magic):
    with open_regular_archive(path) as stream:
        info = os.fstat(stream.fileno())
        h = hashlib.sha256()
        require(stream.read(len(magic)) == magic, 'Archive magic mismatch')
        stream.seek(0)
        for block in iter(lambda: stream.read(1048576), b''):
            h.update(block)
    return {'sha256':h.hexdigest(),'bytes':info.st_size}

def safe_tar(path):
    count = 0
    regular_files = 0
    expanded = 0
    names = set()
    file_names = set()
    content_hash = hashlib.sha256()
    try:
        with open_regular_archive(path) as raw, tarfile.open(fileobj=raw, mode='r:gz') as archive:
            for member in archive:
                count += 1
                require(count <= 10000, 'Too many private archive members')
                parts = member.name.replace('\\', '/').split('/')
                require(member.name and not member.name.startswith(('/', '\\')) and
                        '..' not in parts and '.' not in parts and all(parts) and
                        '\\' not in member.name,
                        'Unsafe private archive member path')
                require(member.name not in names, 'Duplicate private archive member')
                require(member.isfile() or member.isdir(), 'Private links/devices denied')
                # Tar extraction must never have to replace a file with a
                # directory, or create children below an existing regular
                # file. This check works regardless of member ordering.
                ancestors = ('/'.join(parts[:n]) for n in range(1, len(parts)))
                require(not any(parent in file_names for parent in ancestors),
                        'Private member descends from a regular file')
                if member.isfile():
                    require(not any(existing.startswith(member.name + '/') for existing in names),
                            'Private file collides with an archive parent path')
                    file_names.add(member.name)
                names.add(member.name)
                require(member.mode & 0o7077 == 0,
                        'Private archive members must have owner-only permissions')
                require(member.size >= 0, 'Invalid private archive member size')
                expanded += member.size
                require(expanded <= 2 * 1024**3, 'Expanded private data exceeds limit')

                # Parsing headers alone does not verify compressed file contents.
                # Read every member fully before the recovery script can extract it.
                content_hash.update(member.name.encode('utf-8', errors='surrogateescape'))
                content_hash.update(b'\0')
                content_hash.update(str(member.size).encode('ascii'))
                content_hash.update(b'\0')
                if member.isfile():
                    regular_files += 1
                    reader = archive.extractfile(member)
                    require(reader is not None, 'Private archive member is unreadable')
                    read_bytes = 0
                    with reader:
                        for chunk in iter(lambda: reader.read(1024 * 1024), b''):
                            read_bytes += len(chunk)
                            require(read_bytes <= member.size, 'Archive member exceeds declared size')
                            content_hash.update(chunk)
                    require(read_bytes == member.size, 'Truncated private archive member')
    except (tarfile.TarError, OSError, EOFError, UnicodeError):
        raise ArchiveError('Invalid private gzip/tar archive') from None
    require(count > 0 and regular_files > 0,
            'Private archive must contain at least one regular file')
    # tarfile can stop at tar end markers before reading the gzip trailer.
    # Consume the entire stream and validate CRC32/ISIZE before restoration.
    decompressed = 0
    try:
        with open_regular_archive(path) as raw, gzip.GzipFile(fileobj=raw, mode='rb') as stream:
            for block in iter(lambda: stream.read(1024 * 1024), b''):
                decompressed += len(block)
                require(decompressed <= 2 * 1024**3 + 10000 * 512 + 1024 * 1024,
                        'Compressed tar expands beyond maximum wire size')
    except (OSError, EOFError):
        raise ArchiveError('Private gzip stream failed complete checksum verification') from None
    return {
        'gzip_trailer_verified': True,
        'entries': count,
        'regular_files': regular_files,
        'expanded_bytes': expanded,
        'expanded_sha256': content_hash.hexdigest(),
        'full_member_read_verified': True,
    }

def inspect(pg_dump, private_tar=None):
    result={
        'format':'agent3.synthetic.dump-validation.v1',
        'classification':'local integrity only; unsigned synthetic evidence',
        'database':fingerprint(pg_dump,b'PGDMP'),
        'production_ready':False,
    }
    if private_tar is not None:
        before = fingerprint(private_tar, b'\x1f\x8b')
        structure = safe_tar(private_tar)
        require(fingerprint(private_tar, b'\x1f\x8b') == before,
                'Private archive changed during validation')
        result['private'] = {**before, **structure}
    return result

def manifest_mac(record, key):
    canonical = json.dumps(record, sort_keys=True, separators=(',', ':')).encode('utf-8')
    return hmac.new(key, canonical, hashlib.sha256).hexdigest()

def signed_manifest(record, key):
    return {**record, 'authentication': 'run-local-hmac-sha256',
            'manifest_hmac_sha256': manifest_mac(record, key)}

def read_auth_key(path):
    with open_regular_archive(path) as stream:
        info = os.fstat(stream.fileno())
        require(info.st_size == 32 and info.st_mode & 0o077 == 0
                and info.st_uid == os.geteuid() and info.st_nlink == 1,
                'Run-local HMAC key must be an owner-held, unlinked 32-byte file')
        key = stream.read(33)
        require(len(key) == 32, 'Run-local HMAC key changed during reading')
    return key

def read_strict_json(payload):
    # Duplicate names, even inside a nested object, and NaN/Infinity are
    # ambiguous evidence across JSON implementations. Refuse them outright.
    def unique(pairs):
        data = {}
        for key, value in pairs:
            require(key not in data, 'Duplicate backup manifest JSON key')
            data[key] = value
        return data

    def nonfinite(value):
        raise ArchiveError('Non-standard manifest JSON numeric constant')

    return json.loads(payload.decode('utf-8'), object_pairs_hook=unique,
                      parse_constant=nonfinite)


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=('record','check'))
    parser.add_argument('--pg-dump',required=True,type=Path)
    parser.add_argument('--private-tar',type=Path)
    parser.add_argument('--manifest',required=True,type=Path)
    parser.add_argument('--auth-key-file',type=Path)
    args=parser.parse_args(argv)
    try:
        if args.action=='record':
            args.manifest.unlink(missing_ok=True)
            actual=inspect(args.pg_dump,args.private_tar)
            if args.auth_key_file is not None:
                actual = signed_manifest(actual, read_auth_key(args.auth_key_file))
            publish(args.manifest, actual)
        else:
            with open_regular_archive(args.manifest) as proof:
                info = os.fstat(proof.fileno())
                require(info.st_size <= 65536, 'Manifest invalid size/type')
                payload = proof.read(65537)
                require(len(payload) == info.st_size and len(payload) <= 65536,
                        'Manifest size changed during read')
                saved = read_strict_json(payload)
            require(type(saved) is dict, 'Backup manifest must be a JSON object')
            if args.auth_key_file is not None:
                key = read_auth_key(args.auth_key_file)
                require(saved.get('authentication') == 'run-local-hmac-sha256',
                        'Run-local HMAC authentication is missing')
                tag = saved.get('manifest_hmac_sha256')
                require(type(tag) is str and len(tag) == 64 and
                        all(ch in '0123456789abcdef' for ch in tag),
                        'Run-local HMAC tag is invalid')
                unsigned = {k: v for k, v in saved.items()
                            if k not in ('authentication', 'manifest_hmac_sha256')}
                require(hmac.compare_digest(tag, manifest_mac(unsigned, key)),
                        'Run-local HMAC manifest authentication failed')
                expected = signed_manifest(inspect(args.pg_dump,args.private_tar), key)
            else:
                require('authentication' not in saved and 'manifest_hmac_sha256' not in saved,
                        'Signed manifest requires run-local HMAC key')
                expected = inspect(args.pg_dump,args.private_tar)
            require(saved == expected, 'Backup bytes differ from integrity record')
    except (OSError, ValueError, ArchiveError) as exc:
        print(f'FAIL: {exc}',file=sys.stderr)
        return 1
    print('PASS: synthetic archive digest and optional run-local HMAC; no independent authenticity')
    return 0

if __name__=='__main__':
    sys.exit(main())
