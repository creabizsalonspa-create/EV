#!/usr/bin/env node
'use strict';
// Independent, offline source/CI metadata gate for a *merged integration branch*.
// It does not authenticate downloaded JSON or grant production release approval.
const assert = require('node:assert/strict');
const { readCoverageFile } = require('./agent10-acceptance-audit.cjs');
const { verifySnapshot } = require('./agent10-ci-source-gate.cjs');
const BRANCH='development/fullstack-continuation';
const REPOSITORY='creabizsalonspa-create/EV';
const SHA=/^[0-9a-f]{40}$/;

function verifyIntegratedEvidence(evidence, expectedSha) {
  assert.ok(typeof expectedSha === 'string' && SHA.test(expectedSha),
    'A 40-hex exact integrated Git SHA is required');
  assert.equal(evidence?.schema, 'ev-agent10-integrated-evidence-v1',
    'Unexpected post-merge evidence envelope');
  for(const key of ['production_ready','customer_delivery_ready','production_accepted','store_signed']) {
    if (Object.hasOwn(evidence,key))
      assert.equal(evidence[key],false,'Post-merge envelope cannot claim live/release acceptance');
  }
  const { branch, ci }=evidence;
  assert.ok(branch && typeof branch === 'object', 'Missing integration branch observation');
  assert.equal(branch.name, BRANCH, 'Not the canonical integration branch');
  assert.equal(branch.commit?.sha, expectedSha,
    'Branch has moved or observed SHA does not match requested integration');
  assert.equal(ci?.platform?.run?.head_sha, expectedSha, 'Platform source does not match integration');
  assert.equal(ci?.native?.run?.head_sha, expectedSha, 'Native source does not match integration');
  for (const label of ['platform','native']) {
    const run=ci[label].run;
    assert.equal(run.event, 'push', 'Post-merge ' + label + ' workflow must be triggered by push');
    assert.equal(run.head_branch, BRANCH, 'Post-merge ' + label + ' workflow is on wrong branch');
    assert.equal(run.repository?.full_name, REPOSITORY,
      'Post-merge ' + label + ' workflow is in the wrong repository');
  }
  const accepted=verifySnapshot(ci, expectedSha);
  return {
    ...accepted,
    schema:'ev-agent10-integrated-evidence-result-v1',
    evidence_class:'OFFLINE_EXACT_INTEGRATED_SOURCE_CI_METADATA_NOT_LIVE_ACCEPTANCE',
    integrated_branch:BRANCH,
    integrated_sha:expectedSha,
    expected_run_events:'push',
    integrated_ci_metadata_consistent:true,
    external_approvals_verified:false,
    signed_distribution_verified:false,
    customer_delivery_ready:false,
    production_ready:false,
  };
}

if (require.main===module) {
  try {
    const [file,sha,...other]=process.argv.slice(2);
    assert.ok(file && sha && !other.length,
      'Usage: node scripts/agent10-postmerge-gate.cjs PRIVATE_INTEGRATION.json EXACT_INTEGRATION_SHA');
    console.log(JSON.stringify(verifyIntegratedEvidence(readCoverageFile(file),sha),null,2));
  } catch(error) {
    console.error('NOT_VERIFIED: exact post-merge CI evidence:',error.message);
    process.exitCode=1;
  }
}
module.exports={verifyIntegratedEvidence,BRANCH};
