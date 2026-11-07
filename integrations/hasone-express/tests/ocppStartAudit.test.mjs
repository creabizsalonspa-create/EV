import test from 'node:test';
import assert from 'node:assert/strict';
import {auditConsumedStarts} from '../ocppStartAudit.mjs';
const now=new Date('2026-11-07T12:00:00Z');
const h1='a'.repeat(64),h2='b'.repeat(64),h3='c'.repeat(64);
function fakeDb(tokens,sessions){
 return {collection(name){
   if(name==='ocpp_access_tokens')return{
     find(query){
       const selected=tokens.filter(t=>query.chargerId.$in.includes(t.chargerId)
         && Object.hasOwn(t,'consumedAt') && t.enabled===false);
       return {sort(){return this},limit(n){return {toArray:async()=>selected.slice(0,n)}}};
     }
   };
   if(name==='ocpp_sessions')return{
     findOne:async query=>sessions.find(s=>s.chargerId===query.chargerId
       && (s.idTagHash===query.$or[0].idTagHash
         || s.idTokenHash===query.$or[1].idTokenHash))||null
   };
   throw new Error('unexpected collection');
 }};
}
test('consumed credential with matching source is never flagged',async()=>{
 const db=fakeDb([{chargerId:'A',tokenHash:h1,consumedAt:new Date(now-300000),enabled:false}],
   [{chargerId:'A',idTagHash:h1,transactionId:3}]);
 const r=await auditConsumedStarts(db,{chargerIds:['A'],now});
 assert.equal(r.orphanCount,0);assert.equal(r.matchedSourceCount,1);
 assert.equal(r.readOnly,true);
});
test('expired grace period with no source is a redacted operator alert',async()=>{
 const db=fakeDb([{chargerId:'A',tokenHash:h2,bookingId:'booking1',
   consumedAt:new Date(now-180000),enabled:false}],[]);
 const r=await auditConsumedStarts(db,{chargerIds:['A'],now});
 assert.equal(r.orphanCount,1);
 assert.equal(r.exceptions[0].bookingId,'booking1');
 assert.equal(r.exceptions[0].credentialRestored,false);
 assert.equal(JSON.stringify(r).includes(h2),false);
 assert.equal(r.exceptions[0].issue,'consumed_without_source_transaction');
});
test('recent token consumption waits through retry grace period',async()=>{
 const db=fakeDb([{chargerId:'A',tokenHash:h3,consumedAt:new Date(now-10000),
   enabled:false}],[]);
 const r=await auditConsumedStarts(db,{chargerIds:['A'],now});
 assert.equal(r.orphanCount,0);
 assert.equal(r.withinGracePeriodCount,1);
});
test('same hash on another charger never validates local transaction',async()=>{
 const db=fakeDb([{chargerId:'A',tokenHash:h1,consumedAt:new Date(now-180000),
   enabled:false}],[{chargerId:'B',idTagHash:h1}]);
 const r=await auditConsumedStarts(db,{chargerIds:['A'],now});
 assert.equal(r.orphanCount,1);
});
test('source for OCPP2.0.1 is recognized by idTokenHash',async()=>{
 const db=fakeDb([{chargerId:'B',tokenHash:h1,consumedAt:new Date(now-180000),
   enabled:false}],[{chargerId:'B',idTokenHash:h1}]);
 const r=await auditConsumedStarts(db,{chargerIds:['B'],now});
 assert.equal(r.orphanCount,0);assert.equal(r.matchedSourceCount,1);
});
test('rejects unscoped, invalid or overly broad audit requests',async()=>{
 const db=fakeDb([],[]);
 for(const v of [[],['BAD ID'],Array.from({length:129},(_,i)=>'C'+i)]){
   await assert.rejects(auditConsumedStarts(db,{chargerIds:v,now}));
 }
 await assert.rejects(auditConsumedStarts(db,{chargerIds:['A'],limit:101,now}));
 await assert.rejects(auditConsumedStarts(db,{chargerIds:['A'],minAgeMs:1,now}));
});
