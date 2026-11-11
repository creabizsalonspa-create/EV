import {normalizeResourceScope} from './ocppResourceScope.mjs';
const id=value=>typeof value==='string' && /^[A-Za-z0-9_-]{1,64}$/.test(value);
export function bookingSlotMinute(value){
 const m=typeof value==='string' && /^(0?[1-9]|1[0-2]):([0-5][0-9]) (AM|PM)$/.exec(value);
 return m?Number(m[1])%12*60+Number(m[2])+(m[3]==='PM'?720:0):null;
}
export function canonicalBookingDay(value){
 if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value))return false;
 const d=new Date(value+'T00:00:00Z');
 return Number.isFinite(d.valueOf())&&d.toISOString().slice(0,10)===value;
}
/** A scope pin is authorization metadata, never evidence of allocated capacity. */
export function inspectReservationWindow(row,chargers=[]){
 const issues=[],start=bookingSlotMinute(row?.slotStart),end=bookingSlotMinute(row?.slotEnd);
 if(!canonicalBookingDay(row?.date))issues.push('legacy_date_requires_resolution');
 if(start===null||end===null||end<=start)issues.push('invalid_or_overnight_slot');
 if(row?.time!==row?.slotStart)issues.push('display_time_differs_from_slot');
 const scoped=row?.ocppResourceScope!=null;
 const scope=scoped?normalizeResourceScope(row.ocppResourceScope):null;
 if(scoped&&!scope)issues.push('invalid_authorization_resource_scope');
 let capacityKey='*';
 if(row?.reservationCapacityVersion!=null){
   if(row.reservationCapacityVersion!==1)issues.push('unknown_capacity_contract');
   else{
     const chargerId=row.reservedOcppChargerId;
     const charger=chargers.find(c=>id(c.chargerId)&&c.chargerId===chargerId &&
       String(c.stationId)===String(row.stationId));
     if(!charger || !scope || charger.protocol!==scope.protocol)
       issues.push('capacity_mapping_unverified');
     else capacityKey=scope.protocol==='ocpp1.6'?chargerId+':charger':chargerId+':evse:'+scope.evseId;
   }
 }
 return {bookingId:String(row?._id??''),stationId:String(row?.stationId??''),
   date:canonicalBookingDay(row?.date)?row.date:null,startMinute:start,endMinute:end,
   capacityKey,capacityClass:capacityKey==='*'?'legacy_station_exclusive':'explicit_resource',
   authorizationPinned:row?.hasArrived===true || row?.isPaid===true || !!row?.ocppTokenHash || row?.ocppTokenIssuedAt!=null ||
     !!row?.ocppChargerId || row?.sessionLinked===true,
   issues,valid:issues.length===0};
}
export function reservationWindowsConflict(a,b){
 return a.valid&&b.valid&&a.stationId===b.stationId&&a.date===b.date &&
   a.startMinute<b.endMinute&&b.startMinute<a.endMinute &&
   (a.capacityKey==='*'||b.capacityKey==='*'||a.capacityKey===b.capacityKey);
}
/** Bounded read-only preflight. Missing coverage can never establish readiness. */
export function auditReservationCapacity(rows,chargers,{truncated=false,maxFindings=25}={}){
 if(!Array.isArray(rows)||!Array.isArray(chargers)||rows.length>1001 ||
     !Number.isInteger(maxFindings)||maxFindings<1||maxFindings>100)
   throw Error('Bounded reservation audit input required');
 const windows=rows.map(row=>inspectReservationWindow(row,chargers));
 const findings=[],collisions=[];
 let issueCount=0,collisionCount=0,comparisons=0,limited=false;
 for(const w of windows){
   issueCount+=w.issues.length;
   for(const issue of w.issues)if(findings.length<maxFindings)findings.push({bookingId:w.bookingId,issue});
 }
 const sorted=windows.filter(w=>w.valid).sort((a,b)=>a.date.localeCompare(b.date)||
   a.stationId.localeCompare(b.stationId)||a.startMinute-b.startMinute);
 for(let i=0;i<sorted.length&&!limited;i++)for(let j=i+1;j<sorted.length;j++){
   const a=sorted[i],b=sorted[j];
   if(a.date!==b.date||a.stationId!==b.stationId||b.startMinute>=a.endMinute)break;
   if(++comparisons>100000){limited=true;break;}
   if(reservationWindowsConflict(a,b)){
     collisionCount++;
     if(collisions.length<maxFindings)collisions.push({bookingIds:[a.bookingId,b.bookingId],
       issue:'overlapping_capacity_claim',capacityClass:a.capacityKey==='*'||b.capacityKey==='*'
         ?'legacy_station_exclusive':'same_charger_or_evse'});
   }
 }
 const complete=!truncated&&!limited;
 const protectedRecords=windows.filter(w=>w.authorizationPinned).length;
 return {readOnly:true,indexChangeAllowed:false,complete,scanned:rows.length,
   truncated:!!truncated,comparisonLimitReached:limited,issueCount,collisionCount,
   authorizationReviewCount:protectedRecords,
   preflightClear:complete&&issueCount===0&&collisionCount===0&&protectedRecords===0,
   legacyStationExclusiveCount:windows.filter(w=>w.capacityKey==='*').length,
   explicitResourceCount:windows.filter(w=>w.capacityKey!=='*').length,
   findings,collisions,findingsLimited:issueCount>findings.length||collisionCount>collisions.length,
   nextAction:'Resolve findings, preserve active authority, and agree the canonical capacity/migration contract. No indexes were changed.'};
}
