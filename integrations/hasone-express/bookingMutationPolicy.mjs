import {canonicalBookingDay} from './reservationCapacityAudit.mjs';
import {stationCalendarIssue} from './reservationCalendarPolicy.mjs';
import {normalizeResourceScope} from './ocppResourceScope.mjs';
/** Existing Express booking compatibility; never reprices or refunds a booking. */
const empty=value=>value===null || value===undefined || value==='';
const text=value=>typeof value==='string' && value.trim()!=='' && value.length<=64;
export function bookingMutationFilter(booking,{hasSession=false}={}){
 if(!booking || !booking._id || !booking.userId || booking.status!=='Upcoming'
     || booking.hasArrived===true || !empty(booking.ocppTokenHash)
     || !empty(booking.ocppChargerId) || booking.ocppTokenIssuedAt!=null
     || hasSession || booking.isPaid!==false
     || (booking.ocppResourceScope!=null && !normalizeResourceScope(booking.ocppResourceScope)))return null;
 // Couple the mutation to the exact inspected slot and payment/arrival state.
 // Token issue uses the same booking document; neither operation can silently
 // overwrite the other's authoritative state after an asynchronous read.
 return {_id:booking._id,userId:booking.userId,status:'Upcoming',
   date:booking.date,time:booking.time,slotStart:booking.slotStart,slotEnd:booking.slotEnd,
   isPaid:booking.isPaid,hasArrived:{$ne:true},ocppResourceScope:booking.ocppResourceScope??null,
   ocppTokenHash:{$in:['',null]},ocppChargerId:{$in:['',null]},ocppTokenIssuedAt:null};
}
function minutes(value){
 if(typeof value!=='string')return null;
 const found=/^(0?[1-9]|1[0-2]):([0-5][0-9]) (AM|PM)$/.exec(value);
 if(!found)return null;
 return Number(found[1])%12*60+Number(found[2])+(found[3]==='PM'?720:0);
}
function duration(start,end){
 const a=minutes(start),b=minutes(end);
 return a===null || b===null || b<=a?null:b-a;
}
export function rescheduleSelection(booking,body,station){
 if(!body || typeof body!=='object' || Array.isArray(body) ||
    !canonicalBookingDay(body.date) || !text(body.time) || !station || station.status==='Unavailable')
   return null;
 const start=body.slotStart??booking.slotStart,end=body.slotEnd??booking.slotEnd;
 if(!text(start) || !text(end) || body.time!==start ||
    !Array.isArray(station.slots) ||
    !station.slots.some(slot=>slot.startTime===start && slot.endTime===end))return null;
 if(stationCalendarIssue(station,{date:body.date,slotStart:start,slotEnd:end}))return null;
 const previous=duration(booking.slotStart,booking.slotEnd),next=duration(start,end);
 // A different duration needs canonical re-quotation/payment recovery. Preserve
 // the recorded prepayment and quote rather than inventing new financial rules.
 if(previous===null || next===null || previous!==next)return null;
 return {date:body.date,time:start,slotStart:start,slotEnd:end};
}
