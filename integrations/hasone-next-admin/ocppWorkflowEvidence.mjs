/** Read-only Admin response checks. They never authorize a server mutation. */
const record=x=>x!==null&&typeof x==='object'&&!Array.isArray(x);
const id=x=>typeof x==='string'&&/^[a-f0-9]{24}$/i.test(x);
const charger=x=>typeof x==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(x);
const scope=x=>record(x)&&Number.isInteger(x.connectorId)&&x.connectorId>=1&&x.connectorId<=64&&
 ((x.protocol==='ocpp1.6'&&x.evseId===null)||
  (x.protocol==='ocpp2.0.1'&&Number.isInteger(x.evseId)&&x.evseId>=1&&x.evseId<=64));
const sameScope=(a,b)=>scope(a)&&scope(b)&&a.protocol===b.protocol&&a.connectorId===b.connectorId&&a.evseId===b.evseId;
const iso=x=>{
 if(typeof x!=='string'||!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/.test(x))return false;
 const d=new Date(x);return Number.isFinite(d.valueOf())&&d.toISOString().slice(0,19)===x.slice(0,19);
};
export function validOcppWorkflowState(response,{bookingId,stationId=null}={}){
 if(!record(response)||response.status!=='Success'||!record(response.data)||!id(bookingId))return false;
 const d=response.data;
 if(d.bookingId!==bookingId||!id(d.stationId)||(stationId!==null&&d.stationId!==stationId)||
    !Array.isArray(d.approvedChargers)||d.approvedChargers.length>128)return false;
 const seen=new Set();
 for(const c of d.approvedChargers){
  if(!record(c)||!charger(c.chargerId)||!['ocpp1.6','ocpp2.0.1'].includes(c.protocol)||
      typeof c.online!=='boolean'||seen.has(c.chargerId))return false;
  seen.add(c.chargerId);
 }
 if(d.approvedChargerId!==null&&(!charger(d.approvedChargerId)||!seen.has(d.approvedChargerId)))return false;
 if(d.selectedChargerId!==null&&!charger(d.selectedChargerId))return false;
 if(['bookingPrepaymentRecorded','arrivalRecorded','authorizationIssued','sourceVerified',
   'meterVerified','sourceEnded','canIssueToken','canReissueToken','canBindSource',
   'canFinalizeSource','needsPaymentReconciliation'].some(k=>typeof d[k]!=='boolean'))return false;
 if(d.resourceScope!=null&&!scope(d.resourceScope))return false;
 if(d.sourceSessionId!==null&&!id(d.sourceSessionId))return false;
 if(d.sourceVerified&&!d.sourceSessionId)return false;
 if(d.meterVerified&&(!d.sourceVerified||typeof d.energyKwh!=='number'||!Number.isFinite(d.energyKwh)||d.energyKwh<0))return false;
 if(d.sourceEnded&&!d.sourceVerified)return false;
 if(!['Upcoming','Completed','Cancelled'].includes(d.bookingStatus))return false;
 if(!d.meterVerified&&d.energyKwh!==null)return false;
 const amount=x=>typeof x==='number'&&Number.isFinite(x)&&x>=0&&x<=1e10;
 if(d.calculatedUnpaidAed!==null&&(!d.sourceVerified||!d.meterVerified||!d.sourceEnded||!amount(d.calculatedUnpaidAed)))return false;
 if(d.provisionalUnpaidAed!=null&&(!d.sourceVerified||!d.meterVerified||d.sourceEnded||!amount(d.provisionalUnpaidAed)))return false;
 if(d.paymentStatus!==(d.sourceVerified?'not_collected':null))return false;
 return true;
}
export function validOcppAuthorizationReceipt(response,{bookingId,chargerId,resourceScope=null,reissued=false}={}){
 if(!record(response)||response.status!=='Success'||!record(response.data)||
     !id(bookingId)||!charger(chargerId))return false;
 const d=response.data;
 if(d.bookingId!==bookingId||d.chargerId!==chargerId||
    typeof d.oneTimeToken!=='string'||!/^[A-F0-9]{20}$/.test(d.oneTimeToken)||
    !iso(d.expiresAt)||d.chargingSessionConfirmed!==false||
    (reissued?d.reissued!==true:d.reissued!==undefined))return false;
 if(resourceScope===null)return d.connectorScoped===false&&d.resourceScope===null;
 return d.connectorScoped===true&&sameScope(resourceScope,d.resourceScope);
}
