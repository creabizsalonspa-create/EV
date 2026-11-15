'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {verifyIntegratedEvidence,BRANCH}=require('../scripts/agent10-postmerge-gate.cjs');
const {WORKFLOWS}=require('../scripts/agent10-ci-source-gate.cjs');
const SHA='a'.repeat(40),OTHER='b'.repeat(40);
const REPO='creabizsalonspa-create/EV',ID=937001;
const steps={
 'business-platform':['Run npm run test:e2e','Run npm run test:web-session','Run npm run test:vendor','Run npm run test:ocpp','Run EV_ROAMING_VERIFY_RATE_LIMIT=1 npm run test:roaming','Run npm run test:ocpi-provisioning'],
 'recovery-acceptance':['Fetch exact-run Laravel recovery evidence','Fetch exact-run PostGIS recovery evidence'],
 'native (ubuntu-latest)':['Run flutter build apk --debug --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000 --dart-define=EV_LOCAL_DEMO=true'],
 'native (macos-15)':['Run flutter build ios --simulator --no-codesign --debug --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000 --dart-define=EV_LOCAL_DEMO=true','Run native UI integration on iOS simulator (synthetic TLS fixtures)'],
};
function fixture(){
  const run=(k,delta)=>{
    const s=WORKFLOWS[k],id=ID+delta;
    return {run:{id,run_attempt:1,name:s.name,workflow_id:s.workflow_id,path:s.path,status:'completed',conclusion:'success',event:'push',head_sha:SHA,head_branch:BRANCH,repository:{id:42,full_name:REPO},head_repository:{id:42,full_name:REPO}},jobs:s.jobs.map((name,i)=>({id:id+10+i,run_id:id,name,status:'completed',conclusion:'success',steps:(steps[name]||[]).map((step,j)=>({name:step,status:'completed',conclusion:'success',number:j+1}))}))};
  };
  return {schema:'ev-agent10-integrated-evidence-v1',branch:{name:BRANCH,commit:{sha:SHA}},ci:{schema:'ev-agent10-cross-workflow-ci-snapshot-v1',platform:run('platform',0),native:run('native',100)}};
}
function mutate(fn){const x=fixture();fn(x);return x;}
test('all source-matched post-merge push checks prove metadata consistency, never production approval',()=>{
  const x=verifyIntegratedEvidence(fixture(),SHA);
  assert.equal(x.schema,'ev-agent10-integrated-evidence-result-v1');
  assert.equal(x.evidence_class,'OFFLINE_EXACT_INTEGRATED_SOURCE_CI_METADATA_NOT_LIVE_ACCEPTANCE');
  assert.equal(x.integrated_sha,SHA);
  assert.equal(x.integrated_ci_metadata_consistent,true);
  for(const k of ['production_ready','customer_delivery_ready','signed_distribution_verified','external_approvals_verified'])assert.equal(x[k],false);
});
test('reject a PR green run, dispatch run, or unrelated branch even when all jobs pass',()=>{
  for(const change of [
    x=>{x.ci.platform.run.event='pull_request';},
    x=>{x.ci.native.run.event='workflow_dispatch';},
    x=>{x.ci.platform.run.head_branch='development/agent10';},
    x=>{x.ci.native.run.head_branch='development/agent10';},
    x=>{x.branch.name='development/platform-foundation';}
  ])assert.throws(()=>verifyIntegratedEvidence(mutate(change),SHA));
});
test('reject stale branch SHA, wrong requested SHA, or mismatched workflow source',()=>{
  for(const change of [x=>{x.branch.commit.sha=OTHER;},x=>{x.ci.native.run.head_sha=OTHER;},x=>{x.ci.platform.run.head_sha=OTHER;}])assert.throws(()=>verifyIntegratedEvidence(mutate(change),SHA));
  assert.throws(()=>verifyIntegratedEvidence(fixture(),OTHER));
  assert.throws(()=>verifyIntegratedEvidence(fixture(),'not-a-git-sha'));
});
test('reject failures, skipped recovery acceptance, queued jobs and incomplete builds',()=>{
  for(const change of [
    x=>{x.ci.platform.run.conclusion='failure';},
    x=>{x.ci.native.run.conclusion='failure';},
    x=>{x.ci.platform.jobs[0].conclusion='failure';},
    x=>{x.ci.platform.jobs.find(j=>j.name==='recovery-acceptance').conclusion='skipped';},
    x=>{x.ci.native.jobs[1].status='in_progress';},
    x=>{x.ci.native.jobs.find(j=>j.name==='native (macos-15)').steps[0].conclusion='skipped';},
  ])assert.throws(()=>verifyIntegratedEvidence(mutate(change),SHA));
});
test('reject invalid envelope and unfounded production claims',()=>{
  assert.throws(()=>verifyIntegratedEvidence({schema:'other'},SHA));
  for(const change of [x=>{x.ci.production_ready=true;},x=>{x.ci.customer_delivery_ready='true';},x=>{x.ci.store_signed=1;},x=>{x.production_ready=true;},x=>{x.store_signed='yes';}])assert.throws(()=>verifyIntegratedEvidence(mutate(change),SHA));
});
test('passing an older historical integration SHA cannot certify a newer merge commit',()=>{
  const historical='28701c08033b06c92515601f983f3b85bddb84ae';
  const latest='48f136d750689b3fccb93727748006428a3912bb';
  const old=mutate(x=>{x.branch.commit.sha=historical;x.ci.platform.run.head_sha=historical;x.ci.native.run.head_sha=historical;});
  assert.equal(verifyIntegratedEvidence(old,historical).integrated_ci_metadata_consistent,true);
  assert.throws(()=>verifyIntegratedEvidence(old,latest),/Branch has moved/);
});
