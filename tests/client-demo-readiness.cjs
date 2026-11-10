'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const demo=require('../scripts/client-demo-readiness.cjs');

const OCPP=['1.6','2.0.1'],ROLES=['customer','vendor','admin'];
function synthetics(){
 return {
   simulators:()=>({ok:true,physical_charging_verified:false,real_money_moved:false,
     stations:OCPP.map(protocol=>({protocol,idle:true,physical_charging_verified:false,
       evidence:'SIMULATED_CITRINEOS'}))}),
   roles:async()=>({ok:true,discovery_verified:true,physical_charging_verified:false,real_money_moved:false,
     production_accepted:false,
     results:ROLES.map(role=>({role,authenticated:true,read_only_features_verified:true,admin_authorized:role==='admin',post_logout_denied:true}))}),
   now:()=> '2026-11-07T13:00:00.000Z',
   fingerprint:()=> 'f'.repeat(64),
 };
}
function temp(fn){
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-lab-report-'));
 fs.chmodSync(dir,0o700);
 try{return fn(path.join(dir,'proof.json'));}
 finally{fs.rmSync(dir,{recursive:true,force:true});}
}
const params=file=>['--manifest','/private/client-demo/a1b2c3d4/access.json','--output',file];
test('approved synthetic lab roles and two idle protocols yield limited readiness',async()=>{
 const result=await demo.inspect({manifest:'private'},synthetics());
 assert.equal(result.schema,demo.SCHEMA);
 assert.equal(result.status,'PRIVATE_LAB_DEMO_READY');
 assert.deepEqual(result.simulated_protocols,OCPP);
 assert.deepEqual(result.browser_roles,ROLES);
 for(const flag of ['physical_charging_verified','real_money_moved','live_ocpi_verified','production_accepted'])
   assert.equal(result[flag],false);
});
test('role check cannot be called if initial simulator observation fails',async()=>{
 const deps=synthetics();let roles=0;deps.simulators=()=>({ok:false});
 deps.roles=async()=>{roles++;return {}};
 const proof=await demo.inspect({manifest:'private'},deps);
 assert.equal(proof.status,'INCOMPLETE');assert.equal(roles,0);
});
test('after browser checks, newly active simulator fails demonstration readiness',async()=>{
 const deps=synthetics();let seen=0;
 deps.simulators=()=>({ok:true,physical_charging_verified:false,real_money_moved:false,
   stations:OCPP.map(protocol=>({protocol,idle:++seen<=2,
     physical_charging_verified:false,evidence:'SIMULATED_CITRINEOS'}))});
 const proof=await demo.inspect({manifest:'private'},deps);
 assert.equal(proof.status,'INCOMPLETE');
 assert.deepEqual(proof.simulated_protocols,[]);
});
test('wrong customer account mapping or elevated vendor role fails closed',async()=>{
 const deps=synthetics();
 deps.roles=async()=>({...await synthetics().roles(),results:ROLES.map(role=>({
   role,authenticated:true,read_only_features_verified:true,admin_authorized:role==='vendor',post_logout_denied:true
 }))});
 const proof=await demo.inspect({manifest:'private'},deps);
 assert.equal(proof.status,'INCOMPLETE');
});
test('credentials and role-smoke error details never appear in readiness evidence',async()=>{
 const secret='DO_NOT_PRINT_REAL_ACCOUNT';
 const deps=synthetics();deps.roles=async()=>{throw Error(secret);};
 const proof=await demo.inspect({manifest:'private'},deps);
 assert.equal(proof.status,'INCOMPLETE');
 assert.equal(JSON.stringify(proof).includes(secret),false);
});
test('realized private lab evidence cannot be overwritten',async()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-lab-report-'));
 fs.chmodSync(dir,0o700);
 try{
   const file=path.join(dir,'status.json');
   assert.equal(await demo.main(params(file),synthetics()),0);
   const data=JSON.parse(fs.readFileSync(file,'utf8'));
   assert.equal(data.status,'PRIVATE_LAB_DEMO_READY');
   assert.equal(data.production_accepted,false);
   assert.equal(fs.statSync(file).mode&0o077,0);
   assert.equal(await demo.main(params(file),synthetics()),2);
 }finally{fs.rmSync(dir,{recursive:true,force:true});}
});
test('failed demo test produces only sanitized incomplete evidence',async()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-lab-report-'));
 fs.chmodSync(dir,0o700);
 try{
   const file=path.join(dir,'status.json'),deps=synthetics();
   deps.simulators=()=>{throw Error('SECRET_PRIVATE_STATION_TOKEN');};
   assert.equal(await demo.main(params(file),deps),1);
   const text=fs.readFileSync(file,'utf8');
   assert.ok(text.includes('INCOMPLETE'));
   assert.equal(text.includes('SECRET_PRIVATE_STATION_TOKEN'),false);
 }finally{fs.rmSync(dir,{recursive:true,force:true});}
});
test('unrecognized CLI inputs cannot target remote servers or files',()=>{
 assert.throws(()=>demo.parseArgs(['--url','https://production.example.org']));
 assert.throws(()=>demo.parseArgs(['--manifest','foo','--output','x','--execute']));
});

test('post-browser station reporting real hardware fails private synthetic readiness',async()=>{
 const deps=synthetics();let calls=0;
 deps.simulators=()=>{
   calls++;
   const good=synthetics().simulators();
   if(calls===2)good.physical_charging_verified=true;
   return good;
 };
 const proof=await demo.inspect({manifest:'private'},deps);
 assert.equal(proof.status,'INCOMPLETE');
 assert.equal(proof.production_accepted,false);
});
test('incorrect simulator provenance marker and second-stage monetary claim cannot pass',async()=>{
 const deps=synthetics();
 deps.simulators=()=>({...synthetics().simulators(),stations:[
   {protocol:'1.6',idle:true,physical_charging_verified:false,evidence:'PHYSICAL_CHARGER'},
   {protocol:'2.0.1',idle:true,physical_charging_verified:false,evidence:'SIMULATED_CITRINEOS'}]});
 assert.equal((await demo.inspect({manifest:'private'},deps)).status,'INCOMPLETE');
 let seen=0;
 deps.simulators=()=>{
   seen++;
   const v=synthetics().simulators();
   if(seen===2)v.real_money_moved=true;
   return v;
 };
 assert.equal((await demo.inspect({manifest:'private'},deps)).status,'INCOMPLETE');
});

test('aggregated report rejects missing EV vehicle/reservation journey readiness',async()=>{
 const deps=synthetics();
 const good=await synthetics().roles();
 good.results[1].read_only_features_verified=false;
 deps.roles=async()=>good;
 assert.equal((await demo.inspect({manifest:'private'},deps)).status,'INCOMPLETE');
});

test('combined lab report refuses browser roles whose public synthetic stations are missing',async()=>{
 const deps=synthetics();
 const result=await synthetics().roles();
 result.discovery_verified=false;
 deps.roles=async()=>result;
 assert.equal((await demo.inspect({manifest:'private'},deps)).status,'INCOMPLETE');
});

test('private demo report binds its reviewed synthetic fixture fingerprint without exposing passwords',async()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-fingerprint-report-'));
 fs.chmodSync(dir,0o700);
 try {
   const file=path.join(dir,'proof.json');
   const result=await demo.main(params(file),synthetics());
   assert.equal(result,0);
   const saved=JSON.parse(fs.readFileSync(file,'utf8'));
   assert.equal(saved.fixture_sha256,'f'.repeat(64));
   assert.equal(JSON.stringify(saved).includes('aA9!'),false);
 } finally {fs.rmSync(dir,{recursive:true,force:true});}
});
