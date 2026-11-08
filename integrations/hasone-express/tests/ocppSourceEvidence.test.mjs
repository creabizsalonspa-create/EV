import test from 'node:test';
import assert from 'node:assert/strict';
import {verifyOcppSource} from '../ocppSourceEvidence.mjs';
const sid='507f1f77bcf86cd799439011', charger='agent2-source-charger';
const token='a'.repeat(64);
const binding=()=>({sourceId:sid,chargerId:charger,transactionId:'tx-agent2',tokenHash:token});
const doc=()=>({_id:sid,chargerId:charger,protocol:'ocpp1.6',transactionId:'tx-agent2',
  idTagHash:token,status:'ended',startedAt:'2026-11-07T00:00:00Z',
  endedAt:'2026-11-07T00:10:00Z',meterStartWh:1000,meterLastWh:8000,
  energyWh:7000,energyKwh:7,subtotalFils:525,vatFils:26,totalFils:551,
  tariff:{currency:'AED',rateFilsPerKwh:75,vatPercent:5},billingStatus:'calculated_unpaid',paymentStatus:'not_collected'});
test('linked 1.6 source yields verified unpaid Wh, kWh and AED fils',()=>{
  const out=verifyOcppSource(doc(),binding());
  assert.equal(out.sourceVerified,true);assert.equal(out.meterVerified,true);
  assert.equal(out.energyWh,7000);assert.equal(out.energyKwh,7);
  assert.equal(out.totalFils,551);assert.equal(out.paymentStatus,'not_collected');
});
test('wrong charger, token, source session and transaction are always rejected',()=>{
  for(const field of ['sourceId','chargerId','transactionId','tokenHash']){
    assert.equal(verifyOcppSource(doc(),{...binding(),[field]:'invalid'}),null);
  }
});
test('OCPP 2.0.1 binds idTokenHash instead of a client-provided raw token',()=>{
  const s={...doc(),protocol:'ocpp2.0.1',idTagHash:undefined,idTokenHash:token};
  assert.equal(verifyOcppSource(s,binding()).meterVerified,true);
  assert.equal(verifyOcppSource({...s,idTokenHash:undefined},binding()),null);
});
test('source without valid meter evidence never produces a kWh or invoice',()=>{
  const p=verifyOcppSource({...doc(),meterLastWh:null},binding());
  assert.equal(p.sourceVerified,true);assert.equal(p.meterVerified,false);
  assert.equal(p.energyKwh,null);assert.equal(p.totalFils,null);
});
test('reversed meter values and tampered kWh are not billable',()=>{
  assert.equal(verifyOcppSource({...doc(),energyWh:8000},binding()),null);
  const p=verifyOcppSource({...doc(),meterLastWh:500},binding());
  assert.equal(p.meterVerified,false);assert.equal(p.totalFils,null);
});
test('mismatched or non-AED invoice cannot be marked source-billable',()=>{
  assert.equal(verifyOcppSource({...doc(),totalFils:999},binding()).totalFils,null);
  assert.equal(verifyOcppSource({...doc(),tariff:{currency:'USD'}},binding()).totalFils,null);
});
test('unexpected paid status is never proof of a merchant charge',()=>{
  assert.equal(verifyOcppSource({...doc(),paymentStatus:'paid'},binding()),null);
});
test('unclosed OCPP session may show metered kWh, never final bill',()=>{
  const s={...doc(),status:'active',endedAt:undefined,billingStatus:'provisional_unpaid'};
  const p=verifyOcppSource(s,binding());
  assert.equal(p.meterVerified,true);assert.equal(p.sessionEnded,false);
  assert.equal(p.totalFils,null);
});
test('malformed or chronological session evidence is rejected',()=>{
  assert.equal(verifyOcppSource({...doc(),endedAt:'2026-11-06T23:59:00Z'},binding()),null);
  assert.equal(verifyOcppSource({...doc(),protocol:'unknown'},binding()),null);
});

test('tampered source tariff, subtotal or tax is never treated as payable',()=>{
  assert.equal(verifyOcppSource({...doc(),subtotalFils:1},binding()).totalFils,null);
  assert.equal(verifyOcppSource({...doc(),vatFils:1},binding()).totalFils,null);
  assert.equal(verifyOcppSource({...doc(),tariff:{currency:'AED',rateFilsPerKwh:20,vatPercent:5}},binding()).totalFils,null);
});
test('verified AED rate, VAT and subtotal are available for the read-only summary',()=>{
  const p=verifyOcppSource(doc(),binding());
  assert.equal(p.subtotalFils,525);
  assert.equal(p.vatFils,26);
  assert.equal(p.rateAedPerKwh,.75);
  assert.equal(p.vatPercent,5);
});

test('missing, coerced or impossible source timestamps never verify charging',()=>{
 for(const value of [null,false,true,0,1,{},[], '0','2026-02-31T00:00:00Z',
   '2026-11-07T24:00:00Z','2026-11-07 00:00:00','2026-11-07T00:00:00']){
   assert.equal(verifyOcppSource({...doc(),startedAt:value},binding()),null);
   assert.equal(verifyOcppSource({...doc(),endedAt:value},binding()),null);
 }
});
test('Mongo Date and explicitly zoned ISO timestamps remain supported',()=>{
 assert.equal(verifyOcppSource({...doc(),
   startedAt:new Date('2026-11-07T00:00:00Z'),
   endedAt:new Date('2026-11-07T00:10:00Z')},binding()).sourceVerified,true);
 assert.equal(verifyOcppSource({...doc(),
   startedAt:'2026-11-07T04:00:00+04:00',
   endedAt:'2026-11-07T04:10:00+04:00'},binding()).totalFils,551);
});
test('source billing labels are not trusted until their meter and invoice proof pass',()=>{
 const missingMeter=verifyOcppSource({...doc(),meterLastWh:null},binding());
 assert.equal(missingMeter.billingStatus,'unverified');
 const badInvoice=verifyOcppSource({...doc(),totalFils:999},binding());
 assert.equal(badInvoice.billingStatus,'unverified');
 const active=verifyOcppSource({...doc(),status:'active',billingStatus:'calculated_unpaid'},binding());
 assert.equal(active.billingStatus,'unverified');
 assert.equal(active.totalFils,null);
});


test('active calculated amounts are separately verified and never a final invoice',()=>{
 const active={...doc(),status:'active',endedAt:undefined,billingStatus:'provisional_unpaid'};
 const proof=verifyOcppSource(active,binding());
 assert.equal(proof.provisionalCostVerified,true);assert.equal(proof.provisionalTotalFils,551);
 assert.equal(proof.provisionalSubtotalFils,525);assert.equal(proof.provisionalVatFils,26);
 assert.equal(proof.totalFils,null);assert.equal(proof.billingStatus,'provisional_unpaid');
 assert.equal(verifyOcppSource(doc(),binding()).provisionalCostVerified,false);
});
test('tampered active cost/currency/tariff cannot be exposed as a verified estimate',()=>{
 const active={...doc(),status:'active',endedAt:undefined,billingStatus:'provisional_unpaid'};
 for(const update of [{totalFils:999},{subtotalFils:-1},{vatFils:0},
   {tariff:{currency:'USD',rateFilsPerKwh:75,vatPercent:5}},
   {tariff:{currency:'AED',rateFilsPerKwh:1e20,vatPercent:5}},
   {billingStatus:'calculated_unpaid'},{paymentStatus:'paid'}]){
  const proof=verifyOcppSource({...active,...update},binding());
  assert.equal(proof?.provisionalCostVerified??false,false);
  assert.equal(proof?.provisionalTotalFils??null,null);
 }
});
test('accepted meter receipt ages from recent to stale without inventing a timestamp',()=>{
 const received=new Date('2026-11-07T00:01:00Z');
 const source={...doc(),lastMeterReceivedAt:received};
 const recent=verifyOcppSource(source,{...binding(),now:new Date('2026-11-07T00:03:00Z')});
 assert.equal(recent.meterReceiptAgeSeconds,120);assert.equal(recent.meterFreshness,'recent');
 const stale=verifyOcppSource(source,{...binding(),now:new Date('2026-11-07T00:03:01Z')});
 assert.equal(stale.meterFreshness,'stale');assert.equal(stale.meterReceiptAgeSeconds,121);
 for(const value of [null,false,'2026-02-31T00:00:00Z','2026-11-07T00:10:01Z']){
  const p=verifyOcppSource({...source,lastMeterReceivedAt:value},
    {...binding(),now:new Date('2026-11-07T00:10:00Z')});
  assert.equal(p.meterReceivedAt,null);assert.equal(p.meterFreshness,'unknown');
 }
});
test('source-ended reasons distinguish operator review without claiming a successful charge',()=>{
  for(const reason of ['EmergencyStop','HardReset','PowerLoss','Reboot','SoftReset','GroundFault','ImmediateReset','OvercurrentFault','PowerQuality','Timeout','Other']){
    const value=verifyOcppSource({...doc(),stopReason:reason},binding());
    assert.deepEqual(value.sourceEnd,{reason,reviewRequired:true});
    assert.equal(value.totalFils,551);assert.equal(value.paymentStatus,'not_collected');
  }
  for(const reason of ['Local','Remote','EVDisconnected','StoppedByEV','EnergyLimitReached'])
    assert.deepEqual(verifyOcppSource({...doc(),stopReason:reason},binding()).sourceEnd,{reason,reviewRequired:false});
});
test('unknown source end reasons remain bounded and active records cannot claim an end',()=>{
  for(const reason of [null,undefined,'Unknown','<script>alert(1)</script>','Remote\n','x'.repeat(10000),{}])
    assert.deepEqual(verifyOcppSource({...doc(),stopReason:reason},binding()).sourceEnd,{reason:null,reviewRequired:true});
  assert.equal(verifyOcppSource({...doc(),status:'active',stopReason:'EmergencyStop'},binding()).sourceEnd,null);
});

test('source-qualified connector fault does not fabricate missing meter or cost',()=>{
 const now=new Date('2026-11-08T01:00:00Z');
 const source={...doc(),status:'active',connectorId:2,startedAt:new Date(now-600000),startedReceivedAt:new Date(now-600000),
   meterStartWh:null,meterLastWh:null,energyWh:null,energyKwh:null};
 const chargerRecord={chargerId:charger,enabled:true,connectors:{'0_2':{
   protocol:'ocpp1.6',connectorId:2,evseId:null,status:'Faulted',receivedAt:new Date(now-30000)}}};
 const out=verifyOcppSource(source,{...binding(),now,chargerRecord});
 assert.equal(out.sourceVerified,true);assert.equal(out.meterVerified,false);
 assert.equal(out.energyWh,null);assert.equal(out.totalFils,null);assert.equal(out.provisionalCostVerified,false);
 assert.equal(out.connectorObservation.status,'Faulted');
 assert.equal(out.connectorObservation.receiptAgeSeconds,30);
});
