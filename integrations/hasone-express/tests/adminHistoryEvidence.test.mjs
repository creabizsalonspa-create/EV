import test from 'node:test';
import assert from 'node:assert/strict';
import {validOcppHistoryPage,validOcppHistoryRow} from '../../hasone-next-admin/ocppHistoryEvidence.mjs';
import {presentOcppHistory} from '../ocppHistory.mjs';
const sid='507f1f77bcf86cd799439011',bid='507f1f77bcf86cd799439012',sourceId='507f1f77bcf86cd799439013';
function fixture(){return {
 link:{_id:sid,bookingId:bid,userId:'owner',sourceType:'ocpp',sourceSessionId:sourceId,sourceChargerId:'CH-1',sourceTransactionId:'tx-1'},
 booking:{_id:bid,userId:'owner',stationId:'station',stationName:'Synthetic station',isPaid:true,hasArrived:true,ocppChargerId:'CH-1',ocppTokenHash:'a'.repeat(64)},
 charger:{stationId:'station',chargerId:'CH-1',protocol:'ocpp1.6',enabled:true},
 source:{_id:sourceId,chargerId:'CH-1',protocol:'ocpp1.6',transactionId:'tx-1',idTagHash:'a'.repeat(64),
  startedAt:'2026-11-08T00:00:00Z',endedAt:'2026-11-08T00:10:00Z',status:'ended',
  meterStartWh:1000,meterLastWh:6000,energyWh:5000,energyKwh:5,paymentStatus:'not_collected',
  tariff:{currency:'AED',rateFilsPerKwh:100,vatPercent:5},subtotalFils:500,vatFils:25,totalFils:525,billingStatus:'calculated_unpaid'}
};}
const row=()=>presentOcppHistory(fixture());
const page=entries=>({status:'Success',data:{entries,scanned:entries.length,unavailableCount:0,hasMore:false,nextCursor:null,currency:'AED',billingMode:'calculation_only_no_collection',readOnly:true}});
test('Admin history accepts actual backend projections including disabled, redacted and pre-meter sources',()=>{
 const variants=[fixture(),fixture(),fixture(),fixture(),fixture(),fixture()];
 variants[1].charger.enabled=false;variants[2].charger=null;
 variants[3].source.meterLastWh=null;
 variants[4].source.status='active';variants[4].source.billingStatus='provisional_unpaid';
 variants[5].booking.ocppResourceScope={protocol:'ocpp2.0.1',connectorId:2,evseId:3};
 Object.assign(variants[5].source,{protocol:'ocpp2.0.1',connectorId:2,evseId:3,idTokenHash:'a'.repeat(64)});
 variants[5].charger.protocol='ocpp2.0.1';
 for(const v of variants){const projected=presentOcppHistory(v);assert.equal(validOcppHistoryRow(projected),true,JSON.stringify(projected));assert.equal(validOcppHistoryPage(page([projected])),true);}
});
test('detail receipt must match both selected history session and booking',()=>{
 assert.equal(validOcppHistoryRow(row(),{sessionId:sid,bookingId:bid}),true);
 assert.equal(validOcppHistoryRow(row(),{sessionId:bid,bookingId:bid}),false);
 assert.equal(validOcppHistoryRow(row(),{sessionId:sid,bookingId:sid}),false);
});
test('history rejects telemetry, settlement and resource claims unsupported by source proof',()=>{
 for(const patch of [{sourceVerified:false},{meterVerified:false},{paymentStatus:'collected'},
  {energyKwh:99},{energyWh:999},{totalFils:999},{calculatedUnpaidAed:99},
  {resourceVerified:true,resourceScope:{protocol:'ocpp2.0.1',connectorId:1,evseId:1}},
  {durationSeconds:999},{startedAt:'2026-02-30T00:00:00.000Z'},{readOnly:false}])
  assert.equal(validOcppHistoryRow({...row(),...patch}),false,JSON.stringify(patch));
});
test('redacted rows cannot sneak source identifiers or numeric values into presentation',()=>{
 const f=fixture();f.charger=null;const redacted=presentOcppHistory(f);
 for(const patch of [{energyKwh:0},{totalFils:0},{transactionId:'hidden-source'},
  {sourceSessionId:sourceId},{reviewReason:null},{protocol:'ocpp1.6'}])
  assert.equal(validOcppHistoryRow({...redacted,...patch}),false);
});
test('page receipts require bounded coherent counts, ordered distinct rows and progressing cursor',()=>{
 const response=page([row()]);assert.equal(validOcppHistoryPage(response),true);
 for(const patch of [{scanned:0},{scanned:101},{unavailableCount:1},{hasMore:'false'},
  {hasMore:true,nextCursor:sid},{nextCursor:sid},{entries:[row(),row()]}])
  assert.equal(validOcppHistoryPage({...response,data:{...response.data,...patch}}),false);
 const records=Array.from({length:25},(_,i)=>({...row(),sessionId:(0x1000+i).toString(16).padStart(24,'0')})).reverse();
 const paged=page(records);Object.assign(paged.data,{hasMore:true,nextCursor:records.at(-1).sessionId});
 assert.equal(validOcppHistoryPage(paged,{cursor:sid}),true);
 assert.equal(validOcppHistoryPage(paged,{cursor:paged.data.nextCursor}),false);
 records.reverse();assert.equal(validOcppHistoryPage(paged),false);
});
