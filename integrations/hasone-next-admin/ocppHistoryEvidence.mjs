/** Validate read-only source history before presenting charging/price claims.
 * These checks do not grant server authority or collect/reprice a payment.
 */
const record=x=>x!==null&&typeof x==='object'&&!Array.isArray(x);
const id=x=>typeof x==='string'&&/^[a-f0-9]{24}$/i.test(x);
const integer=x=>Number.isSafeInteger(x)&&x>=0&&x<=1e12;
const time=x=>{
 if(typeof x!=='string'||!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(x))return false;
 const d=new Date(x);return Number.isFinite(d.valueOf())&&d.toISOString()===x;
};
const scope=x=>record(x)&&Number.isInteger(x.connectorId)&&x.connectorId>=1&&x.connectorId<=64&&
 ((x.protocol==='ocpp1.6'&&x.evseId===null)||
  (x.protocol==='ocpp2.0.1'&&Number.isInteger(x.evseId)&&x.evseId>=1&&x.evseId<=64));
export function validOcppHistoryRow(row,{sessionId=null,bookingId=null}={}){
 if(!record(row)||!id(row.sessionId)||!id(row.bookingId)||
    (sessionId!==null&&row.sessionId!==sessionId)||(bookingId!==null&&row.bookingId!==bookingId)||
    row.readOnly!==true||row.paymentStatus!=='not_collected'||typeof row.stationName!=='string'||
    (row.chargerId!==null&&(typeof row.chargerId!=='string'||!/^[A-Za-z0-9_-]{1,64}$/.test(row.chargerId)))||
    ['chargerEnabled','sourceVerified','meterVerified','resourceVerified','bookingPrepaymentRecorded']
      .some(k=>typeof row[k]!=='boolean'))return false;
 if(row.resourceVerified){
  if(!row.sourceVerified||!scope(row.resourceScope)||row.resourceScope.protocol!==row.protocol)return false;
 }else if(row.resourceScope!==null)return false;
 if(!row.sourceVerified)return row.state==='unverified'&&!row.meterVerified&&!row.resourceVerified&&
  row.billingStatus==='unverified'&&['charger_mapping_unavailable','source_evidence_unverified'].includes(row.reviewReason)&&
  ['protocol','transactionId','sourceSessionId','startedAt','endedAt','durationSeconds','energyWh','energyKwh',
   'meterStartWh','meterLastWh','calculatedUnpaidAed','subtotalFils','vatFils','totalFils'].every(k=>row[k]===null);
 if(!row.bookingPrepaymentRecorded||!row.chargerId||!id(row.sourceSessionId)||
    !['ocpp1.6','ocpp2.0.1'].includes(row.protocol)||typeof row.transactionId!=='string'||
    !row.transactionId||row.transactionId.length>128||!time(row.startedAt))return false;
 const ended=row.endedAt!==null;
 if(ended){
  if(!time(row.endedAt)||new Date(row.endedAt)<new Date(row.startedAt)||
     row.durationSeconds!==Math.floor((new Date(row.endedAt)-new Date(row.startedAt))/1000))return false;
 }else if(row.durationSeconds!==null)return false;
 if(row.meterVerified){
  if(row.state!==(ended?'ended':'active')||row.reviewReason!==null||
     !integer(row.meterStartWh)||!integer(row.meterLastWh)||row.meterLastWh<row.meterStartWh||
     row.energyWh!==row.meterLastWh-row.meterStartWh||row.energyKwh!==row.energyWh/1000)return false;
 }else if(row.state!=='unverified'||row.reviewReason!=='meter_evidence_unverified'||
   ['energyWh','energyKwh','meterStartWh','meterLastWh'].some(k=>row[k]!==null))return false;
 if(!['unverified','provisional_unpaid','calculated_unpaid'].includes(row.billingStatus))return false;
 if(row.billingStatus==='calculated_unpaid')return row.meterVerified&&ended&&
  integer(row.subtotalFils)&&integer(row.vatFils)&&integer(row.totalFils)&&
  row.totalFils===row.subtotalFils+row.vatFils&&row.calculatedUnpaidAed===row.totalFils/100;
 if(row.billingStatus==='provisional_unpaid'&&(!row.meterVerified||ended))return false;
 return ['calculatedUnpaidAed','subtotalFils','vatFils','totalFils'].every(k=>row[k]===null);
}
export function validOcppHistoryPage(response,{cursor=null,limit=25}={}){
 if(!record(response)||response.status!=='Success'||!record(response.data)||
    (cursor!==null&&!id(cursor))||!Number.isInteger(limit)||limit<1||limit>100)return false;
 const p=response.data;
 if(p.readOnly!==true||p.currency!=='AED'||p.billingMode!=='calculation_only_no_collection'||
    !Array.isArray(p.entries)||p.entries.length>limit||typeof p.hasMore!=='boolean'||
    !Number.isInteger(p.scanned)||p.scanned<p.entries.length||p.scanned>limit||
    p.unavailableCount!==p.scanned-p.entries.length)return false;
 if(p.hasMore?(!id(p.nextCursor)||p.scanned!==limit||
   (cursor!==null&&p.nextCursor.toLowerCase()>=cursor.toLowerCase())):p.nextCursor!==null)return false;
 let previous=cursor?.toLowerCase()??null;
 for(const row of p.entries){
  if(!validOcppHistoryRow(row)||(previous!==null&&row.sessionId.toLowerCase()>=previous))return false;
  previous=row.sessionId.toLowerCase();
 }
 return true;
}
