/**
 * Additional OCPP chargers at one station, independent of the legacy
 * single-charger settings route. AdminAuth wraps every route at registration.
 * No remote start/stop is enabled by registration.
 */
import crypto from 'node:crypto';
import mongoose from 'mongoose';
import Station from '../models/NearestStationModel.js';
import BookingModel from '../models/BookingModel.js';
import ChargingSessionModel from '../models/ChargingSessionModel.js';
import {auditReservationCapacity} from '../utils/reservationCapacityAudit.mjs';
import {auditConsumedStarts} from '../utils/ocppStartAudit.mjs';

const chargerTable=()=>mongoose.connection.db.collection('ocpp_chargers');
const validId=x=>typeof x==='string' && /^[A-Za-z0-9_-]{1,64}$/.test(x);
const fail=(res,code,message)=>res.status(code).json({status:'fail',message});
// No invented OCPP websocket address: only expose an explicitly configured
// approved WSS gateway base. This route never deploys a gateway listener.
const publicGatewayUrl=chargerId=>{
 const base=process.env.OCPP_PUBLIC_WS_BASE||'';
 if(!/^wss:\/\/[a-z0-9.-]+(?::[0-9]{2,5})?(?:\/[A-Za-z0-9/_-]*)?\/?$/i.test(base))
   return null;
 return base.replace(/\/$/,'')+'/'+chargerId;
};
async function stationFrom(req,res) {
  if(!mongoose.isValidObjectId(req.params.id)){
    fail(res,400,'Invalid station identifier');return null;
  }
  const station=await Station.findById(req.params.id).select('_id name').lean();
  if(!station){fail(res,404,'Station not found');return null;}
  return station;
}
function publicCharger(c){
  const online=!!c.online && !!c.lastSeenAt &&
    Date.now()-new Date(c.lastSeenAt).getTime()<120000;
  return {chargerId:c.chargerId,protocol:['ocpp1.6','ocpp2.0.1'].includes(c.protocol)?c.protocol:null,
    protocolReviewRequired:!['ocpp1.6','ocpp2.0.1'].includes(c.protocol),
    enabled:c.enabled===true,online,controlApproved:c.controlApproved===true,
    lastSeenAt:c.lastSeenAt??null,connectorStatuses:c.connectors??{},
    monitoringOnly:c.controlApproved!==true};
}
export async function AdminListStationOcppChargers(req,res){
  res.set('Cache-Control','no-store');
  try{
    const station=await stationFrom(req,res);if(!station)return;
    const chargers=await chargerTable().find({stationId:String(station._id)})
      .sort({chargerId:1}).limit(128).toArray();
    return res.status(200).json({status:'Success',data:{
      stationId:String(station._id),chargers:chargers.map(publicCharger),
      count:chargers.length,readOnly:true
    }});
  }catch{
    return fail(res,503,'Station OCPP charger listing unavailable');
  }
}
/** POST /admin/stations/:id/ocpp/chargers */
export async function AdminProvisionStationOcppCharger(req,res){
  res.set('Cache-Control','no-store');
  try{
    const station=await stationFrom(req,res);if(!station)return;
    const protocol=req.body?.protocol;
    const chargerId=req.body?.chargerId;
    if(!['ocpp1.6','ocpp2.0.1'].includes(protocol) || !validId(chargerId))
      return fail(res,400,'Specify a valid chargerId and OCPP protocol.');
    const collection=chargerTable();
    // Old production schema contains a unique stationId index. This must
    // be removed with an audited migration, NEVER silently during a request.
    const indexes=await collection.indexes();
    if(indexes.some(i=>i.unique===true && i.key?.stationId===1
      && Object.keys(i.key).length===1))
      return fail(res,409,'Multi-charger database migration is required before registration.');
    const count=await collection.countDocuments({stationId:String(station._id)});
    if(count>=128)return fail(res,409,'Station charger limit reached');
    await collection.createIndex({chargerId:1},{unique:true,name:'chargerId_1'});
    const password=crypto.randomBytes(20).toString('hex');
    const salt=crypto.randomBytes(16);
    await collection.insertOne({
      stationId:String(station._id),chargerId,protocol,enabled:true,
      online:false,controlApproved:false,secretSalt:salt.toString('hex'),
      secretHash:crypto.scryptSync(password,salt,32).toString('hex'),
      createdAt:new Date()
    });
    return res.status(201).json({status:'Success',message:'Charger added. Copy credentials once.',
      data:{...publicCharger({chargerId,protocol,enabled:true,online:false,controlApproved:false}),
        stationId:String(station._id),
        url:publicGatewayUrl(chargerId),
        gatewayUrlConfigured:!!publicGatewayUrl(chargerId),
        username:chargerId,password}});
  }catch(error){
    if(error?.code===11000)return fail(res,409,'Charger ID is already registered');
    return fail(res,503,'OCPP charger registration unavailable');
  }
}
/** GET /admin/stations/:id/ocpp/booking-sources */
export async function AdminListStationOcppBookingSources(req,res){
  res.set('Cache-Control','no-store');
  try{
    const station=await stationFrom(req,res);if(!station)return;
    const chargers=await chargerTable().find({stationId:String(station._id),enabled:true})
      .project({chargerId:1}).limit(128).toArray();
    const ids=[...new Set(chargers.map(c=>c.chargerId).filter(validId))];
    if(!ids.length)return res.status(200).json({status:'Success',data:{sessions:[],count:0}});
    const sessions=await mongoose.connection.db.collection('ocpp_sessions')
      .find({chargerId:{$in:ids}})
      .sort({startedAt:-1}).limit(100)
      .project({_id:1,chargerId:1,protocol:1,transactionId:1,status:1,energyKwh:1,
        startedAt:1,endedAt:1,paymentStatus:1}).toArray();
    return res.status(200).json({status:'Success',data:{
      count:sessions.length,
      sessions:sessions.map(s=>({
        id:String(s._id),chargerId:s.chargerId,protocol:s.protocol,
        transactionId:String(s.transactionId),status:s.status,
        energyKwh:Number.isFinite(s.energyKwh)?s.energyKwh:null,
        startedAt:s.startedAt??null,endedAt:s.endedAt??null,
        paymentStatus:s.paymentStatus||'not_collected'
      }))
    }});
  }catch{
    return fail(res,503,'OCPP station source listing unavailable');
  }
}

/**
 * GET /admin/stations/:id/ocpp/start-exceptions
 * Investigate consumed credentials with no corresponding charging source.
 * Does not expose authorization hashes, reset credentials, or start hardware.
 */
export async function AdminListStationOcppStartExceptions(req,res){
  res.set('Cache-Control','no-store');
  try{
    const station=await stationFrom(req,res);
    if(!station)return;
    const cursor=req.query?.cursor;
    const rawLimit=req.query?.limit;
    if(Object.keys(req.query||{}).some(key=>!['cursor','limit'].includes(key)) ||
       (cursor!==undefined && (typeof cursor!=='string' ||
         !/^[a-f0-9]{24}$/i.test(cursor))) ||
       (rawLimit!==undefined && (typeof rawLimit!=='string' ||
         !/^[1-9][0-9]{0,2}$/.test(rawLimit) || Number(rawLimit)>100)))
      return fail(res,400,'Specify a valid audit cursor and limit from 1 to 100.');
    // Disabled chargers can still have unresolved consumed credentials.
    // This read-only audit never restores charger or token enablement.
    const chargers=await chargerTable().find({
      stationId:String(station._id)
    }).project({chargerId:1}).limit(128).toArray();
    if(!chargers.length)return res.status(200).json({
      status:'Success',data:{scanned:0,matchedSourceCount:0,
        withinGracePeriodCount:0,orphanCount:0,exceptions:[],readOnly:true,
        hasMore:false,nextCursor:null}
    });
    const report=await auditConsumedStarts(mongoose.connection.db,{
      chargerIds:chargers.map(c=>c.chargerId),
      limit:rawLimit===undefined?50:Number(rawLimit),
      beforeId:cursor===undefined?null:new mongoose.Types.ObjectId(cursor)
    });
    return res.status(200).json({status:'Success',data:report});
  }catch{
    return fail(res,503,'Charger authorization exception report unavailable');
  }
}

/** Read-only reservation migration readiness; never creates/drops any index. */
export async function AdminStationReservationReadiness(req,res){
 res.set('Cache-Control','private, no-store');
 try{
  if(Object.keys(req.query||{}).length)return fail(res,400,'Reservation preflight accepts no query overrides');
  const station=await stationFrom(req,res);if(!station)return;
  const rows=await BookingModel.find({stationId:station._id,status:{$in:['Upcoming','Completed']}})
    .sort({_id:1}).limit(1001).select('_id stationId date time slotStart slotEnd hasArrived isPaid ocppTokenHash '+
      'ocppTokenIssuedAt ocppChargerId ocppResourceScope reservationCapacityVersion reservedOcppChargerId').lean();
  const chargers=await chargerTable().find({stationId:String(station._id)})
    .project({chargerId:1,stationId:1,protocol:1}).limit(128).toArray();
  const linked=await ChargingSessionModel.find({bookingId:{$in:rows.map(r=>r._id)}})
    .select('bookingId').limit(1001).lean();
  const linkedIds=new Set(linked.map(r=>String(r.bookingId)));
  const indexes=await BookingModel.collection.indexes();
  const report=auditReservationCapacity(rows.map(r=>({...r,sessionLinked:linkedIds.has(String(r._id))})),
    chargers,{truncated:rows.length>1000||linked.length>1000});
  report.legacyStationIndexPresent=indexes.some(i=>i.name==='uniq_active_station_date_slot'&&i.unique===true);
  return res.json({status:'Success',data:{stationId:String(station._id),...report}});
 }catch{return fail(res,503,'Reservation capacity preflight unavailable');}
}
