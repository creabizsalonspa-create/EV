import BookingModel from '../models/BookingModel.js';
import ChargingSessionModel from '../models/ChargingSessionModel.js';
import NearestStationModel from '../models/NearestStationModel.js';
import {bookingMutationFilter,rescheduleSelection} from '../utils/bookingMutationPolicy.mjs';

const fail=message=>({status:'fail',message});
async function mutableBooking(userId,bookingId){
 const booking=await BookingModel.findOne({_id:bookingId,userId});
 if(!booking)return {booking:null,filter:null};
 const hasSession=!!await ChargingSessionModel.exists({bookingId:booking._id});
 return {booking,filter:bookingMutationFilter(booking,{hasSession})};
}
export async function safeRescheduleBooking(userId,bookingId,body){
 try{
  const {booking,filter}=await mutableBooking(userId,bookingId);
  if(!filter)return fail('Only unused unpaid bookings can be rescheduled here. Paid, arrived or authorized bookings require operator review.');
  const station=await NearestStationModel.findById(booking.stationId);
  const selected=rescheduleSelection(booking,body,station);
  if(!selected)return fail('Choose an available station slot with the same duration and matching start time. A changed quote requires separate review.');
  const conflict=await BookingModel.exists({_id:{$ne:booking._id},stationId:booking.stationId,
    date:selected.date,slotStart:selected.slotStart,slotEnd:selected.slotEnd,
    status:{$ne:'Cancelled'}});
  if(conflict)return fail('Selected time slot is already reserved. Choose another.');
  const updated=await BookingModel.findOneAndUpdate(filter,{$set:selected},
    {returnDocument:'after',runValidators:true});
  if(!updated)return fail('Booking state changed during rescheduling. Reload before retrying.');
  return {status:'Success',message:'Booking rescheduled successfully.',data:updated};
 }catch(error){
  return fail(error?.code===11000?'Selected time slot was just reserved. Choose another.':
    'Rescheduling temporarily unavailable. Reload before retrying.');
 }
}
export async function safeCancelBooking(userId,bookingId){
 try{
  const {filter}=await mutableBooking(userId,bookingId);
  if(!filter)return fail('Only unused unpaid bookings can be cancelled here. Paid, arrived or authorized bookings require operator review.');
  const updated=await BookingModel.findOneAndUpdate(filter,{$set:{status:'Cancelled'}},
    {returnDocument:'after',runValidators:true});
  if(!updated)return fail('Booking state changed during cancellation. Reload before retrying.');
  return {status:'Success',message:'Booking cancelled successfully.',data:updated};
 }catch{
  return fail('Cancellation temporarily unavailable. Reload before retrying.');
 }
}
