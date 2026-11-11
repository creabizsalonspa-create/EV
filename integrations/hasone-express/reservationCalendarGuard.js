import BookingModel from '../models/BookingModel.js';
import {canonicalBookingDay,bookingSlotMinute} from '../utils/reservationCapacityAudit.mjs';
import {stationCalendarIssue} from '../utils/reservationCalendarPolicy.mjs';
export async function guardReservationCalendar(station,body,{skipBookingId=null}={}){
 const issue=stationCalendarIssue(station,body);
 if(issue)return {ok:false,message:issue};
 // Inspect only this day plus ambiguous historical day labels. Valid records
 // from other days do not consume the scan budget. Never guess a missing year.
 const rows=await BookingModel.aggregate([
  {$match:{stationId:station._id,status:{$in:['Upcoming','Completed']},
    ...(skipBookingId?{_id:{$ne:skipBookingId}}:{})}},
  {$addFields:{__calendarDay:{$dateFromString:{
    dateString:{$cond:[{$eq:[{$type:'$date'},'string']},'$date',null]},
    format:'%Y-%m-%d',onError:null,onNull:null}}}},
  {$match:{$or:[{date:body.date},{$expr:{$ne:[
    {$dateToString:{date:'$__calendarDay',format:'%Y-%m-%d',onNull:''}},'$date']}}]}},
  {$limit:1001},{$project:{date:1,slotStart:1,slotEnd:1,time:1}}
 ]);
 if(rows.length>1000)return {ok:false,message:'Reservation calendar review limit reached. Contact the operator.'};
 const start=bookingSlotMinute(body.slotStart),end=bookingSlotMinute(body.slotEnd);
 for(const row of rows){
  if(!canonicalBookingDay(row.date))return {ok:false,message:'A legacy booking date needs operator resolution before this station can accept new reservations.'};
  const a=bookingSlotMinute(row.slotStart),b=bookingSlotMinute(row.slotEnd);
  if(a===null||b===null||b<=a)return {ok:false,message:'Existing reservation timing needs operator review.'};
  if(a<end&&b>start)return {ok:false,message:'Selected time overlaps an existing reservation. Choose another.'};
 }
 return {ok:true};
}
