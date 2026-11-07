/**
 * Redacted read-only history projection. Financial amounts are calculated and
 * unpaid; source states never imply command or merchant acceptance.
 */
import {verifyOcppSource} from './ocppSourceEvidence.mjs';
export function parseHistoryPage(query={}){
 if(Object.keys(query).some(k=>!['limit','cursor'].includes(k)))
   throw Error('Unsupported history query');
 if(query.limit!==undefined && (typeof query.limit!=='string' ||
   !/^[1-9][0-9]{0,2}$/.test(query.limit) || Number(query.limit)>100))
   throw Error('Invalid history page size');
 if(query.cursor!==undefined && (typeof query.cursor!=='string' ||
   !/^[a-f0-9]{24}$/i.test(query.cursor)))throw Error('Invalid history cursor');
 return {limit:query.limit===undefined?25:Number(query.limit),cursor:query.cursor??null};
}
export function presentOcppHistory({link,booking,charger,source}){
 if(!link || !booking || String(link.bookingId)!==String(booking._id) ||
   String(link.userId)!==String(booking.userId))return null;
 const mapped=!!charger && String(charger.stationId)===String(booking.stationId) &&
   charger.chargerId===booking.ocppChargerId &&
   link.sourceChargerId===booking.ocppChargerId;
 const proof=mapped && booking.isPaid===true && booking.hasArrived===true
   ?verifyOcppSource(source,{sourceId:link.sourceSessionId,
      chargerId:link.sourceChargerId,transactionId:link.sourceTransactionId,
      tokenHash:booking.ocppTokenHash,resourceScope:booking.ocppResourceScope??null}):null;
 const meter=proof?.meterVerified===true;
 const verified=proof?.sourceVerified===true;
 const start=verified?new Date(source.startedAt):null;
 const end=verified && proof.sessionEnded?new Date(source.endedAt):null;
 return {
   sessionId:String(link._id),bookingId:String(booking._id),
   stationName:typeof booking.stationName==='string'?booking.stationName:'',
   chargerId:booking.ocppChargerId||null,
   chargerEnabled:charger?.enabled===true,
   protocol:verified?proof.protocol:null,
   state:verified && meter?(proof.sessionEnded?'ended':'active'):'unverified',
   sourceVerified:verified,meterVerified:meter,
   resourceVerified:proof?.resourceVerified===true,resourceScope:proof?.resourceScope??null,
   reviewReason:!mapped?'charger_mapping_unavailable':
     !verified?'source_evidence_unverified':!meter?'meter_evidence_unverified':null,
   bookingPrepaymentRecorded:booking.isPaid===true,
   startedAt:start?.toISOString()??null,endedAt:end?.toISOString()??null,
   durationSeconds:start && end?Math.floor((end-start)/1000):null,
   energyWh:meter?proof.energyWh:null,energyKwh:meter?proof.energyKwh:null,
   meterStartWh:meter?source.meterStartWh:null,meterLastWh:meter?source.meterLastWh:null,
   calculatedUnpaidAed:proof?.totalFils==null?null:proof.totalFils/100,
   subtotalFils:proof?.subtotalFils??null,vatFils:proof?.vatFils??null,
   totalFils:proof?.totalFils??null,
   billingStatus:proof?.billingStatus??'unverified',paymentStatus:'not_collected',
   sourceSessionId:verified?String(source._id):null,
   transactionId:verified?String(source.transactionId):null,
   readOnly:true
 };
}
