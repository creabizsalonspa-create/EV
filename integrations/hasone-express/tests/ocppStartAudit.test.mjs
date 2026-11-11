import test from 'node:test';
import assert from 'node:assert/strict';
import {auditConsumedStarts} from '../ocppStartAudit.mjs';
const now=new Date('2026-11-07T12:00:00Z');
const h1='a'.repeat(64),h2='b'.repeat(64),h3='c'.repeat(64);
const oid=n=>{
 const text=typeof n==='string'?n:n.toString(16).padStart(24,'0');
 return {toHexString:()=>text,toString:()=>text};
};
function fakeDb(tokens,sessions){
 tokens=tokens.map((t,i)=>({_id:oid(i+1),...t}));
 return {collection(name){
   if(name==='ocpp_access_tokens')return{
     find(query){
       const selected=tokens.filter(t=>query.chargerId.$in.includes(t.chargerId)
         && Object.hasOwn(t,'consumedAt') && t.enabled===false
         && (!query._id || String(t._id)<String(query._id.$lt)));
       selected.sort((a,b)=>String(b._id).localeCompare(String(a._id)));
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

test('seek pagination visits equal-timestamp records once across page boundaries',async()=>{
 const tokens=Array.from({length:5},(_,i)=>({
   _id:oid(i+1),chargerId:'A',tokenHash:h1,bookingId:'case-'+(i+1),
   consumedAt:new Date(now-300000),enabled:false
 }));
 const db=fakeDb(tokens,[]);
 const seen=[];let beforeId=null,total=0;
 do{
   const report=await auditConsumedStarts(db,{chargerIds:['A'],limit:2,now,beforeId});
   assert.equal(report.readOnly,true);
   assert.ok(report.scanned<=2);
   total+=report.scanned;
   seen.push(...report.exceptions.map(x=>x.bookingId));
   assert.equal(JSON.stringify(report).includes(h1),false);
   if(!report.hasMore){assert.equal(report.nextCursor,null);break;}
   assert.match(report.nextCursor,/^[a-f0-9]{24}$/);
   beforeId=oid(report.nextCursor);
 }while(true);
 assert.equal(total,5);assert.equal(new Set(seen).size,5);
 assert.deepEqual(seen,['case-5','case-4','case-3','case-2','case-1']);
});
test('new records between audit pages do not shift the remaining results',async()=>{
 const tokens=[1,2,3].map(i=>({_id:oid(i),chargerId:'A',tokenHash:h1,
   bookingId:'case-'+i,consumedAt:new Date(now-300000),enabled:false}));
 const first=await auditConsumedStarts(fakeDb(tokens,[]),{chargerIds:['A'],limit:1,now});
 tokens.push({_id:oid(4),chargerId:'A',tokenHash:h2,bookingId:'new-case',
   consumedAt:new Date(now-300000),enabled:false});
 const rest=await auditConsumedStarts(fakeDb(tokens,[]),{
   chargerIds:['A'],limit:3,now,beforeId:oid(first.nextCursor)});
 assert.deepEqual(rest.exceptions.map(x=>x.bookingId),['case-2','case-1']);
 assert.equal(rest.hasMore,false);
});
test('a full page with no exception still supplies continuation to older incidents',async()=>{
 const tokens=[1,2].map(i=>({_id:oid(i),chargerId:'A',
   tokenHash:i===2?h2:h1,consumedAt:new Date(now-300000),enabled:false}));
 const db=fakeDb(tokens,[{chargerId:'A',idTagHash:h2}]);
 const first=await auditConsumedStarts(db,{chargerIds:['A'],limit:1,now});
 assert.equal(first.orphanCount,0);assert.equal(first.hasMore,true);
 const last=await auditConsumedStarts(db,{chargerIds:['A'],limit:1,now,
   beforeId:oid(first.nextCursor)});
 assert.equal(last.orphanCount,1);assert.equal(last.hasMore,false);
});
test('audit cursors cannot be raw strings, query objects or malformed IDs',async()=>{
 for(const beforeId of ['abc',{$lt:'abc'},{toHexString:()=>'$bad'}]){
   await assert.rejects(auditConsumedStarts(fakeDb([],[]),{
     chargerIds:['A'],now,beforeId}));
 }
});
