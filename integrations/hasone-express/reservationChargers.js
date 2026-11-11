import mongoose from 'mongoose';
import NearestStationModel from '../models/NearestStationModel.js';
import {reservationChargerChoices,selectReservationCharger} from '../utils/reservationChargerChoice.mjs';
const rowsFor=stationId=>mongoose.connection.db.collection('ocpp_chargers')
 .find({stationId:String(stationId),enabled:true},{projection:{chargerId:1,protocol:1,enabled:1}})
 .sort({chargerId:1}).limit(65).toArray();
export async function resolveReservationCharger(stationId,requested){
 return selectReservationCharger(await rowsFor(stationId),requested);
}
export async function CustomerReservationChargers(req,res){
 res.set('Cache-Control','no-store');
 if(!mongoose.isValidObjectId(req.params.id)||Object.keys(req.query??{}).length)
  return res.status(400).json({status:'fail',message:'Invalid station charger request.'});
 try{
  const station=await NearestStationModel.findById(req.params.id).select('_id status').lean();
  if(!station)return res.status(404).json({status:'fail',message:'Station not found.'});
  if(station.status==='Unavailable')return res.status(409).json({status:'fail',message:'Station is unavailable.'});
  const choices=reservationChargerChoices(await rowsFor(station._id));
  if(!choices)return res.status(409).json({status:'fail',message:'Approved charger records need operator review.'});
  return res.json({status:'Success',data:{stationId:String(station._id),choices,
    readOnly:true,reservationScope:'station',independentCapacity:false,hardwareAvailability:'unverified'}});
 }catch{return res.status(503).json({status:'fail',message:'Approved chargers could not be loaded.'});}
}
