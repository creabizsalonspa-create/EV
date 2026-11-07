import test from 'node:test';
import assert from 'node:assert/strict';
import {auditReservationCapacity,inspectReservationWindow,reservationWindowsConflict,
 canonicalBookingDay} from '../reservationCapacityAudit.mjs';
import {stationCalendarIssue} from '../reservationCalendarPolicy.mjs';
const row=(overrides={})=>({_id:'booking-A',stationId:'station',date:'2026-11-08',
 time:'12:00 PM',slotStart:'12:00 PM',slotEnd:'01:00 PM',...overrides});
const charger={chargerId:'charger-A',stationId:'station',protocol:'ocpp2.0.1'};
const resource=(evseId,connectorId=1)=>row({reservationCapacityVersion:1,
 reservedOcppChargerId:'charger-A',ocppResourceScope:{protocol:'ocpp2.0.1',evseId,connectorId}});
test('calendar identity includes year and rejects impossible day normalization',()=>{
 assert.equal(canonicalBookingDay('2028-02-29'),true);
 for(const value of ['2026-02-29','2026-02-30','2026-13-01','08 Nov, Sun',{},'2026-1-01'])
   assert.equal(canonicalBookingDay(value),false);
});
test('different labels that overlap still consume legacy station capacity',()=>{
 const a=inspectReservationWindow(row(),[]);
 const b=inspectReservationWindow(row({_id:'booking-B',time:'12:30 PM',slotStart:'12:30 PM',slotEnd:'01:30 PM'}),[]);
 assert.equal(reservationWindowsConflict(a,b),true);
 assert.equal(auditReservationCapacity([row(),row({slotStart:'12:30 PM',time:'12:30 PM',slotEnd:'01:30 PM'})],[]).collisionCount,1);
 assert.equal(reservationWindowsConflict(a,inspectReservationWindow(row({slotStart:'01:00 PM',time:'01:00 PM',slotEnd:'02:00 PM'}),[])),false);
});
test('authorization scope alone never upgrades legacy scheduling capacity',()=>{
 const pinned=row({ocppResourceScope:{protocol:'ocpp2.0.1',connectorId:2,evseId:3}});
 assert.equal(inspectReservationWindow(pinned,[charger]).capacityClass,'legacy_station_exclusive');
});
test('explicit v1 resources preserve shared EVSE exclusivity and independent EVSEs',()=>{
 const a=inspectReservationWindow(resource(1,1),[charger]);
 assert.equal(reservationWindowsConflict(a,inspectReservationWindow(resource(1,2),[charger])),true);
 assert.equal(reservationWindowsConflict(a,inspectReservationWindow(resource(2,1),[charger])),false);
 assert.equal(reservationWindowsConflict(a,inspectReservationWindow(row(),[charger])),true);
});
test('unknown coverage, malformed calendar and live authority cannot clear preflight',()=>{
 for(const records of [[row({date:'08 Nov, Sun'})],[row({slotEnd:'11:00 AM'})],
   [row({ocppTokenHash:'secret-never-return'})],[row({sessionLinked:true})]]){
   const report=auditReservationCapacity(records,[]);
   assert.equal(report.preflightClear,false);
   assert.equal(report.indexChangeAllowed,false);assert.equal(report.readOnly,true);
   assert.equal(JSON.stringify(report).includes('secret-never-return'),false);
 }
 assert.equal(auditReservationCapacity([row()],[],{truncated:true}).preflightClear,false);
 assert.equal(auditReservationCapacity([row()],[],{truncated:true}).complete,false);
});
test('station slot definitions must be non-overlapping before exact-slot index can protect them',()=>{
 const body={date:'2026-11-08',slotStart:'12:00 PM',slotEnd:'01:00 PM'};
 const clean={slots:[{startTime:'12:00 PM',endTime:'01:00 PM'},
   {startTime:'01:00 PM',endTime:'02:00 PM'}]};
 assert.equal(stationCalendarIssue(clean,body),null);
 assert.match(stationCalendarIssue({slots:[...clean.slots,{startTime:'12:30 PM',endTime:'01:30 PM'}]},body),/overlapping/);
 assert.match(stationCalendarIssue(clean,{...body,date:'08 Nov, Sun'}),/YYYY-MM-DD/);
});
