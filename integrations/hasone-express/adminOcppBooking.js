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
  },{$set:{ocppTokenHash:tokenHash,ocppTokenIssuedAt:issuedAt}},{returnDocument:'after'});
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


/**
 * GET /admin/ocpp/booking-sessions
 * Bounded, protected operational view; never calls an amount "collected"
 * without a separate authorized PSP reconciliation.
 */
export async function AdminListLinkedBookingOcppSessions(req,res){
 res.set('Cache-Control','no-store');
 try{
  const parsed=Number(req.query?.limit ?? 50);
  const limit=Number.isInteger(parsed) && parsed>0 ? Math.min(parsed,100) : 50;
  const links=await ChargingSessionModel.find({sourceType:'ocpp'})
    .sort({createdAt:-1}).limit(limit).lean();
  const entries=[];
  let confirmed=0,calculatedUnpaidFils=0;
  for(const item of links){
   if(!mongoose.isValidObjectId(item.sourceSessionId))continue;
   const booking=await BookingModel.findById(item.bookingId);
   if(!booking || !booking.isPaid || !booking.hasArrived ||
      !validHash(booking.ocppTokenHash))continue;
   const charger=await chargerFor(booking);
   if(!charger || charger.chargerId!==item.sourceChargerId)continue;
   const src=await mongoose.connection.db.collection('ocpp_sessions').findOne({
    _id:new mongoose.Types.ObjectId(item.sourceSessionId),
    chargerId:charger.chargerId,
   });
   const proof=verifyOcppSource(src,{
    sourceId:item.sourceSessionId,chargerId:charger.chargerId,
    transactionId:item.sourceTransactionId,tokenHash:booking.ocppTokenHash,
   });
   if(!proof?.sourceVerified)continue;
   if(proof.meterVerified)confirmed++;
   if(proof.totalFils!==null)calculatedUnpaidFils+=proof.totalFils;
   entries.push({
    bookingId:String(booking._id),
    sourceSessionId:item.sourceSessionId,
    chargerId:charger.chargerId,protocol:proof.protocol,
    meterVerified:proof.meterVerified,sessionEnded:proof.sessionEnded,
    energyKwh:proof.energyKwh,
    calculatedUnpaidAed:proof.totalFils===null?null:proof.totalFils/100,
    billingStatus:proof.billingStatus,paymentStatus:'not_collected',
   });
  }
  return res.status(200).json({status:'Success',data:{
    currency:'AED',billingMode:'calculation_only_no_collection',
    count:entries.length,meterVerifiedCount:confirmed,
    totalCalculatedUnpaidAed:calculatedUnpaidFils/100,
    totalCollectedAed:0,entries,
  }});
 }catch{
  return fail(res,503,'Linked OCPP reporting unavailable');
 }
}


/**
 * POST /admin/bookings/:id/ocpp/finalize-source
 * Syncs the booking's operational state after an authorized OCPP stop.
 * This is NOT a payment capture. Source invoices remain calculated/unpaid.
 */
export async function AdminFinalizeBookingOcppSource(req,res){
 res.set('Cache-Control','no-store');
 if(!mongoose.isValidObjectId(req.params.id))
  return fail(res,400,'Invalid booking identifier');
 try{
  const booking=await BookingModel.findById(req.params.id);
  if(!booking)return fail(res,404,'Booking not found');
  if(!booking.isPaid || !booking.hasArrived || !validHash(booking.ocppTokenHash)
      || !['Upcoming','Completed'].includes(booking.status))
   return fail(res,409,'Verified arrived booking authorization is required');
  const charger=await chargerFor(booking);
  if(!charger)return fail(res,409,'No approved charger mapped to booking station');
  const session=await ChargingSessionModel.findOne({
    bookingId:booking._id,userId:booking.userId,sourceType:'ocpp',
  });
  if(!session || !mongoose.isValidObjectId(session.sourceSessionId)
      || session.sourceChargerId!==charger.chargerId)
   return fail(res,409,'No uniquely verified OCPP transaction bound to this booking');
  const source=await mongoose.connection.db.collection('ocpp_sessions').findOne({
    _id:new mongoose.Types.ObjectId(session.sourceSessionId),chargerId:charger.chargerId
  });
  const proof=verifyOcppSource(source,{
    sourceId:session.sourceSessionId,chargerId:charger.chargerId,
    transactionId:session.sourceTransactionId,tokenHash:booking.ocppTokenHash,
  });
  if(!proof?.sourceVerified || !proof.meterVerified || !proof.sessionEnded ||
      proof.totalFils===null || proof.billingStatus!=='calculated_unpaid')
   return fail(res,409,'Source stop and reconciled metered invoice are required');
  const updated=booking.status==='Completed'?booking:await BookingModel.findOneAndUpdate({
    _id:booking._id,status:'Upcoming',isPaid:true,hasArrived:true,
  },{$set:{status:'Completed'}},{returnDocument:'after'});
  if(!updated)return fail(res,409,'Booking state changed; reload and retry');
  return res.status(200).json({status:'Success',data:{
    bookingId:String(booking._id),bookingStatus:'Completed',
    sourceSessionId:session.sourceSessionId,source:'ocpp',
    sourceEnded:true,meterVerified:true,energyKwh:proof.energyKwh,
    calculatedUnpaidAed:proof.totalFils/100,
    paymentStatus:'not_collected',reused:booking.status==='Completed',
  }});
 }catch{
  return fail(res,503,'Source-backed booking completion unavailable');
 }
}
