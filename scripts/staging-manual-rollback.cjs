#!/usr/bin/env node
'use strict';
// Recovery after an interrupted or superseded staging app release.
// Changes only private staging application/worker/scheduler after explicit operator approval.
// Never touches database/gateway volumes, migrations, hardware, payments or production.
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { parseSnapshot, localDockerSnapshot } = require('./staging-runtime-gate.cjs');
const { verifyFormerTag, acquireLock, reviewIsolatedConfig, reviewEffectiveCompose, composeUp, verifyRunning, probePostDeploy, journal } = require('./staging-release.cjs');

const SHA=/^[a-f0-9]{40}$/;
const ID=/^sha256:[a-f0-9]{64}$/;
const REF=/^creabiz-ev-business:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/;
const SERVICES=['application','worker','scheduler','database','gateway'];
// A journal is authorization evidence, not a bag of independent event names.
// Refuse impossible success, rollback, or recovery histories even when owner-only.
const NEXT=Object.freeze({
  PREPARED:['RELEASE_STARTED'],
  RELEASE_STARTED:['RELEASE_VERIFIED','RELEASE_UNCERTAIN_ROLLBACK_STARTED','MANUAL_ROLLBACK_STARTED'],
  RELEASE_VERIFIED:['MANUAL_ROLLBACK_STARTED'],
  RELEASE_UNCERTAIN_ROLLBACK_STARTED:['ROLLBACK_VERIFIED','OPERATOR_RECONCILIATION_REQUIRED','MANUAL_ROLLBACK_STARTED'],
  ROLLBACK_VERIFIED:[],
  OPERATOR_RECONCILIATION_REQUIRED:['MANUAL_ROLLBACK_STARTED'],
  MANUAL_ROLLBACK_STARTED:['MANUAL_ROLLBACK_VERIFIED','OPERATOR_RECONCILIATION_REQUIRED','MANUAL_ROLLBACK_STARTED'],
  MANUAL_ROLLBACK_VERIFIED:[]
});

function parseArgs(args) {
  if(args.length!==5 || args[0]!=='rollback' || args[1]!=='--journal' ||
    !args[2] || args[2].startsWith('--') || args[3]!=='--approval' || !args[4]) {
    throw Error('Usage: rollback --journal PRIVATE.jsonl --approval ROLLBACK_PRIVATE_STAGING_SHA12');
  }
  return {file:args[2],approval:args[4]};
}

function loadJournal(file) {
  const real=path.resolve(file);
  const st=fs.lstatSync(real), parent=fs.lstatSync(path.dirname(real));
  if(!st.isFile() || st.isSymbolicLink() || st.size===0 || st.size>128*1024 ||
    !parent.isDirectory() || parent.isSymbolicLink() ||
    (process.platform!=='win32' && ((st.mode&0o077)!==0 || (parent.mode&0o077)!==0))) {
    throw Error('Private staging release journal invalid');
  }
  const lines=fs.readFileSync(real,'utf8').trim().split('\n');
  if(lines.length<2 || lines.length>60)throw Error('Incomplete release journal');
  let events;
  try {events=lines.map(line=>JSON.parse(line));} catch {throw Error('Invalid release journal JSON');}
  if(events.some(x=>!x || typeof x!=='object' || typeof x.event!=='string')) throw Error('Invalid journal events');
  const prepared=events[0], p=prepared.plan;
  if(prepared.event!=='PREPARED' || !p || p.project!=='ev-staging' ||
     !SHA.test(p.source_sha) || !SHA.test(p.old_source_sha||'') ||
     !REF.test(p.old_ref) || !REF.test(p.candidate_ref) ||
     p.old_ref===p.candidate_ref || !ID.test(p.old_id) || !ID.test(p.candidate_id) ||
     p.old_id===p.candidate_id || p.database_and_gateway_unchanged!==true ||
     !p.protected_services || !/^[a-f0-9]{12,64}$/.test(p.protected_services.database||'') ||
     !/^[a-f0-9]{12,64}$/.test(p.protected_services.gateway||'') ||
     p.protected_services.database===p.protected_services.gateway ||
     p.automatic_migrations!==false || p.external_services_or_payments_invoked!==false ||
     JSON.stringify(p.roles)!==JSON.stringify(['application','worker','scheduler'])) {
    throw Error('Release provenance or non-destructive recovery constraints invalid');
  }
  // Every recoverable history starts a real release, then moves only through
  // states emitted by staging-release or a deliberate manual rollback.
  // In particular PREPARED->ROLLBACK_VERIFIED and standalone MANUAL_* success
  // must never authorize a potentially mutating recovery.
  for(let i=1;i<events.length;i++) {
    if(!NEXT[events[i-1].event]?.includes(events[i].event)) {
      throw Error('Impossible staging release/rollback journal transition');
    }
  }
  return {file:real,plan:p,events};
}

function recoverySnapshot(rows,plan) {
  if(!Array.isArray(rows) || rows.length!==5) throw Error('Unexpected staging project inventory');
  const seen=new Set();
  for(const c of rows) {
    if(!c || c.Project!=='ev-staging' || !SERVICES.includes(c.Service) || seen.has(c.Service)) {
      throw Error('Staging project has unexpected or duplicate service');
    }
    seen.add(c.Service);
    if(['database','gateway'].includes(c.Service) && c.ID!==plan.protected_services[c.Service]) {
      throw Error('A protected staging service was replaced after the original release');
    }
    if(['application','worker','scheduler'].includes(c.Service) &&
      ![plan.old_ref,plan.candidate_ref].includes(c.Image)) {
      throw Error('Another deployment owns the current application');
    }
    if(c.Service==='database' && (c.State!=='running' || c.Health!=='healthy')) {
      throw Error('Database unavailable for safe application rollback');
    }
    if(c.Service==='gateway' && c.State!=='running')throw Error('Gateway unavailable for staging rollback');
    const published=(c.Publishers||[]).filter(x=>x && Number(x.PublishedPort)>0);
    if(c.Service==='gateway') {
      if(published.length!==1 || published[0].URL!=='127.0.0.1' ||
         Number(published[0].PublishedPort)!==8443 ||
         Number(published[0].TargetPort)!==443 || published[0].Protocol!=='tcp') {
        throw Error('Staging gateway is not loopback-only');
      }
    } else if(published.length) {
      throw Error('Private staging service is unexpectedly publicly bound');
    }
  }
  return true;
}

function rollback(config,deps={}) {
  const record=loadJournal(config.file);
  const p=record.plan;
  if(config.approval!=='ROLLBACK_PRIVATE_STAGING_'+p.source_sha.slice(0,12)) {
    throw Error('Explicit staging-only rollback approval missing');
  }
  // A recovery operation must not reactivate live partner, payment or charger wiring.
  reviewIsolatedConfig(deps.envFile||path.resolve(__dirname,'../infra/staging/.env'),deps);
  reviewEffectiveCompose(deps);
  const unlock=acquireLock(deps);
  try {
    const run=deps.run||execFileSync;
    const rows=parseSnapshot(localDockerSnapshot(deps.env||process.env,run));
    recoverySnapshot(rows,p);
    verifyFormerTag(p,deps);
    // A previously verified rollback still needs read-only confirmation of current state.
    if(['ROLLBACK_VERIFIED','MANUAL_ROLLBACK_VERIFIED'].includes(record.events.at(-1).event)) {
      verifyRunning(p.old_ref,p.old_id,deps);
      // Verify the originally observed database and gateway are still running.
      const current=parseSnapshot(localDockerSnapshot(deps.env||process.env,run));
      recoverySnapshot(current,p);
      probePostDeploy(p,deps);
      verifyRunning(p.old_ref,p.old_id,deps);
      recoverySnapshot(parseSnapshot(localDockerSnapshot(deps.env||process.env,run)),p);
      verifyFormerTag(p,deps);
      return {ok:true,changed:false};
    }
    // Preserve a fail-closed journal even if a concurrent operator retargeted the
    // former app tag. Never run an unreviewed image during recovery.
    verifyFormerTag(p,deps);
    journal(record.file,'MANUAL_ROLLBACK_STARTED');
    try {
      composeUp(p.old_ref.slice('creabiz-ev-business:'.length),deps);
      verifyRunning(p.old_ref,p.old_id,deps);
      const current=parseSnapshot(localDockerSnapshot(deps.env||process.env,run));
      recoverySnapshot(current,p);
      probePostDeploy(p,deps);
      verifyRunning(p.old_ref,p.old_id,deps);
      recoverySnapshot(parseSnapshot(localDockerSnapshot(deps.env||process.env,run)),p);
      verifyFormerTag(p,deps);
      journal(record.file,'MANUAL_ROLLBACK_VERIFIED',{private_http_routes_checked:true,production_accepted:false});
      return {ok:true,changed:true};
    } catch {
      journal(record.file,'OPERATOR_RECONCILIATION_REQUIRED',{production_accepted:false});
      return {ok:false,changed:true};
    }
  }finally{unlock();}
}
function main(args=process.argv.slice(2),deps={}) {
  try {
    const value=rollback(parseArgs(args),deps);
    process.stdout.write(value.ok?
      'PASS: prior staging application image verified after rollback. Database/gateway untouched; production acceptance NOT granted.\n':
      'FAIL: manual staging recovery needs operator reconciliation. Inspect private release journal.\n');
    return value.ok?0:3;
  }catch{
    process.stderr.write('FAIL: private staging manual rollback blocked by missing approval, unsafe state or provenance; no production action.\n');
    return 2;
  }
}
if(require.main===module)process.exitCode=main();
module.exports={parseArgs,loadJournal,recoverySnapshot,rollback,main};
