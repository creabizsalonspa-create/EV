import {canonicalBookingDay,bookingSlotMinute} from './reservationCapacityAudit.mjs';
export function stationCalendarIssue(station,body){
 if(!body||!canonicalBookingDay(body.date))return 'Use a complete calendar date in YYYY-MM-DD format.';
 const slots=station?.slots;
 if(!Array.isArray(slots)||slots.length===0||slots.length>512)
   return 'Station reservation calendar requires operator review.';
 const ranges=[];
 for(const slot of slots){
   const start=bookingSlotMinute(slot.startTime),end=bookingSlotMinute(slot.endTime);
   if(start===null||end===null||end<=start)
     return 'Station calendar has invalid or overnight slots. Operator review is required.';
   ranges.push({start,end});
 }
 ranges.sort((a,b)=>a.start-b.start);
 if(ranges.some((r,i)=>i>0&&r.start<ranges[i-1].end))
   return 'Station calendar has overlapping slots. Operator review is required.';
 if(!slots.some(s=>s.startTime===body.slotStart&&s.endTime===body.slotEnd))
   return 'Selected time slot is not in the station calendar.';
 return null;
}
