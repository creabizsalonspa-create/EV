'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { audit, externalGates } = require('../scripts/agent10-acceptance-audit.cjs');

function fixture() {
  return {
    category_count: 2, atom_count: 3,
    weighted_percent: { coded: '75.00', tested: '50.00', merged: '50.00', production_accepted: '0.00' },
    categories: [
      { prd: '2.1', atoms: 2, counts: { coded: 1, tested: 0, merged: 0, production_accepted: 0 }, details: [
        { id: '2.1.1', stages: { coded: true, tested: false, merged: false, production_accepted: false } },
        { id: '2.1.2', stages: { coded: false, tested: false, merged: false, production_accepted: false } },
      ] },
      { prd: '2.2', atoms: 1, counts: { coded: 1, tested: 1, merged: 1, production_accepted: 0 }, details: [
        { id: '2.2.1', stages: { coded: true, tested: true, merged: true, production_accepted: false } },
      ] },
    ],
  };
}
const limits = { categories: 2, atoms: 3 };

test('independently classifies software gaps and keeps all external gates unverified', () => {
  const result = audit(fixture(), limits);
  assert.equal(result.raw_stage_counts.coded, 2);
  assert.deepEqual(result.software_gaps.not_coded, ['2.1.2']);
  assert.deepEqual(result.software_gaps.coded_not_tested, ['2.1.1']);
  assert.equal(result.customer_delivery_ready, false);
  assert.equal(result.production_ready, false);
  assert.ok(externalGates.length >= 8);
  assert.ok(Object.values(result.external_acceptance_gates).every(v => v === 'NOT_VERIFIED_BY_OFFLINE_AUDIT'));
});

test('rejects forged weighted completion claims', () => {
  const altered = fixture(); altered.weighted_percent.merged = '100.00';
  assert.throws(() => audit(altered, limits), /Weighted merged misrepresented/);
});

test('rejects acceptance without a merged and tested prerequisite', () => {
  const altered = fixture(); altered.categories[0].details[0].stages.production_accepted = true;
  assert.throws(() => audit(altered, limits), /Stage precedes prerequisite/);
});

test('rejects missing, duplicate, and wrong-category original atoms', () => {
  const dropped = fixture(); dropped.categories[0].details.pop();
  assert.throws(() => audit(dropped, limits), /Missing category atoms/);
  const duplicated = fixture(); duplicated.categories[0].details[1].id = '2.1.1';
  assert.throws(() => audit(duplicated, limits), /Duplicate atom identifier/);
  const moved = fixture(); moved.categories[0].details[1].id = '3.1.2';
  assert.throws(() => audit(moved, limits), /Atom category mismatch/);
});

test('rejects unsupported self-declared category stage counts', () => {
  const altered = fixture(); altered.categories[1].counts.tested = 0;
  assert.throws(() => audit(altered, limits), /Incorrect 2.2 count: tested/);
});

test('canonical fixed 42/312 inventory independently cross-checks without release certification', () => {
  const root = path.resolve(__dirname, '..');
  const coverage = JSON.parse(fs.readFileSync(path.join(root, 'docs/evidence/requirements/coverage.json'), 'utf8'));
  const result = audit(coverage);
  assert.equal(result.original_categories, 42);
  assert.equal(result.original_atoms, 312);
  assert.equal(result.raw_stage_counts.coded, 186);
  assert.equal(result.raw_stage_counts.tested, 185);
  assert.equal(result.raw_stage_counts.merged, 185);
  assert.equal(result.customer_delivery_ready, false);
  assert.equal(result.live_ci_verified, false);
});
