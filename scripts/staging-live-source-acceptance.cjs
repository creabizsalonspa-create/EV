#!/usr/bin/env node
'use strict';
// Agent 10: local staging evidence only. No Compose up/down, migrations, payment,
// charger commands, CA installation, remote endpoints or production mutations.
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const {getCurrentHead,checkOutputDestination,writeEvidence}=require('./staging-acceptance.cjs');
const {parseArgs:parseHealthArgs,checks:routes,run:runHealth}=require('./staging-health.cjs');
const {localInspect}=require('./staging-image-provenance.cjs');
const {liveConfig,runReview}=require('./staging-effective-config.cjs');

const SCHEMA='ev-private-source-linked-staging-acceptance-v1';
const SHA=/^[a-f0-9]{40}$/;
const IMAGE=/^sha256:[a-f0-9]{64}$/;
const SERVICES=['application','worker','scheduler','database','gateway'];
const ENV_FILE=path.resolve(__dirname,'../infra/staging/.env');

function parseArgs(args) {
  if(!Array.isArray(args)||args.length!==6 ||
      args[0]!=='--source-sha'||!SHA.test(args[1]||'') ||
      args[2]!=='--url'||!args[3]||args[3].startsWith('--') ||
      args[4]!=='--output'||!args[5]||args[5].startsWith('--')) {
    throw Error('Usage: --source-sha EXACT_SHA --url https://STAGING_HOST:8443/ --output NEW_PRIVATE.json');
  }
  const health=parseHealthArgs(['--url',args[3],'--tls-loopback']);
  if(health.url.port!=='8443')throw Error('Only the approved private TLS gateway port is permitted');
  return {source_sha:args[1],health,output:args[5]};
}
function reviewEnv() {
  // PR #62 must be merged before this workflow can succeed.
  const canonical=require('./staging-preflight.cjs');
  const check=canonical.reviewFile(ENV_FILE);
  if(!check || check.ok!==true)throw Error('Private staging environment failed');
  const {values,errors}=canonical.parseEnv(fs.readFileSync(ENV_FILE,'utf8'));
  if(errors.length || !canonical.validDomain(values.STAGING_HOST))throw Error('Invalid staging hostname');
  return {host:values.STAGING_HOST,checker:canonical.checkValues};
}
function reviewEffective(checker) {
  const source=liveConfig(execFileSync,process.env);
  if(runReview(source,checker).ok!==true)throw Error('Resolved staging configuration invalid');
  return true;
}
function checkProvenance(proof,sha) {
  if(!proof || proof.ok!==true || proof.source_sha!==sha || !IMAGE.test(proof.image_id||'')) {
    throw Error('Runtime image does not match source revision');
  }
  const ids=proof.runtime_services;
  if(!ids || typeof ids!=='object' || Array.isArray(ids) ||
      Object.keys(ids).sort().join(',')!==SERVICES.slice().sort().join(',')) {
    throw Error('Missing complete staging runtime inventory');
  }
  const values=SERVICES.map(name=>ids[name]);
  if(values.some(x=>typeof x!=='string'||!/^[a-f0-9]{12,64}$/.test(x)) ||
      new Set(values).size!==5)throw Error('Staging runtime inventory contains invalid identities');
  return {image_id:proof.image_id,services:ids};
}
function checkRoutes(outcome) {
  if(!outcome || outcome.ok!==true || !Array.isArray(outcome.results) ||
      outcome.results.length!==routes.length)throw Error('Incomplete private HTTPS check');
  return routes.map((expected,i)=>{
    const row=outcome.results[i];
    if(!row || row.path!==expected.path || row.ok!==true ||
        (expected.kind==='private' ? ![401,403].includes(row.status) : row.status!==200)) {
      throw Error('Private HTTPS route failed or returned unexpected status');
    }
    return {route:expected.path,status:row.status,passed:true};
  });
}
async function inspect(config,deps={}) {
  const now=deps.now||(()=>new Date().toISOString());
  const getHead=deps.getHead||getCurrentHead;
  const inspectEnv=deps.inspectEnv||reviewEnv;
  const inspectEffective=deps.inspectEffective||reviewEffective;
  const inspectImages=deps.inspectImages||localInspect;
  const checkHttp=deps.checkHttp||runHealth;
  const result={
    schema:SCHEMA,source_sha:config.source_sha,status:'INCOMPLETE',
    observed_from_utc:now(),observed_until_utc:null,
    checks:{clean_checkout:false,private_environment:false,resolved_compose:false,
      immutable_running_source:false,private_https_routes:false,stable_runtime_after_http:false},
    http_routes:[],runtime_fingerprint_sha256:null,
    image_source_label_self_declared:true,independent_build_attestation:false,
    synthetic_accounts_authenticated:false,simulator_session_completed:false,
    live_charger_verified:false,live_ocpi_verified:false,real_money_moved:false,
    production_accepted:false,customer_contract_accepted:false,
  };
  try {
    if(getHead()!==config.source_sha)throw Error('Checked-out source is wrong or dirty');
    result.checks.clean_checkout=true;
    const stage=inspectEnv();
    if(!stage || stage.host!==config.health.url.hostname || typeof stage.checker!=='function') {
      throw Error('Private staging origin differs from reviewed environment');
    }
    result.checks.private_environment=true;
    if(inspectEffective(stage.checker)!==true)throw Error('Resolved Compose is unsafe');
    result.checks.resolved_compose=true;
    const first=checkProvenance(inspectImages(config.source_sha),config.source_sha);
    result.checks.immutable_running_source=true;
    result.http_routes=checkRoutes(await checkHttp(config.health));
    result.checks.private_https_routes=true;
    // The HTTP probe can take many seconds; prevent accepting a newly swapped
    // DB/gateway or a new app container that happens to use the same image ID.
    const second=checkProvenance(inspectImages(config.source_sha),config.source_sha);
    if(first.image_id!==second.image_id ||
        SERVICES.some(s=>first.services[s]!==second.services[s])) {
      throw Error('Staging container identity changed during HTTPS acceptance');
    }
    if(inspectEffective(stage.checker)!==true || getHead()!==config.source_sha) {
      throw Error('Staging configuration or checkout changed during acceptance');
    }
    const bytes=SERVICES.map(s=>s+':'+first.services[s]).join('\n')+'\n'+first.image_id;
    result.runtime_fingerprint_sha256=crypto.createHash('sha256').update(bytes).digest('hex');
    result.checks.stable_runtime_after_http=true;
    result.status='PRIVATE_SOURCE_LINKED_STAGE_READY';
  } catch {
    // Redact all error details: Compose configuration can include private data.
    result.status='INCOMPLETE';
  }
  result.observed_until_utc=now();
  return result;
}
async function main(argv=process.argv.slice(2),deps={}) {
  try {
    const config=parseArgs(argv);
    checkOutputDestination(config.output);
    const result=await inspect(config,deps);
    writeEvidence(config.output,result);
    if(result.status!=='PRIVATE_SOURCE_LINKED_STAGE_READY'){
      process.stderr.write('FAIL: private source-linked staging readiness incomplete. Inspect locally; no private details disclosed.\n');
      return 1;
    }
    process.stdout.write('PASS: private staging source-labeled running image, stable containers and trusted loopback TLS checked. No deployment, authenticated transactions, hardware, PSP or production acceptance.\n');
    return 0;
  } catch {
    process.stderr.write('FAIL: private source-linked staging arguments or owner-only output invalid. No deployment performed.\n');
    return 2;
  }
}
if(require.main===module)main().then(code=>{process.exitCode=code;});
module.exports={SCHEMA,SERVICES,parseArgs,reviewEnv,reviewEffective,checkProvenance,checkRoutes,inspect,main};
