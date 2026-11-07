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
    multi=root/'src/controllers/adminMultiCharger.js'
    legacy=root/'src/controllers/adminOcpp.js'
    multi_helper=root/'src/utils/ocppMultiCharger.mjs'
    migration_helper=root/'src/utils/migrateChargerStationIndex.mjs'
    reissue_helper=root/'src/utils/ocppReissuePolicy.mjs'
    binding_audit_helper=root/'src/utils/ocppSourceBindingIndexAudit.mjs'
    if not legacy.is_file() or multi.exists() or multi_helper.exists() or migration_helper.exists() or reissue_helper.exists() or binding_audit_helper.exists():
        raise RuntimeError('Multi-charger source missing or already patched')
    if not all(p.is_file() for p in (routes,booking,session)) or controller.exists():
        raise RuntimeError('Incomplete staged tree or controller already present')
    integration=Path(__file__).resolve().parents[2]/'integrations/hasone-express'
    source=integration/'adminOcppBooking.js'
    multi_source=integration/'adminMultiCharger.js'
    helper_source=integration/'ocppMultiCharger.mjs'
    migration_source=integration/'migrateChargerStationIndex.mjs'
    reissue_source=integration/'ocppReissuePolicy.mjs'
    binding_audit_source=integration/'ocppSourceBindingIndexAudit.mjs'
    if not all(p.is_file() for p in (source,multi_source,helper_source,migration_source,reissue_source,binding_audit_source)):
        raise RuntimeError('Missing vetted multi-charger integration source')
    r,b,s=(f.read_text(encoding='utf8') for f in (routes,booking,session))
    original=legacy.read_text(encoding='utf8')
    r=once(r,'import { AdminAuth } from "../middlewares/adminAuth.js";',
        '''import { AdminAuth } from "../middlewares/adminAuth.js";
import { AdminIssueBookingOcppToken, AdminBindBookingOcppSource, AdminListLinkedBookingOcppSessions, AdminFinalizeBookingOcppSource, AdminGetBookingOcppOperations, AdminReissueExpiredBookingOcppToken } from "../controllers/adminOcppBooking.js";''',
        'admin import')
    r=once(r,'import { AdminAuth } from "../middlewares/adminAuth.js";',
        '''import { AdminAuth } from "../middlewares/adminAuth.js";
import {
  AdminListStationOcppChargers, AdminProvisionStationOcppCharger,
  AdminListStationOcppBookingSources,
} from "../controllers/adminMultiCharger.js";''',
        'multi-charger imports')
    r=once(r, '// Bookings & Reviews (read-only)',
        '''// Authorized source-bound OCPP customer booking lifecycle (no hardware commands).
router.get("/admin/bookings/:id/ocpp/operations", AdminAuth, AdminGetBookingOcppOperations);
router.post("/admin/bookings/:id/ocpp/issue-token", AdminAuth, AdminIssueBookingOcppToken);
router.post("/admin/bookings/:id/ocpp/reissue-token", AdminAuth, AdminReissueExpiredBookingOcppToken);
router.post("/admin/bookings/:id/ocpp/bind-source", AdminAuth, AdminBindBookingOcppSource);
router.post("/admin/bookings/:id/ocpp/finalize-source", AdminAuth, AdminFinalizeBookingOcppSource);
router.get("/admin/ocpp/booking-sessions", AdminAuth, AdminListLinkedBookingOcppSessions);

// Multi-charger operations. All require existing AdminAuth.
router.get("/admin/stations/:id/ocpp/chargers", AdminAuth, AdminListStationOcppChargers);
router.post("/admin/stations/:id/ocpp/chargers", AdminAuth, AdminProvisionStationOcppCharger);
router.get("/admin/stations/:id/ocpp/booking-sources", AdminAuth, AdminListStationOcppBookingSources);

// Bookings & Reviews (read-only)''','Admin OCPP route placement')
    b=once(b,'const BookingModel = mongoose.model("Booking", BookingSchema);',
       '''BookingSchema.add({
  ocppTokenIssuedAt:{type:Date,default:null},
  ocppChargerId:{type:String,default:""},
});

const BookingModel = mongoose.model("Booking", BookingSchema);''',
       'booking issuance timestamp')
    s=once(s,'const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);',
       '''// Prevent one physical OCPP transaction being bound to multiple bookings.
ChargingSessionSchema.index({sourceSessionId:1},{
  unique:true,
  name:"uniq_linked_ocpp_source_session",
  partialFilterExpression:{sourceSessionId:{$gt:""}},
});
// A booking may have only one source-verified physical charging transaction.
ChargingSessionSchema.index({bookingId:1,userId:1},{
  unique:true,
  name:"uniq_source_bound_ocpp_booking",
  partialFilterExpression:{sourceType:"ocpp",sourceSessionId:{$gt:""}},
});

const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);''',
       'session binding uniqueness')
    original=once(original,
      '''await chargerTable().createIndex({stationId:1},{unique:true,partialFilterExpression:{stationId:{$type:"string"}}});''',
      '''// The audited multi-charger migration must remove any legacy unique
    // stationId index. Future registration guarantees unique charger IDs only.
    await chargerTable().createIndex({chargerId:1},{unique:true,name:"chargerId_1"});''',
      'remove legacy single-station index creation')
    # Everything was validated before any source file mutation.
    routes.write_text(r,encoding='utf8')
    legacy.write_text(original,encoding='utf8')
    booking.write_text(b,encoding='utf8')
    session.write_text(s,encoding='utf8')
    controller.write_text(source.read_text(encoding='utf8'),encoding='utf8')
    multi.write_text(multi_source.read_text(encoding='utf8'),encoding='utf8')
    multi_helper.write_text(helper_source.read_text(encoding='utf8'),encoding='utf8')
    migration_helper.write_text(migration_source.read_text(encoding='utf8'),encoding='utf8')
    reissue_helper.write_text(reissue_source.read_text(encoding='utf8'),encoding='utf8')
    binding_audit_helper.write_text(binding_audit_source.read_text(encoding='utf8'),encoding='utf8')
    print('STAGED_ADMIN_OCPP_BINDING_PATCHED; production not modified')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_admin_ocpp_binding.py STAGED_SOURCE_COPY')
    patch(Path(sys.argv[1]))
