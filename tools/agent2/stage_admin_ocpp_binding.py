#!/usr/bin/env python3
"""Stage AdminAuth-protected OCPP booking token and source-session binding.

Fail closed; refuse production paths and already-applied staging patches.
Only operates on an isolated source copy. No charger commands, billing or
production customer mutations.
"""
from pathlib import Path
import sys

def once(text, before, after, label):
    if text.count(before)!=1:
        raise RuntimeError(label+': expected one source marker; got '+str(text.count(before)))
    return text.replace(before, after)

def patch(root):
    root=root.resolve()
    if str(root)=='/home/hasio/ev-deployment' or str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing to modify production directory')
    routes=root/'src/routes/adminRoutes.js'
    booking=root/'src/models/BookingModel.js'
    session=root/'src/models/ChargingSessionModel.js'
    controller=root/'src/controllers/adminOcppBooking.js'
    if not all(p.is_file() for p in (routes,booking,session)) or controller.exists():
        raise RuntimeError('Incomplete staged tree or controller already present')
    source=Path(__file__).resolve().parents[2]/'integrations/hasone-express/adminOcppBooking.js'
    if not source.is_file():raise RuntimeError('Missing vetted controller in GitHub worktree')
    r,b,s=(f.read_text(encoding='utf8') for f in (routes,booking,session))
    r=once(r,'import { AdminAuth } from "../middlewares/adminAuth.js";',
        '''import { AdminAuth } from "../middlewares/adminAuth.js";
import { AdminIssueBookingOcppToken, AdminBindBookingOcppSource, AdminListLinkedBookingOcppSessions } from "../controllers/adminOcppBooking.js";''',
        'admin import')
    r=once(r, '// Bookings & Reviews (read-only)',
        '''// Authorized source-bound OCPP customer booking lifecycle (no hardware commands).
router.post("/admin/bookings/:id/ocpp/issue-token", AdminAuth, AdminIssueBookingOcppToken);
router.post("/admin/bookings/:id/ocpp/bind-source", AdminAuth, AdminBindBookingOcppSource);
router.get("/admin/ocpp/booking-sessions", AdminAuth, AdminListLinkedBookingOcppSessions);

// Bookings & Reviews (read-only)''','Admin OCPP route placement')
    b=once(b,'const BookingModel = mongoose.model("Booking", BookingSchema);',
       '''BookingSchema.add({ocppTokenIssuedAt:{type:Date,default:null}});

const BookingModel = mongoose.model("Booking", BookingSchema);''',
       'booking issuance timestamp')
    s=once(s,'const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);',
       '''// Prevent one physical OCPP transaction being bound to multiple bookings.
ChargingSessionSchema.index({sourceSessionId:1},{
  unique:true,
  name:"uniq_linked_ocpp_source_session",
  partialFilterExpression:{sourceSessionId:{$gt:""}},
});

const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);''',
       'session binding uniqueness')
    # Everything was validated before any source file mutation.
    routes.write_text(r,encoding='utf8')
    booking.write_text(b,encoding='utf8')
    session.write_text(s,encoding='utf8')
    controller.write_text(source.read_text(encoding='utf8'),encoding='utf8')
    print('STAGED_ADMIN_OCPP_BINDING_PATCHED; production not modified')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_admin_ocpp_binding.py STAGED_SOURCE_COPY')
    patch(Path(sys.argv[1]))
