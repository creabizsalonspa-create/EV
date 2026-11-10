#!/usr/bin/env node
'use strict';
// Agent 10: read-only readiness of the two managed local OCPP simulators.
// No physical charger, remote command, reservation, token or financial writes.
const fs=require('node:fs');
const path=require('node:path');
const {inspectManifest}=require('./demo-fixture-guard.cjs');

const PROTOCOLS=['1.6','2.0.1'];
const MAX_AGE_MS=35000;
function args(argv){
  if(argv.length!==2||argv[0]!=='--manifest'||!argv[1]||argv[1].startsWith('--'))
    throw Error('Expected --manifest private synthetic access.json');
  return argv[1];
}
function privateJson(file){
  const stat=fs.lstatSync(file);
  if(!stat.isFile()||stat.isSymbolicLink()||stat.size<2||stat.size>32*1024||
    (process.platform!=='win32'&&(stat.mode&0o077)!==0)) {
    throw Error('Private synthetic device evidence invalid');
  }
  const obj=JSON.parse(fs.readFileSync(file,'utf8'));
  if(!obj||typeof obj!=='object'||Array.isArray(obj))throw Error('Invalid device evidence JSON');
  return obj;
}
function defaultOwned(pid,manifest){
  if(!Number.isSafeInteger(pid)||pid<=1) return false;
  try{
    process.kill(pid,0); // no signal delivered, only local existence check
    const cmdline=fs.readFileSync('/proc/'+pid+'/cmdline','utf8');
    return cmdline.includes(path.resolve(__dirname,'client-demo-charger.cjs')) &&
      cmdline.includes(path.resolve(manifest));
  }catch{return false;}
}
function assessStation({manifest,dir,station,now=Date.now(),owned=defaultOwned}){
  const suffix=station.protocol.replaceAll('.','');
  const pidData=privateJson(path.join(dir,'pid-'+suffix+'.json'));
  const status=privateJson(path.join(dir,'status-'+suffix+'.json'));
  if(!Number.isSafeInteger(pidData.pid)||pidData.pid<=1||
    status.pid!==pidData.pid || status.station_id!==station.station_id ||
    status.protocol!==station.protocol || status.evidence!=='SIMULATED_CITRINEOS' ||
    status.physical_charging_verified!==false || status.state!=='idle') {
    throw Error('Simulator identity or safe idle state is invalid');
  }
  const observed=Date.parse(status.observed_at);
  if(!Number.isFinite(observed)||now-observed<0||now-observed>MAX_AGE_MS) {
    throw Error('Simulator idle observation is not fresh');
  }
  const journalPath=path.join(dir,'device-'+suffix+'.json');
  if(fs.existsSync(journalPath)){
    const journal=privateJson(journalPath);
    if(journal.phase!=='idle')throw Error('Unresolved local simulator transaction');
  }
  if(fs.existsSync(path.join(dir,'swipe-'+suffix+'.json'))) {
    throw Error('One-shot simulator command remains queued');
  }
  if(!owned(pidData.pid,manifest)) {
    throw Error('Simulator process is absent or owned by another program');
  }
  return {protocol:station.protocol,idle:true,observed_within_seconds:35,
    evidence:'SIMULATED_CITRINEOS',physical_charging_verified:false};
}
function run(file,opts={}){
  const errors=inspectManifest(file,{allowLocalQa:false});
  if(errors.length)throw Error('Synthetic customer/vendor/Admin demo fixture is unsafe');
  const fixture=privateJson(file);
  if(fixture.qa_current_bookings!==false||fixture.real_money!==false||
    fixture.physical_charging_verified!==false||
    fixture.base!=='http://127.0.0.1:8000') {
    throw Error('Not a strictly isolated synthetic OCPP demonstration');
  }
  const dir=path.dirname(file);
  const stations=PROTOCOLS.map(protocol=>{
    const station=fixture.stations.find(x=>x.protocol===protocol);
    if(!station)throw Error('Both simulator protocols are required');
    return assessStation({manifest:file,dir,station,now:opts.now||Date.now(),owned:opts.owned||defaultOwned});
  });
  return {ok:true,fixture_only:true,stations,physical_charging_verified:false,real_money_moved:false,
    customer_acceptance_verified:false};
}
function main(argv=process.argv.slice(2),opts={}){
  try{
    const result=run(args(argv),opts);
    if(!result.ok)throw Error('Synthetic readiness failed');
    process.stdout.write('PASS: private synthetic OCPP 1.6 and 2.0.1 simulators are idle, recent and process-owned. No physical charger, financial or production acceptance.\n');
    return 0;
  }catch{
    process.stderr.write('FAIL: private OCPP simulator identity, ownership or idle readiness requires review. No private values disclosed.\n');
    return 1;
  }
}
if(require.main===module)process.exitCode=main();
module.exports={PROTOCOLS,MAX_AGE_MS,args,privateJson,defaultOwned,assessStation,run,main};
