#!/usr/bin/env node
'use strict';
// Agent 10: private, local-only customer demo acceptance for synthetic accounts
// and OCPP simulator state; not physical charging, PSP or production acceptance.
const {run:simulatorReadiness}=require('./staging-simulator-readiness.cjs');
const {run:syntheticRoleSmoke}=require('./staging-synthetic-role-smoke.cjs');
const {checkOutputDestination,writeEvidence}=require('./staging-acceptance.cjs');

const ROLES=['customer','vendor','admin'];
const OCPP=['1.6','2.0.1'];
const SCHEMA='ev-client-demo-lab-readiness-v1';
function parseArgs(argv){
  if(!Array.isArray(argv) || argv.length!==4 ||
    argv[0]!=='--manifest'||!argv[1]||argv[1].startsWith('--')||
    argv[2]!=='--output'||!argv[3]||argv[3].startsWith('--')) {
    throw Error('Usage: --manifest PRIVATE/access.json --output NEW_PRIVATE_REPORT.json');
  }
  return {manifest:argv[1],output:argv[3]};
}
async function inspect(options,deps={}){
  const readiness=deps.simulators||simulatorReadiness;
  const roleCheck=deps.roles||syntheticRoleSmoke;
  const now=deps.now||(()=>new Date().toISOString());
  const result={
    schema:SCHEMA,observed_at_utc:now(),status:'INCOMPLETE',
    origin:'LOCAL_SYNTHETIC_DEMO_ONLY',
    simulated_protocols:[],browser_roles:[],
    physical_charging_verified:false,real_money_moved:false,
    live_ocpi_verified:false,production_accepted:false,
  };
  function requireSimulators(snap) {
    if(snap?.ok!==true || snap.physical_charging_verified!==false ||
      snap.real_money_moved!==false || snap.stations?.length!==2 ||
      snap.stations.some((x,i)=>x?.protocol!==OCPP[i] || x.idle!==true ||
        x.physical_charging_verified!==false ||
        x.evidence!=='SIMULATED_CITRINEOS')) {
      throw Error('Simulator readiness invalid');
    }
  }
  try{
    const first=readiness(options.manifest);
    requireSimulators(first);
    const roles=await roleCheck(options.manifest);
    if(roles?.ok!==true || roles.physical_charging_verified!==false ||
      roles.real_money_moved!==false || roles.production_accepted!==false ||
      roles.results?.length!==3 ||
      roles.results.some((x,i)=>x.role!==ROLES[i] || x.authenticated!==true ||
        x.read_only_features_verified!==true || x.post_logout_denied!==true ||
        x.admin_authorized!==(x.role==='admin'))) {
      throw Error('Synthetic browser session or RBAC check failed');
    }
    // Detect a charger transition/command while browser testing was in progress.
    const second=readiness(options.manifest);
    requireSimulators(second);
    result.status='PRIVATE_LAB_DEMO_READY';
    result.simulated_protocols=OCPP;
    result.browser_roles=ROLES;
  }catch{
    result.status='INCOMPLETE'; // secrets, accounts, raw responses and PIDs never copied
  }
  result.checked_until_utc=now();
  return result;
}
async function main(argv=process.argv.slice(2),deps={}){
  try{
    const config=parseArgs(argv);
    checkOutputDestination(config.output);
    const result=await inspect(config,deps);
    writeEvidence(config.output,result);
    if(result.status!=='PRIVATE_LAB_DEMO_READY'){
      process.stderr.write('FAIL: private synthetic demo readiness incomplete; no sensitive details printed.\n');
      return 1;
    }
    process.stdout.write('PASS: synthetic local account roles and both OCPP simulator idle states verified. No live charger/payment or production acceptance.\n');
    return 0;
  }catch{
    process.stderr.write('FAIL: private demo evidence output, input or safety guard unavailable; no sensitive details printed.\n');
    return 2;
  }
}
if(require.main===module)main().then(code=>{process.exitCode=code;});
module.exports={SCHEMA,parseArgs,inspect,main};
