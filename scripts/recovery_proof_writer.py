#!/usr/bin/env python3
"""Atomic, bounded, owner-private synthetic recovery evidence writer."""
import json
import os
from pathlib import Path
import tempfile


def publish(path, record):
    destination = Path(path)
    destination.parent.mkdir(parents=True, exist_ok=True)
    # A failed second run must not leave a prior successful proof visible.
    destination.unlink(missing_ok=True)
    payload = (json.dumps(record, indent=2, sort_keys=True, allow_nan=False) +
               '\n').encode('utf-8')
    if len(payload) > 65536:
        raise ValueError('Recovery proof exceeds size limit')
    fd, candidate = tempfile.mkstemp(
        dir=destination.parent, prefix='.' + destination.name + '.',
        suffix='.pending',
    )
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(candidate, destination)
    finally:
        try:
            os.unlink(candidate)
        except FileNotFoundError:
            pass
