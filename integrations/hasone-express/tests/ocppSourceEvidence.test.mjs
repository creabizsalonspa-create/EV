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
