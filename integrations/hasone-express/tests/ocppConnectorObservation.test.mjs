import test from 'node:test';
import assert from 'node:assert/strict';
import {presentConnectorObservation} from '../ocppConnectorObservation.mjs';
const now=new Date('2026-11-08T01:00:00Z');
const source=(protocol='ocpp1.6')=>({chargerId:'charger',protocol,status:'active',connectorId:2,
  evseId:protocol==='ocpp1.6'?null:3,startedAt:new Date(now-600000),startedReceivedAt:new Date(now-600000)});
const row=(protocol='ocpp1.6')=>({protocol,connectorId:2,evseId:protocol==='ocpp1.6'?null:3,
  status:'Faulted',receivedAt:new Date(now-30000)});
const charger=(protocol='ocpp1.6')=>({chargerId:'charger',enabled:true,
  connectors:{[protocol==='ocpp1.6'?'0_2':'3_2']:row(protocol)}});
test('active source qualifies the exact protocol/EVSE/connector report without changing session or cost',()=>{
 for(const protocol of ['ocpp1.6','ocpp2.0.1']){
  const s=source(protocol),c=charger(protocol),before=JSON.stringify({s,c});
  const report=presentConnectorObservation(s,c,{now});
  assert.equal(report.status,'Faulted');assert.equal(report.receiptAgeSeconds,30);
  assert.equal(report.source,'gateway_connector_report');assert.equal(report.freshness,'recent');
  assert.equal(report.evseId,protocol==='ocpp1.6'?null:3);assert.equal(report.connectorId,2);
  assert.equal(JSON.stringify({s,c}),before);assert.equal('availability' in report,false);
 }
});
test('legacy timestamps, earlier sessions, future clocks, wrong protocol and wrong physical scope cannot qualify',()=>{
 for(const change of [{receivedAt:null},{receivedAt:now.toISOString()},
   {receivedAt:new Date(now-600001)},{receivedAt:new Date(now.valueOf()+1)},
   {protocol:'ocpp2.0.1'},{connectorId:1},{evseId:3},{status:'<script>'}]){
  const c=charger();c.connectors['0_2']={...row(),updatedAt:now,...change};
  assert.equal(presentConnectorObservation(source(),c,{now}),null);
 }
 const c=charger('ocpp2.0.1');c.connectors={'0_0':row('ocpp2.0.1'),'2_2':row('ocpp2.0.1')};
 assert.equal(presentConnectorObservation(source('ocpp2.0.1'),c,{now}),null);
 for(const s of [{...source(),status:'ended'},{...source(),connectorId:0},{...source(),startedReceivedAt:'2026-11-08T00:00:00Z'},{...source(),startedReceivedAt:null}])
  assert.equal(presentConnectorObservation(s,charger(),{now}),null);
 assert.equal(presentConnectorObservation(source(),{...charger(),enabled:false},{now}),null);
 assert.equal(presentConnectorObservation({...source(),startedAt:new Date(now.valueOf()+86400000)},charger(),{now}).status,'Faulted');
});
test('receipt freshness changes after120 seconds and heartbeat/device time cannot refresh the report',()=>{
 const c=charger();c.lastSeenAt=now;c.connectors['0_2'].updatedAt=now;
 c.connectors['0_2'].receivedAt=new Date(now-120000);
 assert.equal(presentConnectorObservation(source(),c,{now}).freshness,'recent');
 c.connectors['0_2'].receivedAt=new Date(now-121000);
 const report=presentConnectorObservation(source(),c,{now});
 assert.equal(report.freshness,'stale');assert.equal(report.receiptAgeSeconds,121);
});
