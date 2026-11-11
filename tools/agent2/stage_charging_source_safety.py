#!/usr/bin/env python3
"""Stage a strict OCPP-source gate on the separately deployed Express app.

Source in /home/hasio/ev-deployment is NEVER edited by this script.
No user-initiated charging command may mark a charger as physically started
until a real OCPP authorization/transaction adapter exists.
"""
from pathlib import Path
import sys

def patch(root):
    root=root.resolve()
    if str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing production source')
    service=root/'src/services/userService.js'
    model=root/'src/models/ChargingSessionModel.js'
    if not service.is_file() or not model.is_file():
        raise RuntimeError('Complete staged source required')
    source=service.read_text(encoding='utf8')
    schema=model.read_text(encoding='utf8')
    if 'sourceType:' in schema:
        raise RuntimeError('Source verification model fields already present')
    def replace_section(s,start,end,new,label):
        begin=s.find(start)
        finish=s.find(end,begin+len(start))
        if begin<0 or finish<0 or s.find(start,begin+1)>=0 or begin>=finish:
            raise RuntimeError(label+' source shape mismatch')
        return s[:begin]+new+'\n\n'+s[finish:]
    source=replace_section(source,
        '// ─── Screen 2: POST /ChargingSession/:id/start',
        '// ─── Screen 3: GET /ChargingSession/:id/status',
        '''// ─── Screen 2: Source-backed charger start ─────────────────────────────
export const StartChargingService = async () => ({
  status: "fail",
  message: "A verified OCPP charger authorization and source-confirmed start are required. This mobile API cannot start a physical charger until the canonical gateway adapter is connected.",
});''','unsafe simulated start')
    source=replace_section(source,
        '// ─── Screen 3: GET /ChargingSession/:id/status',
        '// ─── Screen 4: POST /ChargingSession/:id/stop',
        '''// ─── Screen 3: Read-only charging status (no synthetic metering) ────────
export const GetChargingStatusService = async (user_id, sessionId) => {
  try {
    const session = await ChargingSessionModel.findOne({ _id: sessionId, userId: user_id });
    if (!session) return { status: "fail", message: "Session not found." };
    const verified = session.sourceType === "ocpp" && session.meterVerified === true;
    return {
      status: "Success",
      message: verified ? "Verified OCPP session status." : "No verified OCPP meter or transaction evidence.",
      data: {
        sessionId: session._id,
        bookingId: session.bookingId,
        chargingStatus: verified ? session.status : "Unverified",
        batteryPercent: verified ? session.batteryPercent : null,
        timeRemaining: null,
        kwhUsed: verified ? session.kwhUsed : null,
        canStop: false,
        canExtend: false,
        meterVerified: verified,
        source: verified ? "ocpp" : "unverified",
      },
    };
  } catch {
    return { status: "fail", message: "Charging status unavailable." };
  }
};''','synthetic meter polling')
    source=replace_section(source,
        '// ─── Screen 4: POST /ChargingSession/:id/stop',
        '// ─── Screen 5: POST /ChargingSession/:id/extend',
        '''// ─── Screen 4: Source-backed charger stop ──────────────────────────────
export const StopChargingService = async () => ({
  status: "fail",
  message: "A verified OCPP charger command and stop transaction are required. No simulated physical stop or metered invoice may be created.",
});''','unverified physical stop and invoice')
    source=replace_section(source,
        '// ─── Screen 5: POST /ChargingSession/:id/extend',
        '// ─── Screen 6: GET /ChargingSession/:id/summary',
        '''// ─── Screen 5: Charging extension cannot create unverified charges ──────
export const ExtendSessionService = async () => ({
  status: "fail",
  message: "Source-verified charger extension is not enabled in this purchased API. No estimated extension charges have been created.",
});''','unverified extension charge')
    marker='    if (session.status === "NotStarted" || session.status === "Charging")'
    insert='''    if (session.sourceType !== "ocpp" || session.meterVerified !== true) {
      return { status:"fail", message:"No verified OCPP charging summary is available." };
    }
'''
    begin=source.find('export const GetChargingSummaryService = async (user_id, sessionId) => {')
    finish=source.find('// ─── POST /ChargingSession/:id/pay',begin)
    if begin<0 or finish<0:raise RuntimeError('Summary function boundary not found')
    part=source[begin:finish]
    if part.count(marker)!=1:raise RuntimeError('Summary status check changed')
    source=source[:begin]+part.replace(marker,insert+marker)+source[finish:]
    # Block extension settlement if no source-bound metering, including direct requests.
    start=source.find('export const PayChargingSessionService = async (user_id, sessionId, body = {}) => {')
    end=source.find('export const ',start+10)
    if start<0:raise RuntimeError('Extension payment service not found')
    sub=source[start:end if end>start else len(source)]
    old='    if (!session) return { status: "fail", message: "Session not found." };'
    if sub.count(old)!=1:raise RuntimeError('Extension payment lookup changed')
    sub=sub.replace(old,old+'''
    if (session.sourceType !== "ocpp" || session.meterVerified !== true) {
      return { status:"fail", message:"Verified source metering is required for any extension payment." };
    }''')
    source=source[:start]+sub+source[end if end>start else len(source):]
    schema_marker='const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);'
    if schema.count(schema_marker)!=1:raise RuntimeError('Charging model source changed')
    schema=schema.replace(schema_marker,'''// Provenance must be set only from approved source gateway transaction evidence.
ChargingSessionSchema.add({
  sourceType: {type:String,enum:["unverified","ocpp"],default:"unverified"},
  meterVerified: {type:Boolean,default:false},
});

'''+schema_marker)
    service.write_text(source,encoding='utf8')
    model.write_text(schema,encoding='utf8')
    print('STAGED_CHARGING_SOURCE_GATE_PATCHED; production unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_charging_source_safety.py STAGED_SOURCE_COPY')
    patch(Path(sys.argv[1]))
