#!/usr/bin/env python3
"""Derive 'In Charging Station' customer screen from the source OCPP session.

Only a staged source copy is modified. This read-only fix does not operate hardware.
"""
from pathlib import Path
import sys

def patch(root):
    root=root.resolve()
    if str(root)=='/home/hasio/ev-deployment' or str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing production source')
    file=root/'src/services/userService.js'
    if not file.is_file():raise RuntimeError('Staged customer service not found')
    source=file.read_text(encoding='utf8')
    a='export const GetChargingStationService = async (user_id, bookingId) => {'
    b='// ─── Screen 2: Source-backed charger start'
    i=source.find(a);j=source.find(b,i)
    if i<0 or j<i:raise RuntimeError('Charging station function not located')
    section=source[i:j]
    # A confirmed source transaction overrides the original planned slot time.
    # For unbound bookings, the original five-minute check stays in force.
    original='''    // ── 5-minute early check ──
    const earlyCheck = checkTooEarly(booking);
    if (earlyCheck) return earlyCheck;

    // Create session doc if not already created'''
    revised='''    // Create session doc if not already created'''
    if section.count(original)!=1:raise RuntimeError('Station early gate changed')
    section=section.replace(original,revised)
    before_source='''    // Never offer another scan when this booking is already bound to a'''
    gate='''    // Once an OCPP source exists, the actual transaction outranks the
    // booking's scheduled start time. Unbound bookings still obey the slot.
    if (!session.sourceSessionId) {
      const earlyCheck = checkTooEarly(booking);
      if (earlyCheck) return earlyCheck;
    }

    // Never offer another scan when this booking is already bound to a'''
    old='''    return {
      status: "Success",
      message: "Charging station info fetched.",
      data: {'''
    new='''    // Never offer another scan when this booking is already bound to a
    // gateway source transaction. Source metering is the only authority.
    const proof = session.sourceSessionId
      ? await readCustomerOcppSource(user_id,session) : null;
    const sourceBound = !!session.sourceSessionId;
    const chargingState = sourceBound
      ? (!proof?.sourceVerified ? "Unverified" :
         proof.sessionEnded ? "Completed" : "Charging")
      : session.status;
    const scanningAllowed = !sourceBound && booking.status === "Upcoming" &&
      session.status === "NotStarted";

    return {
      status: "Success",
      message: "Charging station info fetched.",
      data: {'''
    if section.count(old)!=1:raise RuntimeError('Station display shape changed')
    section=section.replace(old,new)
    if section.count(before_source)!=1:raise RuntimeError('Scanner source gate moved')
    section=section.replace(before_source,gate)
    old2='''        status:           session.status,            // "NotStarted"
        canScan:          session.status === "NotStarted",'''
    new2='''        status: chargingState,
        canScan: scanningAllowed,
        sourceVerified:proof?.sourceVerified === true,
        meterVerified:proof?.meterVerified === true,
        source:proof?.sourceVerified === true ? "ocpp" : "unverified",
        energyKwh:proof?.meterVerified === true ? proof.energyKwh : null,
        paymentStatus:proof?.sourceVerified === true ? "not_collected" : null,''';
    if section.count(old2)!=1:raise RuntimeError('Station scanner flag moved')
    section=section.replace(old2,new2)
    source=source[:i]+section+source[j:]
    file.write_text(source,encoding='utf8')
    print('STAGED_CHARGING_STATION_SCREEN_LINKED_TO_OCPP; production unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_ocpp_charging_station_screen.py STAGED_SOURCE')
    patch(Path(sys.argv[1]))
