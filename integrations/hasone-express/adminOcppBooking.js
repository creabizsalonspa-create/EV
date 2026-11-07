/**
 * Staging integration for the existing AdminAuth-protected Express admin API.
 * One-time booking token -> charger-side OCPP authorization -> verified source
 * binding. Does not initiate remote charging, capture money or generate invoices.
 */
import crypto from 'node:crypto';
import mongoose from 'mongoose';
import BookingModel from '../models/BookingModel.js';
import ChargingSessionModel from '../models/ChargingSessionModel.js';
import {verifyOcppSource} from '../utils/ocppSourceEvidence.mjs';

const fail=(res,code,message)=>res.status(code).json({status:'fail',message});
const validHash=value=>typeof value==='string' && /^[a-f0-9]{64}$/i.test(value);
const chargerFor=async booking=>mongoose.connection.db.collection('ocpp_chargers').findOne({
 stationId:String(booking.stationId),enabled:true
});

/** POST /admin/bookings/:id/ocpp/issue-token */
export async function AdminIssueBookingOcppToken(req,res){
 res.set('Cache-Control','no-store');
 if(!mongoose.isValidObjectId(req.params.id))
  return fail(res,400,'Invalid booking identifier');
 try{
  const booking=await BookingModel.findById(req.params.id);
  if(!booking)return fail(res,404,'Booking not found');
  if(!booking.isPaid || !booking.hasArrived || booking.status!=='Upcoming')
   return fail(res,409,'A confirmed, paid and arrived booking is required');
  if(validHash(booking.ocppTokenHash))
   return fail(res,409,'A one-time authorization token was already issued');
  const charger=await chargerFor(booking);
  if(!charger || !/^[A-Za-z0-9_-]{1,64}$/.test(charger.chargerId))
   return fail(res,409,'Booking station has no approved active OCPP charger');
  const token=crypto.randomBytes(24).toString('hex');
  const tokenHash=crypto.createHash('sha256').update(token).digest('hex');
  const issuedAt=new Date();
  const expiresAt=new Date(issuedAt.getTime()+20*60*1000);
  const updated=await BookingModel.findOneAndUpdate({
    _id:booking._id,isPaid:true,hasArrived:true,status:'Upcoming',
    ocppTokenHash:{$in:['',null]},
  },{$set:{ocppTokenHash:tokenHash,ocppTokenIssuedAt:issuedAt}},{new:true});
  if(!updated)return fail(res,409,'Booking token already issued or booking status changed');
  try{
   await mongoose.connection.db.collection('ocpp_access_tokens').insertOne({
    chargerId:charger.chargerId,tokenHash,enabled:true,expiresAt,
    bookingId:String(booking._id),userId:String(booking.userId),createdAt:issuedAt,
   });
  }catch{
   await BookingModel.updateOne({_id:booking._id,ocppTokenHash:tokenHash},
    {$set:{ocppTokenHash:'',ocppTokenIssuedAt:null}});
   return fail(res,503,'Authorization token could not be registered');
  }
  return res.status(201).json({status:'Success',
    message:'One-time OCPP token issued. Deliver securely to the authorized charger.',
    data:{bookingId:String(booking._id),chargerId:charger.chargerId,
      oneTimeToken:token,expiresAt,chargingSessionConfirmed:false}});
 }catch{
  return fail(res,503,'Authorization provisioning temporarily unavailable');
 }
}

/** POST /admin/bookings/:id/ocpp/bind-source, body {sourceSessionId} */
export async function AdminBindBookingOcppSource(req,res){
 res.set('Cache-Control','no-store');
 if(!mongoose.isValidObjectId(req.params.id)
   || !mongoose.isValidObjectId(req.body?.sourceSessionId))
  return fail(res,400,'Valid booking and OCPP session identifiers are required');
 try{
  const booking=await BookingModel.findById(req.params.id);
  if(!booking)return fail(res,404,'Booking not found');
  if(!booking.isPaid || !booking.hasArrived || !validHash(booking.ocppTokenHash))
   return fail(res,409,'A paid, arrived and token-authorized booking is required');
  const charger=await chargerFor(booking);
  if(!charger)return fail(res,409,'No approved charger registered for this station');
  const sourceId=String(req.body.sourceSessionId);
  const source=await mongoose.connection.db.collection('ocpp_sessions').findOne({
    _id:new mongoose.Types.ObjectId(sourceId),chargerId:charger.chargerId
  });
  if(!source)return fail(res,404,'Source charging transaction not found for this charger');
  const startedAt=new Date(source.startedAt);
  if(!booking.ocppTokenIssuedAt ||
     !Number.isFinite(startedAt.valueOf()) ||
     startedAt.getTime()<new Date(booking.ocppTokenIssuedAt).getTime()-120000)
   return fail(res,409,'Source transaction predates this booking authorization');
  const proof=verifyOcppSource(source,{
    sourceId,chargerId:charger.chargerId,
    transactionId:String(source.transactionId),tokenHash:booking.ocppTokenHash
  });
  if(!proof?.sourceVerified || !proof.meterVerified)
   return fail(res,409,'Source transaction lacks authorized OCPP meter evidence');
  const query={bookingId:booking._id,userId:booking.userId};
  let session=await ChargingSessionModel.findOne(query);
  if(session && session.sourceSessionId && session.sourceSessionId!==sourceId)
   return fail(res,409,'Booking is already linked to another transaction');
  if(session){
   session.sourceType='ocpp';
   session.sourceSessionId=sourceId;
   session.sourceChargerId=charger.chargerId;
   session.sourceTransactionId=String(source.transactionId);
   await session.save();
  }else{
   session=await ChargingSessionModel.create({...query,stationId:booking.stationId,
     status:'NotStarted',sourceType:'ocpp',sourceSessionId:sourceId,
     sourceChargerId:charger.chargerId,sourceTransactionId:String(source.transactionId)});
  }
  return res.status(200).json({status:'Success',message:'Verified OCPP source linked to booking.',
    data:{bookingId:String(booking._id),sessionId:String(session._id),
      source:'ocpp',meterVerified:proof.meterVerified,
      energyKwh:proof.energyKwh,chargingSessionConfirmed:proof.sourceVerified,
      financialSettlement:'not_collected'}});
 }catch(err){
  if(err?.code===11000)return fail(res,409,'Source transaction already linked to another booking');
  return fail(res,503,'Source binding temporarily unavailable');
 }
}
