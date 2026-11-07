#!/usr/bin/env python3
"""Synthetic dump integrity guard: not a signature, encryption or production backup."""
import argparse, gzip, hashlib, json, os, stat, sys, tarfile
from pathlib import Path

class ArchiveError(Exception):
    pass

def require(ok, reason):
    if not ok:
        raise ArchiveError(reason)

def fingerprint(path, magic):
    path=Path(path)
    require(not path.is_symlink(), 'Symlink archive denied')
    info=path.stat()
    require(stat.S_ISREG(info.st_mode) and 0 < info.st_size <= 8*1024**3,
            'Archive type or size invalid')
    h=hashlib.sha256()
    with path.open('rb') as stream:
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
    content_hash = hashlib.sha256()
    try:
        with tarfile.open(path, mode='r:gz') as archive:
            for member in archive:
                count += 1
                require(count <= 10000, 'Too many private archive members')
                parts = member.name.replace('\\', '/').split('/')
                require(member.name and not member.name.startswith(('/', '\\')) and
                        '..' not in parts and '.' not in parts and all(parts) and
                        '\\' not in member.name,
                        'Unsafe private archive member path')
                require(member.name not in names, 'Duplicate private archive member')
                names.add(member.name)
                require(member.isfile() or member.isdir(), 'Private links/devices denied')
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
        with gzip.open(path, 'rb') as stream:
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
        result['private']={**fingerprint(private_tar,b'\x1f\x8b'),**safe_tar(private_tar)}
    return result

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=('record','check'))
    parser.add_argument('--pg-dump',required=True,type=Path)
    parser.add_argument('--private-tar',type=Path)
    parser.add_argument('--manifest',required=True,type=Path)
    args=parser.parse_args(argv)
    try:
        if args.action=='record':
            args.manifest.unlink(missing_ok=True)
            actual=inspect(args.pg_dump,args.private_tar)
            args.manifest.parent.mkdir(parents=True,exist_ok=True)
            tmp=args.manifest.with_suffix('.pending-manifest')
            tmp.unlink(missing_ok=True)
            fd=os.open(tmp,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
            with os.fdopen(fd,'w') as f:
                json.dump(actual,f,sort_keys=True,indent=2)
                f.write('\n')
            os.replace(tmp,args.manifest)
        else:
            require(not args.manifest.is_symlink(), 'Manifest symlink denied')
            info=args.manifest.stat()
            require(stat.S_ISREG(info.st_mode) and 0 < info.st_size <= 65536,
                    'Manifest invalid size/type')
            saved=json.loads(args.manifest.read_text())
            require(saved == inspect(args.pg_dump,args.private_tar),
                    'Backup bytes differ from integrity record')
    except (OSError, ValueError, ArchiveError) as exc:
        print(f'FAIL: {exc}',file=sys.stderr)
        return 1
    print('PASS: synthetic archive format and digest; no independent authenticity')
    return 0

if __name__=='__main__':
    sys.exit(main())
