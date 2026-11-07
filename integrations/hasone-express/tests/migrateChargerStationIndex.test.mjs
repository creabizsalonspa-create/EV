import test from 'node:test';
import assert from 'node:assert/strict';
import {
  planChargerStationIndexMigration,migrateChargerStationIndex,
  planChargerStationRollback,rollbackChargerStationIndex
} from '../migrateChargerStationIndex.mjs';

class FakeCollection {
 constructor(rows=[],options={}){
  this.dbName=options.dbName??'agent2_demo_ephemeral';
  this.rows=rows.map(x=>({...x}));
  this.failLookup=!!options.failLookup;
  this.idx=[
   {name:'_id_',key:{_id:1},unique:true},
   {name:'stationId_1',key:{stationId:1},unique:true,
     partialFilterExpression:{stationId:{$type:'string'}}},
   {name:'chargerId_1',key:{chargerId:1},unique:true}
  ];
  if(options.noChargerIndex)this.idx=this.idx.filter(x=>x.name!=='chargerId_1');
 }
 async indexes(){return this.idx.map(x=>({...x,key:{...x.key}}));}
 async countDocuments(query){
  if(!Object.keys(query||{}).length)return this.rows.length;
  if(!query.$or)throw Error('Fake only supports read-only missing-field checks');
  return this.rows.filter(row=>query.$or.some(filter=>{
   const field=Object.keys(filter)[0],op=filter[field];
   if(op && typeof op==='object' && op.$exists===false)return !(field in row);
   return row[field]===op;
  })).length;
 }
 aggregate(pipeline){
  const field=pipeline.find(x=>x.$group)?.$group?._id?.slice(1);
  if(!['stationId','chargerId'].includes(field))throw Error('Unexpected aggregation');
  const counts=new Map();
  for(const row of this.rows){
   if(typeof row[field]!=='string')continue;
   counts.set(row[field],(counts.get(row[field])||0)+1);
  }
  return {toArray:async()=>[...counts.entries()].filter(([,n])=>n>1)
    .slice(0,1).map(([id,n])=>({_id:id,n}))};
 }
 async createIndex(key,opts={}){
  const field=Object.keys(key)[0];
  if(opts.name==='station_lookup_multi' && this.failLookup)throw Error('Simulated disk failure');
  if(opts.unique && this.rows.some((r,i)=>
       this.rows.some((s,j)=>i!==j && r[field]!==undefined && r[field]===s[field])))
    throw Error('Duplicate key');
  const name=opts.name||field+'_1';
  const old=this.idx.find(x=>x.name===name);
  if(old)return name;
  this.idx.push({name,key:{...key},unique:!!opts.unique,
    ...(opts.partialFilterExpression?{partialFilterExpression:opts.partialFilterExpression}:{})});
  return name;
 }
 async dropIndex(name){
  const old=this.idx.find(x=>x.name===name);
  if(!old)throw Error('Index missing: '+name);
  this.idx=this.idx.filter(x=>x.name!==name);
 }
}
const initial=()=>new FakeCollection([
 {stationId:'station-a',chargerId:'charger-a'},
 {stationId:'station-b',chargerId:'charger-b'}
]);
test('preflight is read-only and identifies legacy unique station index',async()=>{
 const c=initial();
 const before=JSON.stringify(await c.indexes());
 const p=await planChargerStationIndexMigration(c);
 assert.equal(p.blocked,false);assert.equal(p.hasLegacyUniqueStationIndex,true);
 assert.equal(p.hasGlobalUniqueChargerIndex,true);
 assert.equal(JSON.stringify(await c.indexes()),before);
});
test('migration without exact staging authorization makes no changes',async()=>{
 const c=initial(),before=JSON.stringify(await c.indexes());
 await assert.rejects(()=>migrateChargerStationIndex(c),/approval/);
 assert.equal(JSON.stringify(await c.indexes()),before);
});
test('migration refuses production database even with approval',async()=>{
 const c=new FakeCollection([{stationId:'s',chargerId:'c'}],{dbName:'production'});
 await assert.rejects(()=>migrateChargerStationIndex(c,{
   approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'}),/Refusing index mutation/);
 assert.equal((await c.indexes()).some(x=>x.unique && x.key.stationId===1),true);
});
test('migration replaces unique station index but preserves unique charger ID',async()=>{
 const c=initial();
 const out=await migrateChargerStationIndex(c,{approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'});
 assert.equal(out.removedUniqueStationIndex,'stationId_1');
 assert.ok((await c.indexes()).some(x=>x.key.stationId===1&&!x.unique));
 assert.ok((await c.indexes()).some(x=>x.key.chargerId===1&&x.unique));
});
test('migration is repeatable and a second execution does not drop another index',async()=>{
 const c=initial();
 await migrateChargerStationIndex(c,{approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'});
 const repeat=await migrateChargerStationIndex(c,{approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'});
 assert.equal(repeat.removedUniqueStationIndex,null);
 assert.equal((await c.indexes()).filter(x=>x.key.stationId===1).length,1);
});
test('rollback restores the legacy unique station index while no station duplicates exist',async()=>{
 const c=initial();
 await migrateChargerStationIndex(c,{approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'});
 const ready=await planChargerStationRollback(c);
 assert.equal(ready.blocked,false);
 await assert.rejects(()=>rollbackChargerStationIndex(c),/approval/);
 const out=await rollbackChargerStationIndex(c,{approval:'ROLLBACK_DISPOSABLE_AGENT2_DB'});
 assert.equal(out.restoredUniqueStationIndex,true);
 assert.ok((await c.indexes()).some(x=>x.key.stationId===1&&x.unique));
});
test('rollback refuses restoration once two chargers share a station',async()=>{
 const c=initial();
 await migrateChargerStationIndex(c,{approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'});
 c.rows.push({stationId:'station-a',chargerId:'charger-c'});
 const plan=await planChargerStationRollback(c);
 assert.equal(plan.blocked,true);assert.ok(plan.reasons.includes('multiple_chargers_share_station'));
 await assert.rejects(()=>rollbackChargerStationIndex(c,{
   approval:'ROLLBACK_DISPOSABLE_AGENT2_DB'}),/multiple_chargers_share_station/);
 assert.ok((await c.indexes()).some(x=>x.key.stationId===1&&!x.unique));
});
test('missing charger ID blocks migration and cannot be silently ignored',async()=>{
 const c=initial();c.rows.push({stationId:'station-c'});
 const plan=await planChargerStationIndexMigration(c);
 assert.equal(plan.blocked,true);
 assert.ok(plan.reasons.includes('missing_charger_id'));
 await assert.rejects(()=>migrateChargerStationIndex(c,{
   approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'}),/missing_charger_id/);
});
test('duplicate charger ID blocks migration even if a unique index is misconfigured',async()=>{
 const c=initial();c.rows.push({stationId:'station-c',chargerId:'charger-a'});
 const plan=await planChargerStationIndexMigration(c);
 assert.equal(plan.blocked,true);
 assert.ok(plan.reasons.includes('duplicate_charger_ids'));
});
test('missing globally unique charger index blocks preflight on nonempty collection',async()=>{
 const c=new FakeCollection([{stationId:'station-a',chargerId:'charger-a'}],{
   noChargerIndex:true});
 const p=await planChargerStationIndexMigration(c);
 assert.equal(p.blocked,true);
 assert.ok(p.reasons.includes('global_charger_id_index_not_enforced'));
});
test('failed post-drop lookup creation attempts to restore original unique index',async()=>{
 const c=new FakeCollection([{stationId:'station-a',chargerId:'charger-a'}],{
   failLookup:true});
 await assert.rejects(()=>migrateChargerStationIndex(c,{
   approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'}),/Simulated disk failure/);
 const indexes=await c.indexes();
 assert.ok(indexes.some(x=>x.name==='stationId_1'&&x.unique));
});
