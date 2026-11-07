#!/usr/bin/env node
'use strict';

// Explicit staging-only application release. Never targets production, database, gateway,
// persistent volumes, migrations, physical chargers, payments or external partner networks.
// An operator must separately authorize execution after backup/CI checks.
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { parseSnapshot, checkSnapshot, localDockerSnapshot } = require('./staging-runtime-gate.cjs');

const PROJECT = 'ev-staging';
const ROLES = ['application', 'worker', 'scheduler'];
const SOURCE = 'https://github.com/creabizsalonspa-create/EV';
const SHA = /^[0-9a-f]{40}$/;
const ID = /^sha256:[0-9a-f]{64}$/;
const TAG = /^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/;
const TIMEOUT = 120000;
const composePath = path.resolve(__dirname, '../infra/staging/compose.yaml');
const envPath = path.resolve(__dirname, '../infra/staging/.env');

function parseArgs(args) {
  if (!Array.isArray(args) || !['plan', 'apply'].includes(args[0])) throw Error('Expected plan or apply');
  const mode = args[0];
  const pairs = Object.create(null);
  const keys = ['--candidate-tag', '--source-sha', '--journal', '--approval'];
  for (let i = 1; i < args.length; i += 2) {
    if (!keys.includes(args[i]) || Object.hasOwn(pairs, args[i]) ||
        !args[i+1] || args[i+1].startsWith('--')) throw Error('Unrecognized or repeated release argument');
    pairs[args[i]] = args[i+1];
  }
  if (Object.keys(pairs).some(k => !keys.includes(k)) ||
      typeof pairs['--candidate-tag'] !== 'string' || !TAG.test(pairs['--candidate-tag']) ||
      !SHA.test(pairs['--source-sha'])) throw Error('Invalid release image tag or exact source SHA');
  if (mode === 'plan' && (pairs['--approval'] || pairs['--journal'])) throw Error('Plan may not accept mutation flags');
  if (mode === 'apply') {
    if (pairs['--approval'] !== 'APPLY_PRIVATE_STAGING_' + pairs['--source-sha'].slice(0,12) ||
        !pairs['--journal']) throw Error('Explicit release approval and private new journal required');
  }
  return {mode,tag:pairs['--candidate-tag'],sha:pairs['--source-sha'],journal:pairs['--journal']};
}

function assertLocalSafety(envFile, env) {
  for (const name of ['DOCKER_HOST','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']) {
    if (env[name]) throw Error('Remote Docker endpoints forbidden');
  }
  if (env.DOCKER_CONTEXT && env.DOCKER_CONTEXT !== 'default') throw Error('Nondefault Docker context forbidden');
  const st = fs.lstatSync(envFile);
  if (!st.isFile() || st.isSymbolicLink() || st.size < 20 || st.size > 128 * 1024 ||
      (process.platform !== 'win32' && (st.mode & 0o077) !== 0)) {
    throw Error('Private staging .env is missing or readable by other users');
  }
}

function inspectImage(imageName, run = execFileSync) {
  const text = String(run('docker',['image','inspect',imageName],{
    encoding:'utf8',timeout:10000,maxBuffer:1024*1024,
  }));
  const items = JSON.parse(text);
  if (!Array.isArray(items) || items.length !== 1 || !ID.test(items[0].Id) ||
      !Array.isArray(items[0].RepoTags) || !items[0].RepoTags.includes(imageName)) {
    throw Error('Image tag and immutable local image ID did not resolve');
  }
  return items[0];
}

function reviewIsolatedConfig(envFile, deps={}) {
  // Require Agent 10's canonical offline config gate from PR #62.
  // Never deploy a candidate when DB_URL, live PSP, OCPP or OCPI flags could escape staging.
  const gate=deps.preflight || (() => {
    const file=path.resolve(__dirname,'staging-preflight.cjs');
    if (!fs.existsSync(file)) throw Error('Mandatory isolated staging preflight is not integrated');
    return require(file).reviewFile;
  })();
  const report=gate(envFile);
  if(!report || report.ok!==true) throw Error('Staging environment failed isolated preflight');
}
function reviewEffectiveCompose(deps={}) {
  if (typeof deps.effectiveConfig==='function') {
    if (deps.effectiveConfig()!==true) throw Error('Effective staging Compose security rejected');
    return;
  }
  const {liveConfig,runReview}=require('./staging-effective-config.cjs');
  const checker=require('./staging-preflight.cjs').checkValues;
  runReview(liveConfig(deps.run||execFileSync,deps.env||process.env),checker);
}
function inspectPlan(config, deps = {}) {
  const run = deps.run || execFileSync, env = deps.env || process.env;
  const dotenv = deps.envFile || envPath;
  assertLocalSafety(dotenv,env);
  reviewIsolatedConfig(dotenv,deps);
  reviewEffectiveCompose(deps);
  const before = parseSnapshot(localDockerSnapshot(env,run));
  const current = checkSnapshot(before);
  if (!current.ok) throw Error('Staging health or private port gate failed');
  const imageRefs = ROLES.map(service=>before.find(row=>row.Service===service)?.Image);
  const oldRef=imageRefs[0];
  if (imageRefs.some(ref=>ref!==oldRef) || !/^creabiz-ev-business:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/.test(oldRef||'')) {
    throw Error('Current running image tag invalid or inconsistent');
  }
  const candidateRef='creabiz-ev-business:'+config.tag;
  if (candidateRef===oldRef) throw Error('Candidate must differ from running release tag');
  const currentImage=inspectImage(oldRef,run);
  const candidateImage=inspectImage(candidateRef,run);
  if (currentImage.Id===candidateImage.Id) throw Error('Candidate image ID equals old deployment');
  const labels=candidateImage.Config?.Labels||{};
  if (labels['org.opencontainers.image.revision']!==config.sha ||
      labels['org.opencontainers.image.source']!==SOURCE ||
      labels['com.creabiz.ev.staging.review']!=='true') {
    throw Error('Candidate image does not carry expected staging Git revision and review labels');
  }
  const protectedServices={};
  for(const service of ['database','gateway']) {
    const id=before.find(row=>row.Service===service)?.ID;
    if(typeof id!=='string'||!/^[a-f0-9]{12,64}$/.test(id)) {
      throw Error('Protected staging service identity unavailable');
    }
    protectedServices[service]=id;
  }
  if(protectedServices.database===protectedServices.gateway)throw Error('Protected service identity collision');
  return { project:PROJECT, source_sha:config.sha, old_ref:oldRef,
    old_id:currentImage.Id, candidate_ref:candidateRef, candidate_id:candidateImage.Id,
    roles:ROLES, protected_services:protectedServices,
    database_and_gateway_unchanged:true, automatic_migrations:false,
    external_services_or_payments_invoked:false };
}

function outputJournal(file) {
  const target=path.resolve(file);
  const parent=fs.lstatSync(path.dirname(target));
  if (!parent.isDirectory() || parent.isSymbolicLink() ||
      (process.platform!=='win32' && (parent.mode & 0o077)!==0) ||
      !/^[A-Za-z0-9_.-]+\.jsonl$/.test(path.basename(target)) || fs.existsSync(target)) {
    throw Error('Journal must be a new private JSONL file within an owner-only directory');
  }
  return target;
}

function createJournal(file, plan) {
  const target=outputJournal(file);
  const fd=fs.openSync(target,'wx',0o600);
  try { fs.writeSync(fd,JSON.stringify({event:'PREPARED',at:new Date().toISOString(),plan})+'\n');fs.fsyncSync(fd); }
  catch(e){fs.closeSync(fd);fs.unlinkSync(target);throw e;}
  fs.closeSync(fd);
  return target;
}
function journal(file,event,details={}) {
  const fd=fs.openSync(file,'a',0o600);
  try {fs.writeSync(fd,JSON.stringify({event,at:new Date().toISOString(),...details})+'\n');fs.fsyncSync(fd);}
  finally {fs.closeSync(fd);}
}

function composeUp(tag, deps={}) {
  const run=deps.run||execFileSync;
  const environment={...(deps.env||process.env),EV_IMAGE_TAG:tag};
  run('docker',['compose','--project-name',PROJECT,'-f',deps.composeFile||composePath,
    '--env-file',deps.envFile||envPath,'up','-d','--no-deps','--no-build','--wait',...ROLES],{
      env:environment,encoding:'utf8',timeout:TIMEOUT,maxBuffer:1024*1024,
    });
}

function probePostDeploy(plan,deps={}) {
  // A running container is not proof that a browser can use the staged backend.
  // Probe only private loopback TLS with certificate verification enabled.
  if(typeof deps.checkHttp==='function') {
    if(deps.checkHttp(plan)!==true)throw Error('Private staging HTTP readiness failed');
    return true;
  }
  const {parseEnv}=require('./staging-preflight.cjs');
  const source=fs.readFileSync(deps.envFile||envPath,'utf8');
  const {values,errors}=parseEnv(source);
  if(errors.length || !values.STAGING_HOST)throw Error('Reviewed staging host unavailable');
  const validated=require('./staging-health.cjs').parseArgs(
    ['--url','https://'+values.STAGING_HOST+':8443/','--tls-loopback'],
    deps.env||process.env);
  if(!validated.tlsLoopback)throw Error('Private TLS loopback mode required');
  (deps.nodeRun||execFileSync)(process.execPath,[
    path.resolve(__dirname,'staging-health.cjs'),'--url',validated.url.toString(),'--tls-loopback'],
    {encoding:'utf8',timeout:65000,maxBuffer:65536,stdio:['ignore','pipe','pipe'],
      env:deps.env||process.env});
  return true;
}
function verifyFormerTag(plan,deps={}) {
  // The old image is the only automated rollback target. A release must never
  // proceed when that reviewed image has been removed or re-pointed.
  const old=inspectImage(plan.old_ref,deps.run||execFileSync);
  if(old.Id!==plan.old_id)throw Error('Previous staging image is no longer the reviewed rollback target');
  return true;
}
function verifyCandidateTag(plan,deps={}) {
  // OCI labels are self-declared but must remain identical to the reviewed
  // immutable candidate at the last safe moment before and after Compose up.
  const candidate=inspectImage(plan.candidate_ref,deps.run||execFileSync);
  const labels=candidate.Config?.Labels||{};
  if(candidate.Id!==plan.candidate_id ||
    labels['org.opencontainers.image.revision']!==plan.source_sha ||
    labels['org.opencontainers.image.source']!==SOURCE ||
    labels['com.creabiz.ev.staging.review']!=='true') {
    throw Error('Staging candidate tag was replaced since release plan');
  }
  return true;
}
function verifyProtectedServices(plan,deps={}) {
  const run=deps.run||execFileSync;
  const rows=parseSnapshot(localDockerSnapshot(deps.env||process.env,run));
  if(!checkSnapshot(rows).ok)throw Error('Private staging health or gateway exposure changed');
  for(const name of ['database','gateway']){
    if(rows.find(row=>row.Service===name)?.ID!==plan.protected_services?.[name]) {
      throw Error('Protected staging database or gateway container was replaced');
    }
  }
  return true;
}
function verifyRunning(expectedRef,expectedId,deps={}) {
  const run=deps.run||execFileSync;
  const rows=parseSnapshot(localDockerSnapshot(deps.env||process.env,run));
  if(!checkSnapshot(rows).ok || ROLES.some(role=>rows.find(x=>x.Service===role)?.Image!==expectedRef)) {
    throw Error('Running service health/port/image tag failed after staging change');
  }
  const images=ROLES.map(role=>rows.find(x=>x.Service===role)?.ID);
  if(images.some(id=>!id || !/^[a-f0-9]{12,64}$/.test(id))) throw Error('Container ID missing');
  const inspected=JSON.parse(String(run('docker',['container','inspect',...images],{
    encoding:'utf8',timeout:10000,maxBuffer:1024*1024,
  })));
  if(!Array.isArray(inspected) || inspected.length!==ROLES.length ||
     inspected.some(ctr=>ctr.Image!==expectedId || ctr.State?.Running!==true)) {
    throw Error('Actual running image ID differs from reviewed image');
  }
  return true;
}

function acquireLock(deps={}) {
  // Exclusive lock prevents two independent staging app updates from interleaving.
  // A crash deliberately leaves a lock requiring operator inspection, not an unsafe timeout unlock.
  const file=deps.lockPath || '/tmp/ev-staging-agent10-release.lock';
  if (fs.existsSync(file)) throw Error('A staging release is already active or requires operator reconciliation');
  const fd=fs.openSync(file,'wx',0o600);
  try {
    fs.writeSync(fd,JSON.stringify({project:PROJECT,pid:process.pid,started:new Date().toISOString()})+'\n');
    fs.fsyncSync(fd);
  } catch(error) {fs.closeSync(fd);fs.unlinkSync(file);throw error;}
  return () => {fs.closeSync(fd);fs.unlinkSync(file);};
}
function apply(config,deps={}) {
  // Acquire a shared release lock before inspecting the local deployment.
  const unlock=acquireLock(deps);
  try {
    const target=outputJournal(config.journal);
    const plan=inspectPlan(config,deps);
    const saved=createJournal(target,plan);
    // If a local tag was re-pointed after plan inspection, refuse before
    // touching application containers. Preserve PREPARED-only journal evidence.
    verifyFormerTag(plan,deps);
    verifyCandidateTag(plan,deps);
    journal(saved,'RELEASE_STARTED');
    try {
      composeUp(config.tag,deps);
      verifyRunning(plan.candidate_ref,plan.candidate_id,deps);
      verifyProtectedServices(plan,deps);
      verifyFormerTag(plan,deps);
      verifyCandidateTag(plan,deps);
      probePostDeploy(plan,deps);
      verifyFormerTag(plan,deps);
      verifyCandidateTag(plan,deps);
      journal(saved,'RELEASE_VERIFIED',{service_count:3,private_http_routes_checked:true,production_accepted:false});
      return {ok:true,rollback_verified:false,plan};
    } catch {
      journal(saved,'RELEASE_UNCERTAIN_ROLLBACK_STARTED');
      // Refuse to roll back to a mutable tag that has been re-pointed.
      try {
        verifyFormerTag(plan,deps);
        composeUp(plan.old_ref.split(':').slice(1).join(':'),deps);
        verifyRunning(plan.old_ref,plan.old_id,deps);
        verifyProtectedServices(plan,deps);
        probePostDeploy(plan,deps);
        verifyFormerTag(plan,deps);
        journal(saved,'ROLLBACK_VERIFIED',{private_http_routes_checked:true,production_accepted:false});
        return {ok:false,rollback_verified:true,plan};
      } catch {
        journal(saved,'OPERATOR_RECONCILIATION_REQUIRED',{production_accepted:false});
        return {ok:false,rollback_verified:false,plan};
      }
    }
  } finally {unlock();}
}

function main(args=process.argv.slice(2),deps={}) {
  try {
    const config=parseArgs(args);
    // No actual deployments are performed in plan mode.
    if(config.mode==='plan') {
      const p=inspectPlan(config,deps);
      process.stdout.write(JSON.stringify({status:'READ_ONLY_STAGING_PLAN',...p},null,2)+'\n');
      return 0;
    }
    const outcome=apply(config,deps);
    process.stdout.write(outcome.ok?
      'PASS: staged app/worker/scheduler release verified; production acceptance NOT granted.\n' :
      outcome.rollback_verified?
        'FAIL: staging candidate did not pass; previous app image rollback verified. Inspect private journal.\n' :
        'FAIL: staging release uncertain; operator reconciliation required. Inspect private journal, do not touch database or issue real commands.\n');
    return outcome.ok?0:outcome.rollback_verified?1:3;
  }catch{
    process.stderr.write('FAIL: staging-only release preflight, approval or journal invalid; no production endpoint permitted.\n');
    return 2;
  }
}
if(require.main===module)process.exitCode=main();
module.exports={parseArgs,assertLocalSafety,inspectImage,inspectPlan,outputJournal,createJournal,journal,acquireLock,reviewIsolatedConfig,reviewEffectiveCompose,composeUp,verifyRunning,verifyProtectedServices,verifyCandidateTag,verifyFormerTag,probePostDeploy,apply,main};
