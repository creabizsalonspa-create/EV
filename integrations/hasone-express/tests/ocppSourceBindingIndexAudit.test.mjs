import test from 'node:test';
import assert from 'node:assert/strict';
import {
  auditOcppSourceBindingIndexes,ensureStagingOcppSourceBindingIndexes
} from '../ocppSourceBindingIndexAudit.mjs';
const doc=(id,booking,user='USER')=>({
  _id:id,bookingId:booking,userId:user,sourceSessionId:'S-'+id,sourceType:'ocpp'
});
class Fixture{
 constructor(rows=[],dbName='agent2_demo_ephemeral'){
  this.dbName=dbName;
  this.rows=rows.map(v=>({...v}));this.writes=0;
  this.idx=[{name:'_id_',key:{_id:1},unique:true}];
 }
 active(){return this.rows.filter(x=>x.sourceType==='ocpp' &&
   typeof x.sourceSessionId==='string' && x.sourceSessionId>'');}
 async countDocuments(){return this.active().length;}
 aggregate(pipeline){
  const key=pipeline[1].$group._id;
  const groups=new Map();
  for(const row of this.active()){
   const label=typeof key==='string'?row.sourceSessionId:JSON.stringify({
     bookingId:row.bookingId,userId:row.userId});
   groups.set(label,(groups.get(label)||0)+1);
  }
  return {toArray:async()=>[...groups].filter(([,n])=>n>1)
    .slice(0,1).map(([id,count])=>({_id:id,count}))};
 }
 async indexes(){return this.idx.map(x=>({...x,key:{...x.key}}));}
 async createIndex(key,options){
  this.writes++;const name=options.name;
  if(this.idx.some(x=>x.name===name))return name;
  const saved={name,key:{...key},unique:!!options.unique,...(options.partialFilterExpression?{partialFilterExpression:options.partialFilterExpression}:{})};
  this.idx.push(saved);return name;
 }
}
test('empty collection preflight is safe and read only',async()=>{
 const c=new Fixture();
 const p=await auditOcppSourceBindingIndexes(c);
 assert.equal(p.ready,true);assert.equal(p.sourceBoundRecordCount,0);
 assert.equal((await c.indexes()).length,1);
});
test('unrelated bookings can have different source sessions',async()=>{
 const c=new Fixture([doc('1','BOOK1'),doc('2','BOOK2')]);
 const p=await auditOcppSourceBindingIndexes(c);
 assert.equal(p.ready,true);assert.equal(p.sourceBoundRecordCount,2);
});
test('same source session in two bookings is detected',async()=>{
 const a=doc('1','BOOK1'),b=doc('2','BOOK2');b.sourceSessionId=a.sourceSessionId;
 const p=await auditOcppSourceBindingIndexes(new Fixture([a,b]));
 assert.equal(p.ready,false);
 assert.ok(p.reasons.includes('duplicate_ocpp_source_session'));
});
test('one booking linked to two source transactions blocks index creation',async()=>{
 const c=new Fixture([doc('1','BOOK1'),doc('2','BOOK1')]);
 const p=await auditOcppSourceBindingIndexes(c);
 assert.equal(p.ready,false);assert.ok(p.reasons.includes('duplicate_booking_source_binding'));
 await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(c,{
  approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'}),/duplicates/);
 assert.equal((await c.indexes()).length,1);
});
test('legacy unbound charging sessions do not count as verified source conflicts',async()=>{
 const c=new Fixture([
  doc('1','BOOK1'),{sourceType:'unverified',sourceSessionId:'S-2',
    bookingId:'BOOK1',userId:'USER'}
 ]);
 assert.equal((await auditOcppSourceBindingIndexes(c)).ready,true);
});
test('database migration cannot execute without exact disposable approval',async()=>{
 const c=new Fixture([doc('1','BOOK1')]);
 await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(c),/approval/);
 assert.equal((await c.indexes()).length,1);
});
test('database migration refuses production collection even with approval',async()=>{
 const c=new Fixture([doc('1','BOOK1')],'real_ev_production');
 await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(c,{
  approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'}),/Refusing non-staging/);
 assert.equal((await c.indexes()).length,1);
});
test('disposable staging creates both unique source binding indexes',async()=>{
 const c=new Fixture([doc('1','BOOK1'),doc('2','BOOK2')]);
 const p=await ensureStagingOcppSourceBindingIndexes(c,{
  approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'});
 assert.equal(p.ready,true);
 assert.equal(p.hasUniqueSourceIndex,true);assert.equal(p.hasUniqueBookingIndex,true);
 assert.deepEqual(p.requiredIndexNames,[
   'uniq_linked_ocpp_source_session','uniq_source_bound_ocpp_booking']);
});
test('index creation is idempotent on verified staging data',async()=>{
 const c=new Fixture([doc('1','BOOK1')]);
 await ensureStagingOcppSourceBindingIndexes(c,{
   approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'});
 await ensureStagingOcppSourceBindingIndexes(c,{
   approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'});
 assert.equal((await c.indexes()).length,3);
});

test('narrow partial filters never masquerade as source/booking uniqueness protection',async()=>{
 const c=new Fixture();
 c.idx.push({name:'uniq_linked_ocpp_source_session',key:{sourceSessionId:1},unique:true,
  partialFilterExpression:{sourceType:'unverified',sourceSessionId:{$gt:''}}});
 c.idx.push({name:'uniq_source_bound_ocpp_booking',key:{bookingId:1,userId:1},unique:true,
  partialFilterExpression:{sourceType:'ocpp',sourceSessionId:{$gt:'z'}}});
 const result=await auditOcppSourceBindingIndexes(c);
 assert.equal(result.ready,false);assert.equal(result.hasUniqueSourceIndex,false);
 assert.equal(result.hasUniqueBookingIndex,false);assert.equal(result.bindingUniquenessEnforced,false);
 assert.equal(result.indexReviewRequired,true);assert.equal(c.writes,0);
 await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(c,{approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'}),/index review/);
 assert.equal(c.writes,0);assert.equal(c.idx.length,3);
});
test('unfiltered unique indexes protect all bound rows without duplicate index creation',async()=>{
 const c=new Fixture();c.idx.push({name:'global_source',key:{sourceSessionId:1},unique:true},
  {name:'global_booking',key:{bookingId:1,userId:1},unique:true});
 const report=await ensureStagingOcppSourceBindingIndexes(c,{approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'});
 assert.equal(report.bindingUniquenessEnforced,true);assert.equal(c.writes,0);
});
test('canonical filter property order is irrelevant while extra conditions require review',async()=>{
 const c=new Fixture();c.idx.push({name:'source',key:{sourceSessionId:1},unique:true,
  partialFilterExpression:{sourceSessionId:{$gt:''}}},{name:'booking',key:{userId:1,bookingId:1},unique:true,
  partialFilterExpression:{sourceSessionId:{$gt:''},sourceType:'ocpp'}});
 assert.equal((await auditOcppSourceBindingIndexes(c)).bindingUniquenessEnforced,true);
 c.idx[2].partialFilterExpression.paymentStatus='paid';
 const report=await auditOcppSourceBindingIndexes(c);
 assert.equal(report.hasUniqueBookingIndex,false);assert.equal(report.indexReviewRequired,true);
});
test('wrong-key or non-unique canonical index names block writes before either create',async()=>{
 for(const index of [{name:'uniq_source_bound_ocpp_booking',key:{bookingId:1},unique:true},
  {name:'uniq_source_bound_ocpp_booking',key:{bookingId:1,userId:1},unique:false},
  {name:'uniq_linked_ocpp_source_session',key:{sourceSessionId:1},unique:1}]){
  const c=new Fixture();c.idx.push(index);
  await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(c,{approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'}),/index review/);
  assert.equal(c.writes,0);
 }
});
test('successful create acknowledgements alone cannot establish index enforcement',async()=>{
 const c=new Fixture();c.createIndex=async()=>{c.writes++;return 'acknowledged-only';};
 await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(c,{approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'}),/enforcement could not be verified/);
});
