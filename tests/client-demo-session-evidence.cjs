'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const audit=require('../scripts/client-demo-session-evidence.cjs');

const RUN='a1b2c3d4';
const ACCOUNT=7;
const STATIONS=[11,12];
const ROLES=['customer','vendor','admin'];
function fixture(){
 const accounts={};
 for(const role of ROLES){
   accounts[role]={email:'client-demo-'+role+'-'+RUN+'@example.test',password:'a'.repeat(48)+'aA9!'};
 }
 return {fixture_only:true,run:RUN,base:'http://127.0.0.1:8000',company_id:1,
   accounts,stations:[['1.6','16'],['2.0.1','201']].map(([protocol,suffix],i)=>({
     protocol,identity:'ev-test-demo-'+RUN+'-'+suffix,
     station_id:STATIONS[i],charger_id:i+21,binding_id:i+31,connector_mapping_id:i+41,reservation_id:null})),
   qa_current_bookings:false,physical_charging_verified:false,real_money:false};
}
function reservations() {return STATIONS.map((station_id,i)=>({id:i+101,station_id,user_id:ACCOUNT,status:'confirmed'}));}
function sessions(){return [0,1].map(i=>({id:i+201,reservation_id:i+101,status:'stopped',
  evidence:'SIMULATED_CSMS',csms_linked:true,payment_status:'not_processed',currency:'AED',
  energy_wh:250+i*75,total_minor:25+i*8}));}
function page(records){return{data:records,total:records.length};}
function invoices(){return sessions().map((item,i)=>({
  id:301+i,charging_session_id:item.id,user_id:ACCOUNT,evidence:'SIMULATED',
  currency:'AED',energy_wh:item.energy_wh,total_minor:item.total_minor
}));}
function fake({reservationsData=reservations(),sessionsData=sessions(),invoicesData=invoices(),
  paidMinor=0,ledgerBalanced=true,logoutStatus=204,afterStatus=401,
  wrongRole=false,track=[]}={}) {
 return async(route,method,payload,jar)=>{
   track.push({route,method});
   if(route==='/api/csrf')return{status:200,body:{token:'0123456789abcdef'}};
   if(route==='/api/login')return{status:200,body:{id:ACCOUNT,role:wrongRole?'vendor':'customer',
     email:fixture().accounts.customer.email}};
   if(route==='/api/reservations')return{status:200,body:page(reservationsData)};
   if(route==='/api/simulations')return{status:200,body:page(sessionsData)};
   if(route==='/api/finance/invoices')return{status:200,body:page(invoicesData)};
   if(/^\/api\/finance\/invoices\/[0-9]+$/.test(route)){
     const id=Number(route.split('/').at(-1));
     const record=invoicesData.find(x=>x.id===id);
     if(!record)return{status:404,body:{message:'Not found'}};
     return{status:200,body:{invoice:record,payment_provider:'SIMULATOR_ONLY',
       real_payment_processed:false,ledger_balanced:ledgerBalanced,
       paid_minor:paidMinor,net_collected_minor:paidMinor,refunded_minor:0,
       payments:paidMinor?[{id:1}]:[],refunds:[]}};
   }
   if(route==='/api/logout')return{status:logoutStatus,body:null};
   if(route==='/api/me')return{status:afterStatus,body:{}};
   throw Error('Disallowed local demo route');
 };
}
async function withManifest(fn){
 const root=fs.mkdtempSync(path.join(os.tmpdir(),'ev-session-proof-'));fs.chmodSync(root,0o700);
 const base=path.join(root,'client-demo'),dir=path.join(base,RUN);
 fs.mkdirSync(base,{mode:0o700});fs.mkdirSync(dir,{mode:0o700});
 const manifest=path.join(dir,'access.json');
 fs.writeFileSync(manifest,JSON.stringify(fixture()),{mode:0o600});
 try{return await fn({manifest,root,dir});}
 finally{fs.rmSync(root,{recursive:true,force:true});}
}
test('two independent completed simulator sessions prove nonfinancial post-demo source evidence',()=>{
 const proof=audit.validateCompleted(fixture(),ACCOUNT,reservations(),sessions());
 assert.equal(proof.length,2);
 assert.deepEqual(proof.map(x=>x.protocol),['1.6','2.0.1']);
 assert.ok(proof.every(x=>x.evidence==='SIMULATED_CSMS'&&x.no_real_payment));
});
test('a single station session cannot prove two OCPP versions',()=>{
 const one=sessions().slice(0,1);
 assert.throws(()=>audit.validateCompleted(fixture(),ACCOUNT,reservations(),one));
});
test('customer reservation account boundary must be exact',()=>{
 const rs=reservations();rs[1].user_id=999;
 assert.throws(()=>audit.validateCompleted(fixture(),ACCOUNT,rs,sessions()));
});
test('stopped status, source link and no real payment evidence mandatory',()=>{
 for(const change of [
   x=>x.status='charging',x=>x.evidence='LIVE_OCPI',x=>x.csms_linked=false,
   x=>x.payment_status='paid',x=>x.physical_charging_verified=true,
   x=>x.energy_wh=0,x=>x.energy_wh=2000000,x=>x.total_minor=null,x=>x.currency='USD',
 ]){
   const list=sessions();change(list[1]);
   assert.throws(()=>audit.validateCompleted(fixture(),ACCOUNT,reservations(),list));
 }
});
test('incomplete or invalid paginated local responses are rejected',()=>{
 assert.throws(()=>audit.page({data:[],total:'many'}));
 assert.throws(()=>audit.page({data:Array(26).fill({}),total:26}));
 assert.deepEqual(audit.page({data:[],total:0}),[]);
});
test('local synthetic customer proof only uses CSRF/login, read-only API and logout',async()=>withManifest(async({manifest})=>{
 const track=[];
 const report=await audit.inspect(manifest,fake({track}),()=> '2026-11-07T13:00:00.000Z');
 assert.equal(report.status,'LOCAL_SIMULATOR_COMPLETION_VERIFIED');
 assert.equal(report.completion_count,2);
 assert.equal(report.simulated_invoices_verified,true);
 assert.ok(report.protocols.every(x=>x.mock_invoice_verified===true));
 assert.equal(report.production_accepted,false);
 assert.equal(report.real_money_moved,false);
 assert.deepEqual(track.map(x=>x.route),[
   '/api/csrf','/api/login','/api/reservations','/api/simulations',
   '/api/finance/invoices','/api/finance/invoices/301','/api/finance/invoices/302',
   '/api/logout','/api/me']);
 assert.deepEqual(track.filter(x=>x.method==='POST').map(x=>x.route),['/api/login','/api/logout']);
}));
test('unconfirmed logout makes private result INCOMPLETE even when source sessions finished',async()=>withManifest(async({manifest})=>{
 const report=await audit.inspect(manifest,fake({afterStatus:200}));
 assert.equal(report.status,'INCOMPLETE');assert.equal(report.completion_count,0);
}));
test('wrong authenticated customer role is rejected and malformed successful login is logged out',async()=>withManifest(async({manifest})=>{
 const track=[];
 const report=await audit.inspect(manifest,fake({wrongRole:true,track}));
 assert.equal(report.status,'INCOMPLETE');
 assert.ok(track.some(x=>x.route==='/api/logout'&&x.method==='POST'));
 assert.ok(track.some(x=>x.route==='/api/me'&&x.method==='GET'));
}));
test('missing second simulated protocol session cannot be presented as complete',async()=>withManifest(async({manifest})=>{
 const report=await audit.inspect(manifest,fake({sessionsData:sessions().slice(0,1)}));
 assert.equal(report.status,'INCOMPLETE');
}));
test('empty successful pages never produce a false positive',async()=>withManifest(async({manifest})=>{
 const report=await audit.inspect(manifest,fake({sessionsData:[],reservationsData:[]}));
 assert.equal(report.status,'INCOMPLETE');
}));
test('failed private evidence writes do not contain login credentials or session detail',async()=>withManifest(async({manifest,root})=>{
 const target=path.join(root,'post-demo.json');
 const code=await audit.main(['--manifest',manifest,'--output',target],{send:fake({sessionsData:[]})});
 assert.equal(code,1);
 const body=fs.readFileSync(target,'utf8');
 assert.equal(JSON.parse(body).status,'INCOMPLETE');
 assert.equal(body.includes(fixture().accounts.customer.password),false);
 assert.equal(body.includes(fixture().accounts.customer.email),false);
}));
test('completed private evidence is owner-only and never overwrites an existing report',async()=>withManifest(async({manifest,root})=>{
 const target=path.join(root,'post-demo.json');
 const args=['--manifest',manifest,'--output',target];
 assert.equal(await audit.main(args,{send:fake()}),0);
 assert.equal((fs.statSync(target).mode&0o077),0);
 assert.match(JSON.parse(fs.readFileSync(target)).fixture_sha256,/^[a-f0-9]{64}$/);
 assert.equal(await audit.main(args,{send:fake()}),2);
 assert.equal(JSON.parse(fs.readFileSync(target)).completion_count,2);
}));
test('wrong origin or synthetic credentials are rejected before any session read',async()=>withManifest(async({manifest})=>{
 const x=fixture();x.base='https://customer-production.example.org';
 fs.writeFileSync(manifest,JSON.stringify(x));
 let calls=0;
 await assert.rejects(()=>audit.inspect(manifest,async()=>{calls++;throw Error('MUST NOT CONTACT');}));
 assert.equal(calls,0);
}));
test('CLI accepts only private manifest and new local output',()=>{
 assert.throws(()=>audit.parseArgs(['--url','https://prod.example.org']));
 assert.throws(()=>audit.parseArgs(['--manifest','--unsafe','--output','out.json']));
 assert.deepEqual(audit.parseArgs(['--manifest','a','--output','b']),{manifest:'a',output:'b'});
});

test('nonboolean physical charging claims cannot be laundered as synthetic evidence',()=>{
 for(const value of ['true',1,{},[]]){
   const list=sessions();list[1].physical_charging_verified=value;
   assert.throws(()=>audit.validateCompleted(fixture(),ACCOUNT,reservations(),list));
 }
});

test('mock invoices must cover both distinct local CSMS source sessions',()=>{
 const completed=audit.validateCompleted(fixture(),ACCOUNT,reservations(),sessions());
 const proof=audit.validateMockInvoices(completed,ACCOUNT,invoices());
 assert.equal(proof.length,2);
 assert.deepEqual(proof.map(x=>x.invoice_id),[301,302]);
});
test('missing or foreign-user simulator invoice is not accepted',()=>{
 const completed=audit.validateCompleted(fixture(),ACCOUNT,reservations(),sessions());
 assert.throws(()=>audit.validateMockInvoices(completed,ACCOUNT,invoices().slice(0,1)));
 const foreign=invoices();foreign[1].user_id=999;
 assert.throws(()=>audit.validateMockInvoices(completed,ACCOUNT,foreign));
});
test('paid mock invoice cannot masquerade as no-money-moved demo completion',async()=>withManifest(async({manifest})=>{
 const report=await audit.inspect(manifest,fake({paidMinor:1}));
 assert.equal(report.status,'INCOMPLETE');
 assert.equal(report.simulated_invoices_verified,false);
}));
test('unbalanced synthetic finance journal must fail read-only completion evidence',async()=>withManifest(async({manifest})=>{
 const report=await audit.inspect(manifest,fake({ledgerBalanced:false}));
 assert.equal(report.status,'INCOMPLETE');
}));
test('wrong source invoice currency or physical invoice evidence is rejected',()=>{
 const completed=audit.validateCompleted(fixture(),ACCOUNT,reservations(),sessions());
 const wrong=invoices();wrong[1].evidence='LIVE_PSP';
 assert.throws(()=>audit.validateMockInvoices(completed,ACCOUNT,wrong));
 const wrongCurrency=invoices();wrongCurrency[1].currency='USD';
 assert.throws(()=>audit.validateMockInvoices(completed,ACCOUNT,wrongCurrency));
});
test('complete post-demo evidence contains no session IDs or invoice IDs',async()=>withManifest(async({manifest})=>{
 const report=await audit.inspect(manifest,fake());
 assert.equal(report.status,'LOCAL_SIMULATOR_COMPLETION_VERIFIED');
 assert.equal(JSON.stringify(report).includes('"session_id"'),false);
 assert.equal(JSON.stringify(report).includes('"invoice_id"'),false);
}));
