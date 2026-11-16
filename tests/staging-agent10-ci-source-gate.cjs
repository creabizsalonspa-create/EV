'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {verifySnapshot,WORKFLOWS}=require('../scripts/agent10-ci-source-gate.cjs');
const SHA='a'.repeat(40), ID=1234567890, REPO='creabizsalonspa-create/EV';
const names={
 'business-platform':[
 'Run ./scripts/php vendor/bin/pint --test','Check reproducible42-category coverage',
 'Run ./scripts/php artisan test --compact','Run npm run test:frontend','Run npm run test:privacy',
 'Verify every discovery browser suite within the production catalog budget','Run npm run test:localization',
 'Run node tests/charging-client.cjs','Financial browser workflows','Paced financial history and charger operational regressions',
 'Run npm run test:e2e','Run npm run test:web-session','Run npm run test:vendor',
 'Run npm run test:ocpp','Run EV_ROAMING_VERIFY_RATE_LIMIT=1 npm run test:roaming',
 'Run npm run test:ocpi-provisioning'],
 'staging-image':['Verify private staging and synthetic demo regressions without Docker','Build pinned staging runtime and verify offline image boundaries','Verify real disposable PostgreSQL dump and independent Laravel restore'],
 'finance-postgres':['Run services/business-api/tests/Postgres/run-finance'],
 'connector-postgres':['Run services/business-api/tests/Postgres/run-connectors'],
 'csms-recovery':['Verify disposable pinned PostGIS data and recovery-point integrity'],
 'recovery-acceptance':['Reject stale, incomplete or forged acceptance claims','Fetch exact-run Laravel recovery evidence','Fetch exact-run PostGIS recovery evidence'],
 'native (ubuntu-latest)':['Validate native source and tests','Verify foreground-only native privacy configuration','Verify native PDF artifact security boundaries','Verify actual merged Android foreground location permissions','Verify native inventory against local Laravel','Run flutter build apk --debug --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000 --dart-define=EV_LOCAL_DEMO=true'],
 'native (macos-15)':['Validate native source and tests','Verify foreground-only native privacy configuration','Verify native PDF artifact security boundaries','Verify bundled iOS foreground privacy permissions and localizations','Verify simulator startup recovery guards','Run flutter build ios --simulator --no-codesign --debug --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000 --dart-define=EV_LOCAL_DEMO=true','Run native UI integration on iOS simulator (synthetic TLS fixtures)'],
};
function fixture() {
 const group=(key,index)=>{
   const meta=WORKFLOWS[key], id=ID+index*100;
   return {run:{id,name:meta.name,workflow_id:meta.workflow_id,path:meta.path,run_attempt:1,status:'completed',conclusion:'success',head_sha:SHA,head_branch:'development/fullstack-continuation',event:'push',repository:{id:1234,full_name:REPO},head_repository:{id:1234,full_name:REPO}},
    jobs:meta.jobs.map((name,i)=>({id:id+i+1,run_id:id,name,status:'completed',conclusion:'success',steps:(names[name]||[]).map((step,j)=>({name:step,status:'completed',conclusion:'success',number:j+1}))}))};
 };
 return {schema:'ev-agent10-cross-workflow-ci-snapshot-v1',platform:group('platform',0),native:group('native',1)};
}
const wrong=f=>{const x=fixture();f(x);return x;};
test('independent metadata evidence is source matched but NEVER certifies customer delivery',()=>{
 const r=verifySnapshot(fixture(),SHA);
 assert.equal(r.exact_source_workflow_metadata_consistent,true);
 for(const key of ['customer_delivery_ready','production_ready','signed_android_release_verified','signed_ios_release_verified','authenticated_staging_verified'])assert.equal(r[key],false);
 assert.equal(r.platform.verified_job_names.length,6);
 assert.equal(r.native.verified_job_names.length,2);
});
test('wrong source, branch, workflow ID/path/name or source repository fails closed',()=>{
 for(const mutate of [
   s=>s.native.run.head_sha='b'.repeat(40),
   s=>s.platform.run.head_branch='wrong-branch',
   s=>s.native.run.workflow_id=77,
   s=>s.native.run.path='.github/workflows/other.yml',
   s=>s.platform.run.name='Other workflow',
   s=>s.platform.run.head_repository.full_name='untrusted/fork',
   s=>s.platform.run.repository.id=99,
 ])assert.throws(()=>verifySnapshot(wrong(mutate),SHA));
});
test('canceled, queued, skipped, failed and unlisted mandatory jobs must not pass',()=>{
 for(const mutate of [
   s=>{s.platform.run.conclusion='failure';},
   s=>{s.platform.run.status='in_progress';},
   s=>{s.platform.jobs[0].conclusion='skipped';},
   s=>{s.native.jobs[1].status='queued';},
   s=>{s.platform.jobs.pop();},
   s=>{s.platform.jobs[0].name='unreviewed';},
   s=>{s.platform.jobs[1].id=s.platform.jobs[0].id;},
   s=>{s.native.jobs[0].run_id=99;},
 ])assert.throws(()=>verifySnapshot(wrong(mutate),SHA));
});
test('mandatory test steps cannot be skipped, omitted or falsely passed',()=>{
 for(const mutate of [
   s=>{s.platform.jobs[0].steps[0].conclusion='skipped';},
   s=>{s.platform.jobs[0].steps.find(x=>x.name==='Financial browser workflows').conclusion='skipped';},
   s=>{s.platform.jobs[3].steps[0].conclusion='skipped';},
   s=>{s.platform.jobs[4].steps[0].conclusion='failure';},
   s=>{s.platform.jobs[0].steps[0].status='in_progress';},
   s=>{s.platform.jobs[0].steps.pop();},
   s=>{s.platform.jobs[0].steps.push(s.platform.jobs[0].steps[0]);},
   s=>{s.platform.jobs[5].steps=[];},
   s=>{s.native.jobs[0].steps[0].conclusion='skipped';},
   s=>{s.native.jobs[0].steps.find(x=>x.name==='Verify actual merged Android foreground location permissions').conclusion='skipped';},
   s=>{s.native.jobs[1].steps.find(x=>x.name==='Verify bundled iOS foreground privacy permissions and localizations').conclusion='skipped';},
   s=>{s.native.jobs[1].steps[1].conclusion='failure';},
 ])assert.throws(()=>verifySnapshot(wrong(mutate),SHA));
});
test('unknown release/production declarations and mixed workflow identities fail',()=>{
 for(const mutate of [
   s=>s.production_ready=true,
   s=>s.production_accepted=true,
   s=>s.store_signed=true,
   s=>s.production_ready='true',
   s=>s.customer_delivery_ready=1,
   s=>s.production_accepted='yes',
   s=>s.store_signed=null,
   s=>s.native.run.id=s.platform.run.id,
   s=>s.native.run.run_attempt=0,
   s=>s.native.run.event='pull_request_target',
   s=>s.native.run.event='pull_request',
   s=>{s.platform.run.head_branch='feature';s.native.run.head_branch='feature';},
 ])assert.throws(()=>verifySnapshot(wrong(mutate),SHA));
});
test('source SHA format and snapshot schema are mandatory',()=>{
 assert.throws(()=>verifySnapshot(fixture(),'garbage'));
 assert.throws(()=>verifySnapshot({schema:'unknown'},SHA));
});
