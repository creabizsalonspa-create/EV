#!/usr/bin/env python3
"""Pinned mobile PDFium artifacts. No URL, hash or ABI bypass is exposed by CLI."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import ssl
import tarfile
import tempfile
import urllib.request
from urllib.parse import urlsplit


class HttpsReleaseRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        target=urlsplit(newurl)
        if target.scheme!='https' or target.hostname not in {'github.com','release-assets.githubusercontent.com','objects.githubusercontent.com'} or target.username or target.password:
            raise ValueError('Untrusted release redirect')
        return super().redirect_request(request,fp,code,message,headers,newurl)


def verify(path, expected_hash, expected_size):
    if path.stat().st_size != expected_size:
        raise ValueError('PDFium artifact size mismatch')
    digest = hashlib.sha256()
    with path.open('rb') as source:
        for chunk in iter(lambda: source.read(65536), b''):
            digest.update(chunk)
    if digest.hexdigest() != expected_hash:
        raise ValueError('PDFium artifact checksum mismatch')


def materialize(archive, output):
    """Extract only regular known files; no archive paths/links reach the host."""
    with tarfile.open(archive, 'r:gz') as source:
        member = source.getmember('lib/libpdfium.so')
        if not member.isfile() or member.size <= 0 or member.size > 64 * 1024 * 1024:
            raise ValueError('Invalid PDFium library member')
        output.mkdir(parents=True, exist_ok=True)
        fd, temporary = tempfile.mkstemp(prefix='.pdfium-', dir=output)
        try:
            with os.fdopen(fd, 'wb') as target, source.extractfile(member) as stream:
                shutil.copyfileobj(stream, target, length=65536)
            if Path(temporary).stat().st_size != member.size:
                raise ValueError('Truncated PDFium library')
            os.replace(temporary, output / 'libpdfium.so')
        finally:
            Path(temporary).unlink(missing_ok=True)


def fetch(abi, cache, output):
    manifest = json.loads(Path(__file__).with_name('artifacts.json').read_text())
    artifact = manifest['artifacts'].get(abi)
    if artifact is None:
        raise ValueError('Unsupported PDFium ABI')
    cache.mkdir(parents=True, exist_ok=True)
    archive = cache / artifact['name']
    if not archive.exists():
        # Fixed version/origin; Python's verified TLS context also follows the
        # public GitHub release redirect without any credential or cookie.
        url = 'https://github.com/bblanchon/pdfium-binaries/releases/download/chromium%2F7520/' + artifact['name']
        fd, temporary = tempfile.mkstemp(prefix='.pdfium-', dir=cache)
        try:
            request = urllib.request.Request(url, headers={'User-Agent': 'CreaBiz-EV-verified-build/1'})
            opener=urllib.request.build_opener(urllib.request.HTTPSHandler(context=ssl.create_default_context()),HttpsReleaseRedirect())
            with os.fdopen(fd, 'wb') as target, opener.open(request, timeout=60) as response:
                length = 0
                while chunk := response.read(65536):
                    length += len(chunk)
                    if length > artifact['size']:
                        raise ValueError('PDFium artifact exceeds pinned size')
                    target.write(chunk)
            verify(Path(temporary), artifact['sha256'], artifact['size'])
            os.replace(temporary, archive)
        finally:
            Path(temporary).unlink(missing_ok=True)
    # Cached archives are always checked; old output cannot bypass verification.
    verify(archive, artifact['sha256'], artifact['size'])
    materialize(archive, output)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--abi', required=True)
    parser.add_argument('--cache', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    try:
        fetch(args.abi, args.cache, args.output)
    except Exception:
        # Never echo temporary redirect URLs or environment state.
        parser.exit(1, 'Pinned PDFium verification/extraction failed. No unverified binary was accepted.\n')
