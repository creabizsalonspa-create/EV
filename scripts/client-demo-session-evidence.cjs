#!/usr/bin/env node
'use strict';
// Agent 10: read-only post-demo evidence of two completed LOCAL synthetic CSMS sessions.
// Does not start/stop chargers, reserve slots, issue payments or operate production.
const {load,jsonRequest}=require('./staging-synthetic-role-smoke.cjs');
const {checkOutputDestination,writeEvidence}=require('./staging-acceptance.cjs');

const SCHEMA='ev-local-csms-demo-completion-v1';
const PROTOCOLS=['1.6','2.0.1'];
function parseArgs(argv) {
  if(!Array.isArray(argv)||argv.length!==4||argv[0]!=='--manifest'||
    !argv[1]||argv[1].startsWith('--')||argv[2]!=='--output'||
    !argv[3]||argv[3].startsWith('--'))throw Error('Expected --manifest PRIVATE/access.json --output NEW_PRIVATE.json');
  return {manifest:argv[1],output:argv[3]};
}
function page(body) {
  if(!body || !Array.isArray(body.data) || body.data.length>25 ||
    !Number.isSafeInteger(body.total)||body.total<0)throw Error('Invalid paginated local demo API');
  return body.data;
}
function validateCompleted(fixture,userId,reservations,sessions) {
  if(!Number.isSafeInteger(userId)||userId<=0)throw Error('Invalid synthetic account identity');
  const found=[],usedSessions=new Set(),usedReservations=new Set();
  for(const station of fixture.stations) {
    const eligible=reservations.filter(r=>r?.station_id===station.station_id && r.user_id===userId &&
      Number.isSafeInteger(r.id)&&r.id>0);
    if(!eligible.length)throw Error('Missing synthetic station reservation');
    const ids=new Set(eligible.map(r=>r.id));
    const complete=sessions.filter(s=>ids.has(s?.reservation_id) &&
      Number.isSafeInteger(s.id)&&s.id>0 &&
      s.status==='stopped'&&s.evidence==='SIMULATED_CSMS'&&
      s.csms_linked===true&&s.payment_status==='not_processed'&&
      Number.isSafeInteger(s.energy_wh)&&s.energy_wh>0&&s.energy_wh<=1000000&&
      Number.isSafeInteger(s.total_minor)&&s.total_minor>=0&&
      s.currency==='AED'&&
      (s.physical_charging_verified===undefined||s.physical_charging_verified===false));
    if(!complete.length)throw Error('Missing completed source-linked simulation');
    // Prefer newest actual completed synthetic source session (API is ordered descending).
    const selected=complete[0];
    if(usedSessions.has(selected.id)||usedReservations.has(selected.reservation_id)) {
      throw Error('A single transaction cannot prove both OCPP protocols');
    }
    usedSessions.add(selected.id);usedReservations.add(selected.reservation_id);
    found.push({protocol:station.protocol,stopped:true,positive_simulated_energy:true,
      evidence:'SIMULATED_CSMS',no_real_payment:true});
  }
  if(found.length!==2 || PROTOCOLS.some((protocol,i)=>found[i].protocol!==protocol)){
    throw Error('Both source protocols must be independently completed');
  }
  return found;
}
async function inspect(manifest,send=jsonRequest,now=()=>new Date().toISOString()) {
  const result={schema:SCHEMA,status:'INCOMPLETE',observed_from_utc:now(),
    source:'LOCAL_SYNTHETIC_ONLY',protocols:[],completion_count:0,
    physical_charging_verified:false,real_money_moved:false,
    production_accepted:false,customer_contract_accepted:false};
  const fixture=load(manifest); // canonical strict local-only demo manifest
  const account=fixture.accounts.customer,jar=new Map();
  let loggedIn=false,logoutOk=false;
  try{
    const csrf=await send('/api/csrf','GET',undefined,jar);
    if(csrf.status!==200||typeof csrf.body?.token!=='string'||csrf.body.token.length<12)
      throw Error('Local CSRF bootstrap failed');
    jar.set('__csrf_token',csrf.body.token);
    const login=await send('/api/login','POST',{email:account.email,password:account.password},jar);
    // If a 200 login activated any session, revoke it even when identity is unexpected.
    if(login.status===200)loggedIn=true;
    if(login.status!==200||login.body?.role!=='customer'||login.body.email!==account.email||
      !Number.isSafeInteger(login.body.id))throw Error('Synthetic customer login mismatch');
    const reservations=await send('/api/reservations','GET',undefined,jar);
    const sessions=await send('/api/simulations','GET',undefined,jar);
    if(reservations.status!==200||sessions.status!==200)throw Error('Local session read failed');
    const verified=validateCompleted(fixture,login.body.id,page(reservations.body),page(sessions.body));
    result.protocols=verified;
    result.completion_count=verified.length;
  } catch {
    result.status='INCOMPLETE';
    result.protocols=[];result.completion_count=0;
  } finally {
    if(loggedIn){
      try {
        const logout=await send('/api/logout','POST',undefined,jar);
        const me=await send('/api/me','GET',undefined,jar);
        logoutOk=logout.status===204 && [401,403].includes(me.status);
      } catch {logoutOk=false;}
    }
  }
  if(result.completion_count===2 && logoutOk)result.status='LOCAL_SIMULATOR_COMPLETION_VERIFIED';
  else {result.status='INCOMPLETE';result.protocols=[];result.completion_count=0;}
  result.checked_until_utc=now();
  return result;
}
async function main(argv=process.argv.slice(2),deps={}) {
  try {
    const options=parseArgs(argv);
    checkOutputDestination(options.output);
    const proof=await inspect(options.manifest,deps.send||jsonRequest,deps.now);
    writeEvidence(options.output,proof);
    if(proof.status!=='LOCAL_SIMULATOR_COMPLETION_VERIFIED') {
      process.stderr.write('FAIL: local-only simulated charging completion lacks required evidence; no credentials or API bodies disclosed.\n');
      return 1;
    }
    process.stdout.write('PASS: two independent source-linked LOCAL OCPP simulator sessions completed with positive synthetic energy; no physical charging or payment acceptance.\n');
    return 0;
  } catch {
    process.stderr.write('FAIL: private post-demo report or strict local synthetic input unavailable; no protected values disclosed.\n');
    return 2;
  }
}
if(require.main===module)main().then(code=>{process.exitCode=code;});
module.exports={SCHEMA,PROTOCOLS,parseArgs,page,validateCompleted,inspect,main};
