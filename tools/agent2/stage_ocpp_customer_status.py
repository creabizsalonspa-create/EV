#!/usr/bin/env python3
"""Stage read-only authenticated customer status from exact OCPP source evidence.

This intentionally does NOT enable remote start/stop, payment capture or
unauthorized source-to-booking binding. It rejects unbound source sessions.
"""
from pathlib import Path
import sys

def patch(root):
    root=root.resolve()
    if str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Production files cannot be patched')
    paths={
      'service':root/'src/services/userService.js',
      'station':root/'src/models/NearestStationModel.js',
      'booking':root/'src/models/BookingModel.js',
      'session':root/'src/models/ChargingSessionModel.js',
    }
    if not all(p.is_file() for p in paths.values()):
        raise RuntimeError('Complete staged Express source required')
    helper=Path(__file__).resolve().parents[2]/'integrations/hasone-express/ocppSourceEvidence.mjs'
    if not helper.is_file():
        raise RuntimeError('OCPP evidence guard is missing')
    data={key:p.read_text(encoding='utf8') for key,p in paths.items()}
    def once(text,old,new,name):
        if text.count(old)!=1:raise RuntimeError(name+' source mismatch')
        return text.replace(old,new)
    data['service']=once(data['service'],'import crypto from "crypto";',
      '''import crypto from "crypto";
import mongoose from "mongoose";
import { verifyOcppSource } from "../utils/ocppSourceEvidence.mjs";''',
      'customer service source imports')
    data['booking']=once(data['booking'],
      'const BookingModel = mongoose.model("Booking", BookingSchema);',
      '''// Token hash must be issued for this owner/booking by an authorized gateway workflow.
BookingSchema.add({ ocppTokenHash:{type:String,default:""} });

const BookingModel = mongoose.model("Booking", BookingSchema);''',
      'booking gateway token hash')
    data['session']=once(data['session'],
      'const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);',
      '''// These source locators are operator/verified-gateway written, never customer supplied.
ChargingSessionSchema.add({
  sourceSessionId: {type:String,default:""},
  sourceChargerId: {type:String,default:""},
  sourceTransactionId: {type:String,default:""},
});

const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);''',
      'session source locators')
    s=data['service']
    start='// ─── Screen 3: Read-only charging status (no synthetic metering)'
    end='// ─── Screen 4: Source-backed charger stop'
    pos=s.find(start)
    finish=s.find(end,pos+len(start))
    if pos<0 or finish<0 or s.find(start,pos+1)>=0:
        raise RuntimeError('Read-only charging service boundary changed')
    method='''// Fetch only a uniquely bound, source-confirmed OCPP record for the current customer.
const readCustomerOcppSource = async (user_id, session) => {
  if (!session || session.sourceType !== "ocpp" ||
      typeof session.sourceSessionId !== "string" ||
      !/^[a-f0-9]{24}$/i.test(session.sourceSessionId) ||
      !session.sourceChargerId || !session.sourceTransactionId) return null;
  const booking = await BookingModel.findOne({
    _id:session.bookingId, userId:user_id, isPaid:true, hasArrived:true,
  });
  if (!booking || !/^[a-f0-9]{64}$/i.test(booking.ocppTokenHash || "")) return null;
  // Existing OCPP provisioning is the authoritative station-to-charger mapping.
  const charger = await mongoose.connection.db.collection("ocpp_chargers").findOne({
    stationId:String(booking.stationId),
    chargerId:session.sourceChargerId,
    enabled:true,
  });
  if (!charger) return null;
  // Multi-charger stations require a pinned booking-to-charger assignment.
  // Old single-charger bookings retain a safe backward-compatible lookup.
  if (booking.ocppChargerId) {
    if (booking.ocppChargerId !== session.sourceChargerId) return null;
  } else {
    const count = await mongoose.connection.db.collection("ocpp_chargers")
      .countDocuments({stationId:String(booking.stationId),enabled:true});
    if (count !== 1) return null;
  }
  const source = await mongoose.connection.db.collection("ocpp_sessions").findOne({
    _id:new mongoose.Types.ObjectId(session.sourceSessionId),
    chargerId:session.sourceChargerId,
  });
  return verifyOcppSource(source,{
    sourceId:session.sourceSessionId,
    chargerId:session.sourceChargerId,
    transactionId:session.sourceTransactionId,
    tokenHash:booking.ocppTokenHash,
  });
};

// ─── Screen 3: GET source-backed status; no metering on GET ──────────────
export const GetChargingStatusService = async (user_id, sessionId) => {
  try {
    const session = await ChargingSessionModel.findOne({_id:sessionId,userId:user_id});
    if (!session) return {status:"fail",message:"Session not found."};
    const proof = await readCustomerOcppSource(user_id,session);
    if (!proof?.sourceVerified || !proof.meterVerified) {
      return {status:"Success",message:"No verified source metering for this booking.",
        data:{sessionId:session._id,bookingId:session.bookingId,
          chargingStatus:"Unverified",batteryPercent:null,timeRemaining:null,kwhUsed:null,
          sourceVerified:false,meterVerified:false,source:"unverified",
          canStop:false,canExtend:false}};
    }
    return {status:"Success",message:"OCPP source-verified charging status.",
      data:{sessionId:session._id,bookingId:session.bookingId,
        chargingStatus:proof.sessionEnded?"Completed":"Charging",
        batteryPercent:null,timeRemaining:null,kwhUsed:proof.energyKwh,
        energyWh:proof.energyWh,
        sourceVerified:true,meterVerified:true,source:"ocpp",protocol:proof.protocol,
        billingStatus:proof.billingStatus,paymentStatus:"not_collected",
        canStop:false,canExtend:false}};
  } catch {
    return {status:"fail",message:"Charging source evidence unavailable."};
  }
};

'''
    data['service']=s[:pos]+method+s[finish:]
    helper_dest=root/'src/utils/ocppSourceEvidence.mjs'
    if helper_dest.exists():
        raise RuntimeError('OCPP source guard already present in staged tree')
    for key,p in paths.items():
        p.write_text(data[key],encoding='utf8')
    helper_dest.write_text(helper.read_text(encoding='utf8'),encoding='utf8')
    print('STAGED_CUSTOMER_OCPP_STATUS_LINKED; physical charging and payments unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_ocpp_customer_status.py STAGED_SOURCE')
    patch(Path(sys.argv[1]))
