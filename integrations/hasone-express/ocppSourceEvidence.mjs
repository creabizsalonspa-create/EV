import {presentConnectorObservation} from './ocppConnectorObservation.mjs';
import {normalizeResourceScope,sameResourceScope,sourceResourceScope} from './ocppResourceScope.mjs';
/**
 * Fail-closed, read-only authorization and metering proof for an OCPP session.
 * Never changes payments or starts/stops hardware.
 *
 * Caller MUST enforce the authenticated customer's booking ownership and
 * approved station-to-charger binding before passing the expected values.
 */
const same = (left,right)=> String(left??'') !== '' && String(left)===String(right);
const wh = v=> Number.isInteger(v) && v>=0 && v<=1e12;
const fils = v=> Number.isInteger(v) && v>=0 && v<=1e12;
const sourceDate=value=>{
  if(value instanceof Date)return Number.isFinite(value.valueOf())?value:null;
  if(typeof value!=='string' ||
     !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/.test(value))
    return null;
  const [year,month,day]=value.slice(0,10).split('-').map(Number);
  if(month<1 || month>12 || day<1 ||
     day>new Date(Date.UTC(year,month,0)).getUTCDate() ||
     Number(value.slice(11,13))>23)return null;
  const parsed=new Date(value);
  return Number.isFinite(parsed.valueOf())?parsed:null;
};

/** Compare authorization against gateway time when an accepted-start receipt
 * exists. A malformed receipt never falls back to a device clock. Legacy
 * records without the field keep only the prior bounded two-minute tolerance.
 */
export function sourceStartMatchesAuthorization(source,issuedAt,now=new Date()){
  const issued=sourceDate(issuedAt),device=sourceDate(source?.startedAt);
  if(!issued || !device || !(now instanceof Date) ||
      !Number.isFinite(now.valueOf()) || issued>now)return false;
  if(Object.hasOwn(source,'startedReceivedAt')){
    const received=source.startedReceivedAt;
    return received instanceof Date && Number.isFinite(received.valueOf()) &&
      received>=issued && received<=now;
  }
  return device.valueOf()>=issued.valueOf()-120000 &&
    device.valueOf()<=now.valueOf()+120000;
}

// Bounded presentation policy, not a fault diagnosis or permission to restart.
const endReasons=new Set(['EmergencyStop','EVDisconnected','HardReset','Local','Other',
  'PowerLoss','Reboot','Remote','SoftReset','UnlockCommand','DeAuthorized',
  'EnergyLimitReached','GroundFault','ImmediateReset','LocalOutOfCredit',
  'OvercurrentFault','PowerQuality','SOCLimitReached','StoppedByEV','TimeLimitReached','Timeout']);
const interruptedReasons=new Set(['EmergencyStop','HardReset','PowerLoss','Reboot',
  'SoftReset','GroundFault','ImmediateReset','OvercurrentFault','PowerQuality','Timeout']);

export function verifyOcppSource(source,{sourceId,chargerId,transactionId,tokenHash,resourceScope=null,chargerRecord=null,now=new Date()}={}) {
  if(!source || typeof source!=='object' || !sourceId || !chargerId || !transactionId || !tokenHash)
    return null;
  if(!/^[a-f0-9]{64}$/i.test(tokenHash) || !same(source._id,sourceId)
      || !same(source.chargerId,chargerId) || !same(source.transactionId,transactionId)
      || !['ocpp1.6','ocpp2.0.1'].includes(source.protocol)
      || !['active','ended'].includes(source.status)) return null;
  // A known registered protocol must agree with the observed source. Never
  // upgrade a missing/changed registry protocol from token or device claims.
  if(chargerRecord!==null && (!chargerRecord || chargerRecord.chargerId!==chargerId ||
      chargerRecord.protocol!==source.protocol))return null;
  const field=source.protocol==='ocpp1.6'?'idTagHash':'idTokenHash';
  if(source[field]!==tokenHash) return null;
  const observedScope=sourceResourceScope(source);
  if(resourceScope!=null && (!normalizeResourceScope(resourceScope) ||
      !sameResourceScope(resourceScope,observedScope)))return null;
  const start=sourceDate(source.startedAt);
  if(!start)return null;
  const ended=source.status==='ended';
  if(ended && (!sourceDate(source.endedAt) || !(sourceDate(source.endedAt)>=start)))return null;
  if(source.paymentStatus!=='not_collected')return null;
  const evidence={
    connectorObservation:presentConnectorObservation(source,chargerRecord,{now}),
    sourceVerified:true,
    resourceVerified:resourceScope!=null && !!observedScope,
    resourceScope:resourceScope!=null?observedScope:null,
    meterVerified:false,
    source:'ocpp',
    protocol:source.protocol,
    chargerId:source.chargerId,
    transactionId:String(source.transactionId),
    sessionEnded:ended,
    sourceEnd:ended?{
      reason:endReasons.has(source.stopReason)?source.stopReason:null,
      reviewRequired:!endReasons.has(source.stopReason)||source.stopReason==='Other'||interruptedReasons.has(source.stopReason)
    }:null,
    energyWh:null,
    energyKwh:null,
    totalFils:null,
    subtotalFils:null,
    vatFils:null,
    rateAedPerKwh:null,
    vatPercent:null,
    billingStatus:'unverified',
    paymentStatus:'not_collected',
    provisionalCostVerified:false,provisionalSubtotalFils:null,
    provisionalVatFils:null,provisionalTotalFils:null,
    meterReceivedAt:null,meterReceiptAgeSeconds:null,meterFreshness:'unknown'
  };
  const receipt=sourceDate(source.lastMeterReceivedAt);
  // Gateway receipt chronology is independent of a skewed charger clock.
  // Old records without accepted-start metadata retain their legacy bound.
  const gatewayStart=Object.hasOwn(source,'startedReceivedAt')
    ?(source.startedReceivedAt instanceof Date && Number.isFinite(source.startedReceivedAt.valueOf())
      ?source.startedReceivedAt:null):start;
  if(receipt && gatewayStart && receipt>=gatewayStart && now instanceof Date &&
      Number.isFinite(now.valueOf()) && gatewayStart<=now && receipt<=now){
    evidence.meterReceivedAt=receipt.toISOString();
    evidence.meterReceiptAgeSeconds=Math.floor((now-receipt)/1000);
    evidence.meterFreshness=evidence.meterReceiptAgeSeconds<=120?'recent':'stale';
  }
  if(!wh(source.meterStartWh) || !wh(source.meterLastWh)
      || source.meterLastWh<source.meterStartWh)return evidence;
  const delta=source.meterLastWh-source.meterStartWh;
  if(source.energyWh!==delta || source.energyKwh!==delta/1000)return null;
  evidence.meterVerified=true;
  evidence.energyWh=delta;
  evidence.energyKwh=delta/1000;
  const invoiceMatches=fils(source.subtotalFils) && fils(source.vatFils) && fils(source.totalFils)
      && source.totalFils===source.subtotalFils+source.vatFils
      && source.tariff?.currency==='AED'
      && Number.isSafeInteger(source.tariff.rateFilsPerKwh)
      && source.tariff.rateFilsPerKwh>=0
      && Number.isFinite(source.tariff.vatPercent)
      && source.tariff.vatPercent>=0 && source.tariff.vatPercent<=100
      && source.subtotalFils===Math.round(delta*source.tariff.rateFilsPerKwh/1000)
      && source.vatFils===Math.round(source.subtotalFils*source.tariff.vatPercent/100);
  if(!ended && source.billingStatus==='provisional_unpaid' && invoiceMatches){
    evidence.provisionalCostVerified=true;
    evidence.provisionalSubtotalFils=source.subtotalFils;
    evidence.provisionalVatFils=source.vatFils;
    evidence.provisionalTotalFils=source.totalFils;
    evidence.billingStatus='provisional_unpaid';
  }
  if(ended && source.billingStatus==='calculated_unpaid' && invoiceMatches){
    evidence.totalFils=source.totalFils;
    evidence.subtotalFils=source.subtotalFils;
    evidence.vatFils=source.vatFils;
    evidence.rateAedPerKwh=source.tariff.rateFilsPerKwh/100;
    evidence.vatPercent=source.tariff.vatPercent;
    evidence.billingStatus='calculated_unpaid';
  }
  return evidence;
}

