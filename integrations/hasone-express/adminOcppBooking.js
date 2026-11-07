/**
 * Staging integration for the existing AdminAuth-protected Express admin API.
 * One-time booking token -> charger-side OCPP authorization -> verified source
 * binding. Does not initiate remote charging, capture money or generate invoices.
 */
import crypto from 'node:crypto';
import {createOcppBookingToken} from '../utils/ocppBookingToken.mjs';
import mongoose from 'mongoose';
import BookingModel from '../models/BookingModel.js';
import ChargingSessionModel from '../models/ChargingSessionModel.js';
import {verifyOcppSource} from '../utils/ocppSourceEvidence.mjs';
import {chooseBookingCharger} from '../utils/ocppMultiCharger.mjs';
import {canReissueOcppToken,OCPP_TOKEN_TTL_MS} from '../utils/ocppReissuePolicy.mjs';

const fail=(res,code,message)=>res.status(code).json({status:'fail',message});
const validHash=value=>typeof value==='string' && /^[a-f0-9]{64}$/i.test(value);
const chargersFor=async booking=>mongoose.connection.db.collection('ocpp_chargers')
  .find({stationId:String(booking.stationId),enabled:true})
  .sort({chargerId:1}).toArray();
const chargerSelection=async (booking, requestedId='')=>chooseBookingCharger({
  bookedChargerId:booking.ocppChargerId||'',requestChargerId:requestedId,
  chargers:await chargersFor(booking)
});
const chargerFor=async booking=>(await chargerSelection(booking)).charger;
const tokenReissueEvidence=async (booking,charger)=>{
  if(!charger || !validHash(booking.ocppTokenHash))
    return {sourceLinked:true,sourceObserved:true,tokenConsumed:true,tokenStillValid:true};
  const db=mongoose.connection.db;
  const [bound,source,existing]=await Promise.all([
    ChargingSessionModel.findOne({bookingId:booking._id,userId:booking.userId,
      sourceType:'ocpp',sourceSessionId:{$nin:['',null]}}).select('_id').lean(),
    db.collection('ocpp_sessions').findOne({chargerId:charger.chargerId,
      $or:[{idTagHash:booking.ocppTokenHash},
           {idTokenHash:booking.ocppTokenHash}]},{projection:{_id:1}}),
    db.collection('ocpp_access_tokens').findOne({
      chargerId:charger.chargerId,tokenHash:booking.ocppTokenHash
    },{projection:{consumedAt:1,expiresAt:1,enabled:1}})
  ]);
  return {sourceLinked:!!bound,sourceObserved:!!source,
    tokenConsumed:!!existing?.consumedAt,
    tokenStillValid:existing?.enabled===true &&
      Number.isFinite(new Date(existing.expiresAt).valueOf()) &&
      new Date(existing.expiresAt)>new Date()};
};

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
  const selected=await chargerSelection(booking,req.body?.chargerId||'');
  const charger=selected.charger;
  if(!charger)
   return fail(res,409,selected.reason==='explicit_selection_required'
     ? 'Select a specific approved charger for this multi-charger station.'
     : 'Requested charger is unavailable or does not match this booking.');
  const token=createOcppBookingToken();
  const tokenHash=crypto.createHash('sha256').update(token).digest('hex');
  const issuedAt=new Date();
  const expiresAt=new Date(issuedAt.getTime()+20*60*1000);
  const updated=await BookingModel.findOneAndUpdate({
    _id:booking._id,isPaid:true,hasArrived:true,status:'Upcoming',
    ocppTokenHash:{$in:['',null]},
    ocppChargerId:{$in:['',null,charger.chargerId]},
  },{$set:{ocppTokenHash:tokenHash,ocppTokenIssuedAt:issuedAt,
           ocppChargerId:charger.chargerId}},{returnDocument:'after'});
  if(!updated)return fail(res,409,'Booking token already issued or booking status changed');
  try{
   await mongoose.connection.db.collection('ocpp_access_tokens').insertOne({
    chargerId:charger.chargerId,tokenHash,enabled:true,expiresAt,
    bookingId:String(booking._id),userId:String(booking.userId),createdAt:issuedAt,
   });
  }catch{
   // An insert can commit even when its acknowledgement is lost. Retain
   // the booking pin and issuance time so a retry cannot create a second
   // credential. Best-effort revocation may itself have an uncertain result.
   await mongoose.connection.db.collection('ocpp_access_tokens').updateMany({
     chargerId:charger.chargerId,tokenHash
   },{$set:{enabled:false,revokedAt:new Date()}}).catch(()=>{});
   return fail(res,503,'Authorization outcome uncertain; reload booking before recovery');
  }
  return res.status(201).json({status:'Success',
    message:'One-time OCPP token issued. Deliver securely to the authorized charger.',
    data:{bookingId:String(booking._id),chargerId:charger.chargerId,
      oneTimeToken:token,expiresAt,chargingSessionConfirmed:false}});
 }catch{
  return fail(res,503,'Authorization provisioning temporarily unavailable');
 }
}

/**
 * POST /admin/bookings/:id/ocpp/reissue-token
 *
 * Safe recovery after the original 20-minute booking token expires.
 * A 2-minute settling grace plus source/token-consumption checks prevent
 * reissuing any credential that actually started physical charging.
 */
export async function AdminReissueExpiredBookingOcppToken(req,res){
 res.set('Cache-Control','no-store');
 if(!mongoose.isValidObjectId(req.params.id))
  return fail(res,400,'Invalid booking identifier');
 try{
  const booking=await BookingModel.findById(req.params.id);
  if(!booking)return fail(res,404,'Booking not found');
  const selected=await chargerSelection(booking,req.body?.chargerId||'');
  const charger=selected.charger;
  if(!charger)return fail(res,409,'Original approved booking charger is unavailable');
  const evidence=await tokenReissueEvidence(booking,charger);
  const now=new Date();
  if(!canReissueOcppToken({
    booking,approvedChargerId:charger.chargerId,...evidence,now
  }))return fail(res,409,'Token is active, already used or booking is not eligible for safe reissue');

  const oldHash=booking.ocppTokenHash,oldIssuedAt=booking.ocppTokenIssuedAt;
  const newToken=createOcppBookingToken();
  const newHash=crypto.createHash('sha256').update(newToken).digest('hex');
  const expiresAt=new Date(now.getTime()+OCPP_TOKEN_TTL_MS);
  const changed=await BookingModel.findOneAndUpdate({
    _id:booking._id,ocppTokenHash:oldHash,ocppTokenIssuedAt:oldIssuedAt,
    ocppChargerId:charger.chargerId,isPaid:true,hasArrived:true,status:'Upcoming',
  },{$set:{ocppTokenHash:newHash,ocppTokenIssuedAt:now}},{returnDocument:'after'});
  if(!changed)return fail(res,409,'Booking authorization was changed by another operator');
  const tokenTable=mongoose.connection.db.collection('ocpp_access_tokens');
  try{
    await tokenTable.updateMany({chargerId:charger.chargerId,tokenHash:oldHash},
      {$set:{enabled:false,revokedAt:now}});
    await tokenTable.insertOne({
      chargerId:charger.chargerId,tokenHash:newHash,enabled:true,expiresAt,
      bookingId:String(booking._id),userId:String(booking.userId),createdAt:now,
    });
  }catch{
    // Keep the new booking pin/time even if registration or revocation
    // acknowledgement is lost. Restoring the expired old pin would allow
    // another reissue while the new credential might still be enabled.
    await tokenTable.updateMany({chargerId:charger.chargerId,tokenHash:newHash},
      {$set:{enabled:false,revokedAt:new Date()}}).catch(()=>{});
    return fail(res,503,'Token reissue outcome uncertain; reload booking before recovery');
  }
  return res.status(201).json({status:'Success',
    message:'Expired unused authorization replaced. Copy the new token securely.',
    data:{bookingId:String(booking._id),chargerId:charger.chargerId,
      oneTimeToken:newToken,expiresAt,reissued:true,chargingSessionConfirmed:false}
  });
 }catch{
  return fail(res,503,'Token reissue verification unavailable');
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
  if(!booking.isPaid || !booking.hasArrived || !validHash(booking.ocppTokenHash)
      || !['Upcoming','Completed'].includes(booking.status))
   return fail(res,409,'A paid, arrived and token-authorized active booking is required');
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
  const sourceFields={
    sourceType:'ocpp',sourceSessionId:sourceId,
    sourceChargerId:charger.chargerId,
    sourceTransactionId:String(source.transactionId)
  };
  if(session){
   if(session.sourceSessionId && session.sourceSessionId!==sourceId)
     return fail(res,409,'Booking is already linked to another transaction');
   // Compare-and-swap: concurrent Admin requests must not overwrite each
   // other's source binding or attach a second charger to this booking.
   const changed=await ChargingSessionModel.findOneAndUpdate({
     _id:session._id,...query,
     $or:[{sourceSessionId:{$in:['',null]}},{sourceSessionId:sourceId}]
   },{$set:sourceFields},{returnDocument:'after'});
   if(!changed)return fail(res,409,'Booking source binding changed during confirmation');
   session=changed;
  }else{
   // A partial Mongo unique index on bookingId/userId for sourceType=ocpp
   // prevents two competing creates from linking distinct physical sessions.
   session=await ChargingSessionModel.create({...query,stationId:booking.stationId,
     status:'NotStarted',...sourceFields});
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


/**
 * GET /admin/bookings/:id/ocpp/operations
 * Read-only workflow state for the authenticated Admin frontend.
 * Never reveals the raw one-time OCPP authorization token or its hash.
 */
export async function AdminGetBookingOcppOperations(req,res){
 res.set('Cache-Control','no-store');
 if(!mongoose.isValidObjectId(req.params.id))
  return fail(res,400,'Invalid booking identifier');
 try{
  const booking=await BookingModel.findById(req.params.id);
  if(!booking)return fail(res,404,'Booking not found');
  const candidates=await chargersFor(booking);
  const selection=chooseBookingCharger({
    bookedChargerId:booking.ocppChargerId||'',chargers:candidates
  });
  const charger=selection.charger;
  const authorized=validHash(booking.ocppTokenHash);
  const eligible=booking.isPaid===true && booking.hasArrived===true;
  const renewalEvidence=authorized && charger
    ?await tokenReissueEvidence(booking,charger)
    :{sourceLinked:true,sourceObserved:true,tokenConsumed:true,tokenStillValid:true};
  const canReissue=canReissueOcppToken({
    booking,approvedChargerId:charger?.chargerId||'',...renewalEvidence,now:new Date()
  });
  const record=await ChargingSessionModel.findOne({
    bookingId:booking._id,userId:booking.userId,sourceType:'ocpp'
  });
  let proof=null;
  if(record && charger && mongoose.isValidObjectId(record.sourceSessionId)
      && record.sourceChargerId===charger.chargerId && authorized){
   const session=await mongoose.connection.db.collection('ocpp_sessions').findOne({
     _id:new mongoose.Types.ObjectId(record.sourceSessionId),
     chargerId:charger.chargerId
   });
   proof=verifyOcppSource(session,{
     sourceId:record.sourceSessionId,chargerId:charger.chargerId,
     transactionId:record.sourceTransactionId,tokenHash:booking.ocppTokenHash
   });
  }
  const ready=!!(proof?.sourceVerified && proof?.meterVerified
      && proof?.sessionEnded && proof.totalFils!==null
      && proof.billingStatus==='calculated_unpaid');
  const canIssue=eligible && booking.status==='Upcoming'
    && selection.choices.length>0 && !authorized;
  return res.status(200).json({status:'Success',data:{
    bookingId:String(booking._id),stationId:String(booking.stationId),
    bookingStatus:booking.status,bookingPrepaymentRecorded:!!booking.isPaid,
    arrivalRecorded:!!booking.hasArrived,approvedChargerId:charger?.chargerId??null,
    selectedChargerId:booking.ocppChargerId||null,
    requiresChargerSelection:!booking.ocppChargerId && selection.choices.length>1,
    approvedChargers:selection.choices.map(c=>({
      chargerId:c.chargerId,protocol:c.protocol||'ocpp1.6',
      online:!!c.online && !!c.lastSeenAt &&
        Date.now()-new Date(c.lastSeenAt).getTime()<120000
    })),
    authorizationIssued:authorized,
    tokenIssuedAt:booking.ocppTokenIssuedAt??null,
    tokenExpiresAt:booking.ocppTokenIssuedAt
      ? new Date(new Date(booking.ocppTokenIssuedAt).getTime()+20*60000):null,
    sourceSessionId:record?.sourceSessionId||null,
    sourceTransactionId:record?.sourceTransactionId||null,
    sourceVerified:proof?.sourceVerified===true,
    meterVerified:proof?.meterVerified===true,
    sourceEnded:proof?.sessionEnded===true,
    energyKwh:proof?.meterVerified?proof.energyKwh:null,
    calculatedUnpaidAed:proof?.totalFils===null||!proof?null:proof.totalFils/100,
    paymentStatus:proof?.sourceVerified?'not_collected':null,
    canIssueToken:canIssue,
    canReissueToken:canReissue,
    canBindSource:eligible && !!charger && authorized &&
       (booking.status==='Upcoming'||booking.status==='Completed') &&
       !record?.sourceSessionId,
    canFinalizeSource:ready && booking.status==='Upcoming',
    needsPaymentReconciliation:ready,
  }});
 }catch{
  return fail(res,503,'Booking OCPP operations unavailable');
 }
}
