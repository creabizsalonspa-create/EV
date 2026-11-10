'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const guard=require('../scripts/staging-simulator-readiness.cjs');
const NOW=Date.parse('2026-11-07T18:00:00.000Z');
const RUN='a1b2c3d4';
function fixture(){
 const accounts={};
 for(const role of ['customer','vendor','admin']){
   accounts[role]={email:'client-demo-'+role+'-'+RUN+'@example.test',password:'a'.repeat(48)+'aA9!'};
 }
 return {fixture_only:true,run:RUN,base:'http://127.0.0.1:8000',company_id:1,accounts,
   stations:[['1.6','16'],['2.0.1','201']].map(([protocol,suffix],i)=>({
     protocol,identity:'ev-test-demo-'+RUN+'-'+suffix,station_id:i+1,
     charger_id:i+3,binding_id:i+5,connector_mapping_id:i+7,reservation_id:null})),
   qa_current_bookings:false,physical_charging_verified:false,real_money:false};
}
function sandbox(fn){
 const root=fs.mkdtempSync(path.join(os.tmpdir(),'ev-sim-readiness-'));fs.chmodSync(root,0o700);
 const base=path.join(root,'client-demo'),dir=path.join(base,RUN);
 fs.mkdirSync(base,{mode:0o700});fs.mkdirSync(dir,{mode:0o700});
 const manifest=path.join(dir,'access.json');
 const json=(name,content)=>fs.writeFileSync(path.join(dir,name),JSON.stringify(content),{mode:0o600});
 json('access.json',fixture());
 for(const [i,suffix] of ['16','201'].entries()){
   json('pid-'+suffix+'.json',{pid:5000+i});
   json('status-'+suffix+'.json',{
     state:'idle',event:null,pid:5000+i,protocol:i===0?'1.6':'2.0.1',
     station_id:i+1,evidence:'SIMULATED_CITRINEOS',physical_charging_verified:false,
     observed_at:new Date(NOW-10000).toISOString()});
   json('device-'+suffix+'.json',{phase:'idle',meter_wh:1000});
 }
 const deps={now:NOW,owned:()=>true};
 try {return fn({dir,manifest,json,deps});}
 finally{fs.rmSync(root,{recursive:true,force:true});}
}
function mustReject(change){
 sandbox(x=>{change(x);assert.throws(()=>guard.run(x.manifest,x.deps));});
}
test('both OCPP simulator identities are safely idle and not physical',()=>sandbox(x=>{
 const result=guard.run(x.manifest,x.deps);
 assert.equal(result.ok,true);
 assert.equal(result.stations.length,2);
 assert.deepEqual(result.stations.map(y=>y.protocol),['1.6','2.0.1']);
 assert.equal(result.physical_charging_verified,false);
 assert.equal(result.real_money_moved,false);
 assert.equal(result.customer_acceptance_verified,false);
}));
test('station identity and required OCPP protocol cannot be switched',()=>{
 mustReject(x=>x.json('status-16.json',{state:'idle',pid:5000,protocol:'2.0.1',
   station_id:1,evidence:'SIMULATED_CITRINEOS',physical_charging_verified:false,
   observed_at:new Date(NOW).toISOString()}));
});
test('physical charging claim or wrong evidence is rejected',()=>{
 mustReject(x=>x.json('status-16.json',{state:'idle',pid:5000,protocol:'1.6',
   station_id:1,evidence:'PHYSICAL_CHARGER',physical_charging_verified:true,
   observed_at:new Date(NOW).toISOString()}));
});
test('active, reconnecting, stopped or unresolved charger states cannot be presented as ready',()=>{
 for(const state of ['charging','connecting','stopped','requires_operator_review']){
   mustReject(x=>x.json('status-201.json',{state,pid:5001,protocol:'2.0.1',
     station_id:2,evidence:'SIMULATED_CITRINEOS',physical_charging_verified:false,
     observed_at:new Date(NOW).toISOString()}));
 }
 mustReject(x=>x.json('device-201.json',{phase:'charging',meter_wh:1250}));
});
test('pending one-shot swipe command prevents clean demo readiness',()=>{
 mustReject(x=>x.json('swipe-201.json',{token:'private-test',queued_at:NOW}));
});
test('stale and future timestamps cannot pass as a fresh simulator',()=>{
 for(const time of [NOW-60000,NOW+2000]){
   mustReject(x=>x.json('status-16.json',{state:'idle',pid:5000,protocol:'1.6',
     station_id:1,evidence:'SIMULATED_CITRINEOS',physical_charging_verified:false,
     observed_at:new Date(time).toISOString()}));
 }
});
test('process owner check must confirm actual managed demo process',()=>{
 mustReject(x=>{x.deps.owned=()=>false;});
});
test('forged PID file cannot claim another running process',()=>{
 mustReject(x=>{x.json('pid-16.json',{pid:99999});});
});
test('missing or symlinked status file fails before any remote operations',()=>{
 mustReject(x=>fs.unlinkSync(path.join(x.dir,'status-16.json')));
 mustReject(x=>{
   const source=path.join(x.dir,'source.json');
   x.json('source.json',{state:'idle'});
   fs.unlinkSync(path.join(x.dir,'status-16.json'));
   fs.symlinkSync(source,path.join(x.dir,'status-16.json'));
 });
});
test('world-readable simulator status evidence is rejected', {skip:process.platform==='win32'},()=>{
 mustReject(x=>fs.chmodSync(path.join(x.dir,'status-16.json'),0o644));
});
test('manifest current-slot bypass cannot appear in normal demo readiness',()=>{
 mustReject(x=>x.json('access.json',{...fixture(),qa_current_bookings:true}));
});
test('no output includes private account names or passwords on failure',()=>sandbox(x=>{
 x.deps.owned=()=>false;
 assert.equal(guard.main(['--manifest',x.manifest],x.deps),1);
}));
test('unsupported CLI options fail closed and do not launch a charger',()=>{
 assert.throws(()=>guard.args(['--run','live']));
 assert.throws(()=>guard.args(['--manifest','--unsafe']));
});
