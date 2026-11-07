#!/usr/bin/env python3
"""Staging-only OCPP 2.0.1 source-session identity binding.

OCPP 1.6 session records already have idTagHash. On OCPP 2.0.1, add
idTokenHash after accepted authorization to allow verified booking/session
reconciliation without storing the raw authorization token.
"""
from pathlib import Path
import sys

def patch(path):
    path=path.resolve()
    if str(path).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Never modify the deployed OCPP gateway')
    if not path.is_file():
        raise RuntimeError('Staged ocpp-handlers.mjs not found')
    data=path.read_text(encoding='utf8')
    before='''        lastSeqNo:data.seqNo,tariff:await tariff(chargerId),status:"active",'''
    after='''        lastSeqNo:data.seqNo,tariff:await tariff(chargerId),status:"active",
        idTokenHash:(await import("node:crypto")).createHash("sha256")
          .update(data.idToken.idToken).digest("hex"),'''
    if data.count(before)!=1 or 'idTokenHash:' in data:
        raise RuntimeError('OCPP handler source changed or already patched')
    path.write_text(data.replace(before,after),encoding='utf8')
    print('OCPP201_SOURCE_IDENTITY_BOUND_IN_STAGING; live gateway unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_ocpp201_token_binding.py STAGED_HANDLERS_FILE')
    patch(Path(sys.argv[1]))
