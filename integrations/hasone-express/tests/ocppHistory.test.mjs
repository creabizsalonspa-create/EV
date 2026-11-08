import test from 'node:test';
import assert from 'node:assert/strict';
import {parseHistoryPage,presentOcppHistory} from '../ocppHistory.mjs';
const sid='507f1f77bcf86cd799439011',bid='507f1f77bcf86cd799439012';
const token='a'.repeat(64);
const fixture=()=>({
 link:{_id:'session-1',bookingId:bid,userId:'user-A',sourceType:'ocpp',
   sourceSessionId:sid,sourceChargerId:'charger-A',sourceTransactionId:'tx-A'},
 booking:{_id:bid,userId:'user-A',stationId:'station-A',stationName:'Test',
   ocppChargerId:'charger-A',ocppTokenHash:token,isPaid:true,hasArrived:true},
 charger:{stationId:'station-A',chargerId:'charger-A',protocol:'ocpp1.6',enabled:true},
 source:{_id:sid,chargerId:'charger-A',transactionId:'tx-A',protocol:'ocpp1.6',
   idTagHash:token,startedAt:'2026-11-07T00:00:00Z',endedAt:'2026-11-07T00:10:00Z',
   status:'ended',meterStartWh:1000,meterLastWh:6000,energyWh:5000,energyKwh:5,
   tariff:{currency:'AED',rateFilsPerKwh:100,vatPercent:5},subtotalFils:500,
   vatFils:25,totalFils:525,billingStatus:'calculated_unpaid',paymentStatus:'not_collected'}
});
test('history exposes exact verified duration/energy/unpaid charge without token evidence',()=>{
 const row=presentOcppHistory(fixture());
 assert.equal(row.state,'ended');assert.equal(row.energyKwh,5);
 assert.equal(row.durationSeconds,600);assert.equal(row.totalFils,525);
 assert.equal(row.paymentStatus,'not_collected');
 assert.equal(JSON.stringify(row).includes(token),false);
 assert.equal(row.bookingPrepaymentRecorded,true);
});
test('disabling a charger does not erase verified ended history',()=>{
 const data=fixture();data.charger.enabled=false;
 const row=presentOcppHistory(data);
 assert.equal(row.state,'ended');assert.equal(row.chargerEnabled,false);
 assert.equal(row.energyKwh,5);
});
test('broken charger mapping yields review state and no telemetry or invoice',()=>{
 const data=fixture();data.charger.stationId='other-station';
 const row=presentOcppHistory(data);
 assert.equal(row.state,'unverified');assert.equal(row.sourceVerified,false);
 assert.equal(row.energyKwh,null);assert.equal(row.totalFils,null);
 assert.equal(row.transactionId,null);
});
test('changed source meter/hash cannot be presented as verified history',()=>{
 for(const update of [{energyWh:7000},{idTagHash:'b'.repeat(64)}, {startedAt:null}]){
   const data=fixture();Object.assign(data.source,update);
   const row=presentOcppHistory(data);
   assert.equal(row.state,'unverified');assert.equal(row.energyKwh,null);
   assert.equal(row.totalFils,null);
 }
});
test('active source may show verified energy but never a final duration or total',()=>{
 const data=fixture();data.source.status='active';delete data.source.endedAt;
 data.source.billingStatus='provisional_unpaid';
 const row=presentOcppHistory(data);
 assert.equal(row.state,'active');assert.equal(row.energyKwh,5);
 assert.equal(row.durationSeconds,null);assert.equal(row.totalFils,null);
});
test('cross-owner or cross-booking links cannot produce history rows',()=>{
 const data=fixture();data.link.userId='user-B';
 assert.equal(presentOcppHistory(data),null);
 data.link.userId='user-A';data.link.bookingId='other-booking';
 assert.equal(presentOcppHistory(data),null);
});
test('history query accepts only bounded page size and immutable record cursor',()=>{
 assert.deepEqual(parseHistoryPage({}),{limit:25,cursor:null});
 assert.deepEqual(parseHistoryPage({limit:'100',cursor:sid}),{limit:100,cursor:sid});
 for(const query of [{limit:'0'},{limit:'101'},{limit:['2']},{cursor:{$gt:sid}},
   {cursor:'bad'},{userId:'someone-else'},{limit:'2.5'}])
   assert.throws(()=>parseHistoryPage(query));
});

test('missing or changed approved protocol retains only a redacted history review row',()=>{
 for(const protocol of [undefined,null,'ocpp2.0.1','unsupported']) {
  const data=fixture();data.charger.protocol=protocol;
  const row=presentOcppHistory(data);
  assert.equal(row.sourceVerified,false);assert.equal(row.state,'unverified');
  assert.equal(row.energyKwh,null);assert.equal(row.totalFils,null);
  assert.equal(row.transactionId,null);assert.equal(row.reviewReason,'source_evidence_unverified');
 }
});
