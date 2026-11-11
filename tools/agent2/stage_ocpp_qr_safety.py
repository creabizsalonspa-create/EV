#!/usr/bin/env python3
"""Staging-only QR guard: a scanned station QR is NOT proof of charging.

Keeps station/location checks and paid+arrived ownership, prevents duplicate
source-bound QR sessions, and explicitly reports that physical start is pending.
"""
from pathlib import Path
import sys

def patch(root):
    root=root.resolve()
    if str(root)=='/home/hasio/ev-deployment' or str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refuse production source')
    file=root/'src/services/userService.js'
    if not file.is_file():
        raise RuntimeError('Expected customer service missing')
    data=file.read_text(encoding='utf8')
    a=data.find('export const ValidateQRService = async (user_id, body) => {')
    b=data.find('// ─── Screen 1: GET /ChargingStation/:bookingId',a+1)
    if a<0 or b<0 or b<=a:
        raise RuntimeError('Cannot locate QR validator')
    section=data[a:b]
    old='''    return {
      status: "Success",
      message: "QR code validated. Ready to start charging.",'''
    new='''    if (session.sourceSessionId) {
      return {
        status:"fail",
        message:"This booking already has a source-bound OCPP charging transaction."
      };
    }

    return {
      status: "Success",
      message: "Station QR verified. Charging has not started; wait for confirmed OCPP authorization.",'''
    if section.count(old)!=1:
        raise RuntimeError('QR result shape changed')
    section=section.replace(old,new)
    old_field='''        status:           session.status,
      },
    };'''
    new_field='''        status:           session.status,
        sourceVerified:false,
        meterVerified:false,
        canStartCharging:false,
        requiresOcppConfirmation:true,
      },
    };'''
    if section.count(old_field)!=1:
        raise RuntimeError('QR response fields changed')
    section=section.replace(old_field,new_field)
    file.write_text(data[:a]+section+data[b:],encoding='utf8')
    print('STAGED_STATION_QR_REQUIRES_OCPP_EVIDENCE; production unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:
        raise SystemExit('Usage: stage_ocpp_qr_safety.py STAGED_SOURCE')
    patch(Path(sys.argv[1]))
