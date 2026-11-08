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
const RELEASE_LOCK='/tmp/ev-staging-agent10-release.lock';
const MAX_EVIDENCE_WINDOW_MS=180000;
const ISO_UTC=/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,3})?Z$/;
function releaseIdle(file=RELEASE_LOCK) {
  // A release crash deliberately preserves the interlock: never consider an
  // existing lock stale just because it contains a dead PID.
  try {
    fs.lstatSync(file);
    throw Error('A private staging release or rollback requires reconciliation');
  } catch(error) {
    if(error?.code==='ENOENT')return true;
    throw error;
  }
}
function evidenceWindowValid(start,end){
  if(typeof start!=='string'||typeof end!=='string'||
      !ISO_UTC.test(start)||!ISO_UTC.test(end))return false;
  const a=Date.parse(start),b=Date.parse(end);
  return Number.isFinite(a)&&Number.isFinite(b)&&
      new Date(a).toISOString().slice(0,19)===start.slice(0,19) &&
      new Date(b).toISOString().slice(0,19)===end.slice(0,19) &&
      b>=a && b-a<=MAX_EVIDENCE_WINDOW_MS;
}

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
function readPrivateEnv(file=ENV_FILE) {
  // Read a single owner-only regular inode with no-follow. A concurrent
  // replacement must never turn the acceptance gate into a secrets reader.
  const flags=fs.constants.O_RDONLY | (fs.constants.O_NOFOLLOW||0) |
    (fs.constants.O_NONBLOCK||0);
  const stat=fs.lstatSync(file);
  if(!stat.isFile() || stat.isSymbolicLink() || stat.size<20 || stat.size>128*1024 ||
      (process.platform!=='win32' && (stat.mode&0o077)!==0)) {
    throw Error('Private staging .env is unsafe');
  }
  const fd=fs.openSync(file,flags);
  try {
    const opened=fs.fstatSync(fd);
    if(!opened.isFile() || opened.dev!==stat.dev || opened.ino!==stat.ino ||
        opened.size!==stat.size || opened.size>128*1024 ||
        (process.platform!=='win32' && (opened.mode&0o077)!==0)) {
      throw Error('Staging environment inode changed during read');
    }
    const content=fs.readFileSync(fd);
    if(content.length!==opened.size)throw Error('Staging environment changed during read');
    return content;
  }finally{fs.closeSync(fd);}
}
function reviewEnv() {
  // PR #62 must be merged before this workflow can succeed.
  const canonical=require('./staging-preflight.cjs');
  const initial=readPrivateEnv();
  const check=canonical.reviewFile(ENV_FILE);
  if(!check || check.ok!==true)throw Error('Private staging environment failed');
  const latest=readPrivateEnv();
  const firstDigest=crypto.createHash('sha256').update(initial).digest('hex');
  const finalDigest=crypto.createHash('sha256').update(latest).digest('hex');
  if(firstDigest!==finalDigest)throw Error('Private environment changed during review');
  const {values,errors}=canonical.parseEnv(latest.toString('utf8'));
  if(errors.length || !canonical.validDomain(values.STAGING_HOST))throw Error('Invalid staging hostname');
  return {host:values.STAGING_HOST,checker:canonical.checkValues,env_digest:finalDigest};
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
  const checkReleaseIdle=deps.checkReleaseIdle||releaseIdle;
  const result={
    schema:SCHEMA,source_sha:config.source_sha,status:'INCOMPLETE',
    observed_from_utc:now(),observed_until_utc:null,
    checks:{clean_checkout:false,release_interlock_clear:false,
      private_environment:false,resolved_compose:false,
      immutable_running_source:false,private_https_routes:false,
      private_environment_stable_after_http:false,stable_runtime_after_http:false},
    http_routes:[],runtime_fingerprint_sha256:null,
    image_source_label_self_declared:true,independent_build_attestation:false,
    synthetic_accounts_authenticated:false,simulator_session_completed:false,
    live_charger_verified:false,live_ocpi_verified:false,real_money_moved:false,
    production_accepted:false,customer_contract_accepted:false,
  };
  try {
    if(getHead()!==config.source_sha)throw Error('Checked-out source is wrong or dirty');
    result.checks.clean_checkout=true;
    if(checkReleaseIdle()!==true)throw Error('Release interlock was not clear');
    result.checks.release_interlock_clear=true;
    const stage=inspectEnv();
    if(!stage || stage.host!==config.health.url.hostname ||
        typeof stage.checker!=='function' ||
        !/^[a-f0-9]{64}$/.test(stage.env_digest||'')) {
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
    const afterEnv=inspectEnv();
    if(!afterEnv || afterEnv.host!==stage.host ||
        afterEnv.env_digest!==stage.env_digest ||
        typeof afterEnv.checker!=='function') {
      throw Error('Reviewed private environment changed during HTTPS probe');
    }
    result.checks.private_environment_stable_after_http=true;
    if(inspectEffective(afterEnv.checker)!==true || getHead()!==config.source_sha) {
      throw Error('Staging configuration or checkout changed during acceptance');
    }
    if(checkReleaseIdle()!==true)throw Error('Release or rollback started during HTTPS inspection');
    const bytes=SERVICES.map(s=>s+':'+first.services[s]).join('\n')+'\n'+first.image_id;
    result.runtime_fingerprint_sha256=crypto.createHash('sha256').update(bytes).digest('hex');
    result.checks.stable_runtime_after_http=true;
    result.status='PRIVATE_SOURCE_LINKED_STAGE_READY';
  } catch {
    // Redact all error details: Compose configuration can include private data.
    result.status='INCOMPLETE';
  }
  result.observed_until_utc=now();
  if(result.status==='PRIVATE_SOURCE_LINKED_STAGE_READY' &&
      !evidenceWindowValid(result.observed_from_utc,result.observed_until_utc)){
    result.status='INCOMPLETE';
    result.runtime_fingerprint_sha256=null;
    result.checks.stable_runtime_after_http=false;
  }
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
module.exports={SCHEMA,SERVICES,parseArgs,readPrivateEnv,reviewEnv,reviewEffective,releaseIdle,evidenceWindowValid,checkProvenance,checkRoutes,inspect,main};
