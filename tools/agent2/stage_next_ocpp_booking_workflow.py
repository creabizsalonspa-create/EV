#!/usr/bin/env python3
"""Stage protected OCPP booking controls inside an isolated Next Admin copy.

No production frontend or backend files are written.
"""
from pathlib import Path
import sys

def once(src, old, new, label):
    n=src.count(old)
    if n!=1:raise RuntimeError(f'{label}: expected exactly one match, found {n}')
    return src.replace(old,new)

def patch(root):
    root=root.resolve()
    if str(root)=='/home/hasio/ev-deployment' or str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing live Admin directory')
    booking=root/'app/(admin)/bookings/page.tsx'
    component=root/'components/OcppBookingWorkflow.tsx'
    reference=Path(__file__).resolve().parents[2]/'integrations/hasone-next-admin/OcppBookingWorkflow.tsx'
    support={root/'components'/name:reference.parent/name for name in
      ['ocppWorkflowEvidence.mjs','ocppWorkflowEvidence.d.mts']}
    if not booking.is_file() or not reference.is_file() or component.exists() or any(
       target.exists() or not source.is_file() for target,source in support.items()):
        raise RuntimeError('Incomplete staged Next application or patch already applied')
    src=booking.read_text(encoding='utf8')
    src=once(src,
        'import { PageLoader } from "@/components/PageLoader";',
        'import { PageLoader } from "@/components/PageLoader";\nimport OcppBookingWorkflow from "@/components/OcppBookingWorkflow";',
        'workflow import')
    src=once(src,
        '  isPaid: boolean; status: string; createdAt: string;',
        '  isPaid: boolean; hasArrived?: boolean; stationId?: string;\n  status: string; createdAt: string;',
        'booking interface')
    src=once(src,
        '["Station","Vehicle","Date","Time","Amount","Paid","Status","Created"]',
        '["Station","Vehicle","Date","Time","Amount","Paid","Status","Created","OCPP"]',
        'OCPP heading')
    if src.count('colSpan={8}')!=3:
        raise RuntimeError('Booking table loading/error/empty structure changed')
    src=src.replace('colSpan={8}','colSpan={9}')
    mark='new Date(b.createdAt).toLocaleDateString()}</td>'
    replacement='''new Date(b.createdAt).toLocaleDateString()}</td>
                <td className="px-4 py-3">
                  <OcppBookingWorkflow booking={b} onUpdated={fetchBookings}/>
                </td>'''
    src=once(src,mark,replacement,'OCPP row action')
    # Original purchased UI renders the wrong USD symbol for an AED-only
    # deployment. Update the single booking amount cell, not global settings.
    usd='>'+chr(36)+'{(b.totalAmount || 0).toFixed(2)}</td>'
    src=once(src,usd,'>AED {(b.totalAmount || 0).toFixed(2)}</td>',
             'Admin booking AED label')
    for target,source in support.items():target.write_bytes(source.read_bytes())
    component.write_text(reference.read_text(encoding='utf8'),encoding='utf8')
    booking.write_text(src,encoding='utf8')
    print('STAGED_NEXT_OCPP_BOOKING_ACTIONS_READY; production unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:
        raise SystemExit('Usage: stage_next_ocpp_booking_workflow.py STAGED_NEXT_ADMIN')
    patch(Path(sys.argv[1]))
