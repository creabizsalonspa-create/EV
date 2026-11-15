#!/usr/bin/env node
'use strict';
// Read-only, offline CI *metadata* consistency gate. Not a live/staging or release attestation.
const assert = require('node:assert/strict');
const { readCoverageFile } = require('./agent10-acceptance-audit.cjs');

const REPO = 'creabizsalonspa-create/EV';
const SHA40 = /^[0-9a-f]{40}$/;
const WORKFLOWS = Object.freeze({
  platform: {
    name: 'Platform validation', workflow_id: 397762279,
    path: '.github/workflows/ci.yml',
    jobs: ['business-platform', 'finance-postgres', 'connector-postgres',
      'staging-image', 'csms-recovery', 'recovery-acceptance'],
  },
  native: {
    name: 'Native client validation', workflow_id: 397853920,
    path: '.github/workflows/mobile.yml',
    jobs: ['native (ubuntu-latest)', 'native (macos-15)'],
  },
});

function safeInteger(value) {
  return Number.isSafeInteger(value) && value > 0;
}

function requiredStep(job, label) {
  assert.ok(Array.isArray(job.steps), 'CI job lacks observable steps: ' + job.name);
  const hits = job.steps.filter(step => step?.name === label);
  assert.equal(hits.length, 1, 'Missing/duplicate required CI step: ' + job.name + ' / ' + label);
  assert.equal(hits[0].status, 'completed', 'Required CI step not complete: ' + label);
  assert.equal(hits[0].conclusion, 'success', 'Required CI step not successful: ' + label);
}

function verifyWorkflow(key, group, sourceSha) {
  const spec = WORKFLOWS[key];
  assert.ok(group && typeof group === 'object', 'Missing ' + key + ' workflow');
  const {run, jobs} = group;
  assert.ok(run && typeof run === 'object', 'Missing ' + key + ' run');
  assert.ok(safeInteger(run.id) && safeInteger(run.workflow_id) &&
    safeInteger(run.run_attempt), 'Invalid run identity/attempt');
  assert.equal(run.name, spec.name, 'Wrong reviewed workflow name');
  assert.equal(run.workflow_id, spec.workflow_id, 'Wrong reviewed workflow ID');
  assert.equal(run.path, spec.path, 'Wrong workflow source path');
  assert.equal(run.head_sha, sourceSha, 'CI source does not match exact target');
  assert.ok(['push','pull_request','workflow_dispatch'].includes(run.event), 'Unsafe workflow event');
  assert.ok(typeof run.head_branch === 'string' && run.head_branch.length > 0,
    'Missing workflow branch');
  assert.equal(run.status, 'completed', 'CI workflow has not completed');
  assert.equal(run.conclusion, 'success', 'CI workflow is not successful');
  assert.equal(run.repository?.full_name, REPO, 'Run belongs to wrong repository');
  assert.equal(run.head_repository?.full_name, REPO, 'Run source is not owned repository');
  assert.ok(safeInteger(run.repository?.id) &&
    run.head_repository?.id === run.repository?.id, 'Workflow repository identity invalid');
  assert.ok(Array.isArray(jobs), 'CI job listing incomplete');
  assert.equal(jobs.length, spec.jobs.length, 'Unexpected number of reviewed CI jobs');
  const byName = new Map(), byId = new Set();
  for (const job of jobs) {
    assert.ok(job && typeof job === 'object', 'Invalid CI job record');
    assert.ok(safeInteger(job.id) && !byId.has(job.id), 'Duplicate/invalid CI job ID');
    assert.equal(job.run_id, run.id, 'Job is from a different CI run');
    assert.ok(!byName.has(job.name), 'Duplicate CI job name');
    assert.equal(job.status, 'completed', 'Required CI job incomplete: ' + job.name);
    assert.equal(job.conclusion, 'success', 'Required CI job not successful: ' + job.name);
    byId.add(job.id);
    byName.set(job.name, job);
  }
  assert.deepEqual([...byName.keys()].sort(), spec.jobs.slice().sort(), 'Unreviewed/missing CI job');
  if (key === 'platform') {
    for (const step of [
      'Run npm run test:e2e',
      'Run npm run test:web-session',
      'Run npm run test:vendor',
      'Run npm run test:ocpp',
      'Run EV_ROAMING_VERIFY_RATE_LIMIT=1 npm run test:roaming',
      'Run npm run test:ocpi-provisioning',
    ]) requiredStep(byName.get('business-platform'), step);
    for (const step of [
      'Fetch exact-run Laravel recovery evidence',
      'Fetch exact-run PostGIS recovery evidence',
    ]) requiredStep(byName.get('recovery-acceptance'), step);
  } else {
    const android = byName.get('native (ubuntu-latest)');
    const ios = byName.get('native (macos-15)');
    requiredStep(android, 'Run flutter build apk --debug --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000 --dart-define=EV_LOCAL_DEMO=true');
    requiredStep(ios, 'Run flutter build ios --simulator --no-codesign --debug --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000 --dart-define=EV_LOCAL_DEMO=true');
    requiredStep(ios, 'Run native UI integration on iOS simulator (synthetic TLS fixtures)');
  }
  return {run_id:run.id,run_attempt:run.run_attempt,head_branch:run.head_branch,
    verified_job_names:spec.jobs.slice(),metadata_status:'ALL_REQUIRED_JOBS_SUCCESS'};
}

function verifySnapshot(snapshot, sourceSha) {
  assert.ok(typeof sourceSha === 'string' && SHA40.test(sourceSha), 'Exact Git source SHA required');
  assert.equal(snapshot?.schema,'ev-agent10-cross-workflow-ci-snapshot-v1','Unexpected CI evidence schema');
  for (const key of ['production_ready','customer_delivery_ready','production_accepted','store_signed']) {
    assert.notEqual(snapshot[key], true, 'Untrusted CI metadata claims live/release acceptance');
  }
  const platform = verifyWorkflow('platform', snapshot.platform, sourceSha);
  const native = verifyWorkflow('native', snapshot.native, sourceSha);
  assert.notEqual(platform.run_id, native.run_id, 'Platform/native evidence reused same run identity');
  assert.equal(platform.head_branch, native.head_branch, 'Platform/native source branches differ');
  return {
    schema:'ev-agent10-ci-metadata-result-v1',
    evidence_class:'OFFLINE_GITHUB_API_METADATA_CONSISTENCY_NOT_AUTHENTICATED_RELEASE',
    source_sha:sourceSha, platform, native,
    exact_source_workflow_metadata_consistent:true,
    signed_android_release_verified:false,
    signed_ios_release_verified:false,
    authenticated_staging_verified:false,
    external_payment_hardware_partner_verified:false,
    customer_delivery_ready:false,
    production_ready:false,
  };
}

if (require.main === module) {
  try {
    const file = process.argv[2], sha = process.argv[3];
    assert.ok(file && sha && process.argv.length === 4,
      'Usage: node scripts/agent10-ci-source-gate.cjs PRIVATE_SNAPSHOT.json EXACT_GIT_SHA');
    const result = verifySnapshot(readCoverageFile(file), sha);
    console.log(JSON.stringify(result, null, 2));
  } catch (error) {
    console.error('NOT_VERIFIED: cross-workflow CI metadata gate failed:', error.message);
    process.exitCode = 1;
  }
}
module.exports = {verifySnapshot,verifyWorkflow,requiredStep,WORKFLOWS};
