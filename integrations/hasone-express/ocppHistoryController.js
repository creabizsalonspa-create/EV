import mongoose from 'mongoose';
import BookingModel from '../models/BookingModel.js';
import ChargingSessionModel from '../models/ChargingSessionModel.js';
import {parseHistoryPage,presentOcppHistory} from '../utils/ocppHistory.mjs';
const fail=(res,code,message)=>res.status(code).json({status:'fail',message});
const validId=value=>typeof value==='string' && /^[a-f0-9]{24}$/i.test(value);
async function rowFor(link){
 const booking=await BookingModel.findById(link.bookingId).lean();
 if(!booking || String(booking.userId)!==String(link.userId))return null;
 const db=mongoose.connection.db;
 const charger=await db.collection('ocpp_chargers').findOne({
   stationId:String(booking.stationId),chargerId:booking.ocppChargerId
 },{projection:{stationId:1,chargerId:1,protocol:1,enabled:1}});
 const source=validId(link.sourceSessionId)?await db.collection('ocpp_sessions').findOne({
   _id:new mongoose.Types.ObjectId(link.sourceSessionId),
   chargerId:link.sourceChargerId
 }):null;
 return presentOcppHistory({link,booking,charger,source});
}
function scope(req,admin){
 if(admin)return {sourceType:'ocpp'};
 const id=req.headers.user_id;
 if(!validId(id))return null;
 return {sourceType:'ocpp',userId:id};
}
async function list(req,res,admin){
 res.set('Cache-Control','private, no-store');
 let page;
 try{page=parseHistoryPage(req.query||{});}catch{return fail(res,400,'Invalid history cursor or limit (1–100).');}
 const query=scope(req,admin);
 if(!query)return fail(res,401,'Authentication required');
 if(page.cursor)query._id={$lt:new mongoose.Types.ObjectId(page.cursor)};
 try{
   const found=await ChargingSessionModel.find(query).sort({_id:-1}).limit(page.limit+1).lean();
   const records=found.slice(0,page.limit),entries=[];
   for(const record of records){
     const row=await rowFor(record);
     if(row)entries.push(row);
   }
   const hasMore=found.length>page.limit;
   return res.json({status:'Success',data:{
     entries,scanned:records.length,unavailableCount:records.length-entries.length,
     hasMore,nextCursor:hasMore?String(records.at(-1)._id):null,
     currency:'AED',billingMode:'calculation_only_no_collection',readOnly:true
   }});
 }catch{return fail(res,503,'Source charging history temporarily unavailable');}
}
async function detail(req,res,admin){
 res.set('Cache-Control','private, no-store');
 if(!validId(req.params.id))return fail(res,400,'Invalid history identifier');
 const query=scope(req,admin);
 if(!query)return fail(res,401,'Authentication required');
 try{
   const link=await ChargingSessionModel.findOne({...query,_id:req.params.id}).lean();
   const row=link?await rowFor(link):null;
   if(!row)return fail(res,404,'Charging history not found');
   return res.json({status:'Success',data:row});
 }catch{return fail(res,503,'Source history inspection temporarily unavailable');}
}
export const CustomerOcppHistory=(req,res)=>list(req,res,false);
export const CustomerOcppHistoryDetail=(req,res)=>detail(req,res,false);
export const AdminOcppHistory=(req,res)=>list(req,res,true);
export const AdminOcppHistoryDetail=(req,res)=>detail(req,res,true);
