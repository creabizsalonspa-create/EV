#!/usr/bin/env node
'use strict';
// Agent 10: source-linked bundle of three independent PRIVATE synthetic lab reports.
// This is neither a signed attestation nor evidence of live charger/payment acceptance.
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const {fixtureSha256}=require('./client-demo-evidence-fingerprint.cjs');
const {checkOutputDestination,writeEvidence}=require('./staging-acceptance.cjs');

const SCHEMA='ev-local-synthetic-delivery-packet-v1';
const OCPP=['1.6','2.0.1'];
const ROLES=['customer','vendor','admin'];
const MAX_AGE=72*3600*1000,MAX_SPAN=24*3600*1000;
function args(argv){
  if(!Array.isArray(argv)||argv.length!==10 ||
    argv[0]!=='--manifest'||!argv[1]||argv[1].startsWith('--')||
    argv[2]!=='--readiness'||!argv[3]||argv[3].startsWith('--')||
    argv[4]!=='--completion'||!argv[5]||argv[5].startsWith('--')||
    argv[6]!=='--ledger'||!argv[7]||argv[7].startsWith('--')||
    argv[8]!=='--output'||!argv[9]||argv[9].startsWith('--')){
    throw Error('Expected --manifest private --readiness private.json --completion private.json --ledger private.json --output NEW.json');
  }
  return {manifest:argv[1],readiness:argv[3],completion:argv[5],ledger:argv[7],output:argv[9]};
}
function readPrivateReport(file){
  const absolute=path.resolve(file),stat=fs.lstatSync(absolute),
    parent=fs.lstatSync(path.dirname(absolute));
  if(!stat.isFile()||stat.isSymbolicLink()||stat.size<2||stat.size>96*1024||
    !parent.isDirectory()||parent.isSymbolicLink()||
    (process.platform!=='win32'&&((stat.mode&0o077)!==0||(parent.mode&0o077)!==0))){
    throw Error('Private evidence report must be a regular owner-only file');
  }
  const bytes=fs.readFileSync(absolute);
  // Sanitized reports must never become a carrier of account or session information.
  if(/"(?:password|email|account_id|invoice_id|session_id|csrf|authorization|cookie|access_token)"\s*:/i.test(bytes.toString('utf8'))){
    throw Error('Protected credential or account detail leaked into evidence');
  }
  const body=JSON.parse(bytes.toString('utf8'));
  if(!body||typeof body!=='object'||Array.isArray(body)){
    throw Error('Invalid private delivery evidence');
  }
  return {body,digest:crypto.createHash('sha256').update(bytes).digest('hex')};
}
function utc(value){
  if(typeof value!=='string'||!^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,3})?Z$/.test(value)){
    throw Error('Private demo UTC timestamp missing or malformed');
  }
  const n=Date.parse(value);
  if(!Number.isFinite(n)||new Date(n).toISOString().slice(0,19)!==value.slice(0,19)){
    throw Error('Invalid demo UTC timestamp');
  }
  return n;
}
function equal(value,expected){return JSON.stringify(value)===JSON.stringify(expected);}
function allFalse(item,keys){
  return keys.every(key=>Object.hasOwn(item,key)&&item[key]===false);
}
function validatePacket(fingerprint,reports,now=Date.now()){
  if(!/^[a-f0-9]{64}$/.test(fingerprint)||!Number.isFinite(now))throw Error('Synthetic fixture fingerprint missing');
  const [before,during,after]=reports.map(x=>x?.body);
  if(!before||!during||!after||
    reports.some(x=>!x.digest||!/^[a-f0-9]{64}$/.test(x.digest)) ||
    [before,during,after].some(x=>x.fixture_sha256!==fingerprint)) {
    throw Error('Evidence reports do not share exact private synthetic fixture');
  }
  if(before.schema!=='ev-client-demo-lab-readiness-v1'||
    before.status!=='PRIVATE_LAB_DEMO_READY'||
    !equal(before.simulated_protocols,OCPP)||!equal(before.browser_roles,ROLES)||
    !allFalse(before,['physical_charging_verified','real_money_moved','live_ocpi_verified','production_accepted'])) {
    throw Error('Private pre-demo readiness is incomplete or unsafe');
  }
  if(during.schema!=='ev-local-csms-demo-completion-v2'||
    during.status!=='LOCAL_SIMULATOR_COMPLETION_VERIFIED'||
    during.completion_count!==2||during.simulated_invoices_verified!==true||
    !Array.isArray(during.protocols)||during.protocols.length!==2||
    during.protocols.some((x,i)=>x.protocol!==OCPP[i]||x.stopped!==true||
      x.positive_simulated_energy!==true||x.mock_invoice_verified!==true||
      x.no_real_payment!==true||x.evidence!=='SIMULATED_CSMS')||
    !allFalse(during,['physical_charging_verified','real_money_moved','production_accepted','customer_contract_accepted'])) {
    throw Error('Private source-linked session completion is incomplete or unsafe');
  }
  if(after.schema!=='ev-local-csms-ledger-audit-v1'||
    after.status!=='LOCAL_SIMULATED_LEDGER_VERIFIED'||
    after.balanced_invoice_postings!==2||
    !equal(after.simulated_protocols,OCPP)||
    !allFalse(after,['physical_charging_verified','real_money_moved',
      'payment_processor_verified','production_accepted','customer_contract_accepted'])) {
    throw Error('Private simulated invoice journal evidence is incomplete or unsafe');
  }
  const firstStart=utc(before.observed_at_utc),firstEnd=utc(before.checked_until_utc);
  const middleStart=utc(during.observed_from_utc),middleEnd=utc(during.checked_until_utc);
  const lastStart=utc(after.checked_from_utc),lastEnd=utc(after.checked_until_utc);
  if(firstStart>firstEnd || firstEnd>middleStart || middleStart>middleEnd ||
    middleEnd>lastStart || lastStart>lastEnd || lastEnd-firstStart>MAX_SPAN ||
    lastEnd>now+5*60*1000 || now-lastEnd>MAX_AGE) {
    throw Error('Evidence window is stale, future-dated, overlapping or out of order');
  }
  return {
    schema:SCHEMA,status:'PRIVATE_LAB_EVIDENCE_BUNDLE_VERIFIED',
    source:'LOCAL_SYNTHETIC_ONLY',fixture_sha256:fingerprint,
    evidence_sha256:{
      readiness:reports[0].digest,completed_sessions:reports[1].digest,
      simulated_ledger:reports[2].digest,
    },
    simulated_protocols:OCPP,browser_roles:ROLES,
    demo_start_utc:before.observed_at_utc,demo_end_utc:after.checked_until_utc,
    reviewed_at_utc:new Date(now).toISOString(),
    physical_charging_verified:false,real_money_moved:false,
    live_ocpi_verified:false,payment_processor_verified:false,
    production_accepted:false,customer_contract_accepted:false,
    source_signed_or_independently_attested:false,
  };
}
function bundle(config,deps={}){
  const unique=[config.manifest,config.readiness,config.completion,config.ledger,config.output].map(x=>path.resolve(x));
  if(new Set(unique).size!==unique.length)throw Error('Distinct private fixture, report and output files required');
  const fingerprint=(deps.fingerprint||fixtureSha256)(config.manifest);
  const reports=['readiness','completion','ledger'].map(name=>readPrivateReport(config[name]));
  return validatePacket(fingerprint,reports,deps.now===undefined?Date.now():deps.now);
}
function main(argv=process.argv.slice(2),deps={}){
  try{
    const config=args(argv);
    checkOutputDestination(config.output);
    const summary=bundle(config,deps);
    writeEvidence(config.output,summary);
    process.stdout.write('PASS: three local-only source-bound private demo phases reviewed. No live charger, provider payment, signed build or production acceptance.\n');
    return 0;
  }catch{
    process.stderr.write('FAIL: private demonstration packet unsafe or incomplete; confidential details withheld.\n');
    return 1;
  }
}
if(require.main===module)process.exitCode=main();
module.exports={SCHEMA,args,readPrivateReport,utc,validatePacket,bundle,main};
