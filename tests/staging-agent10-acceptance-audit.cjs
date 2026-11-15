'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { audit, externalGates, originalCategoryCounts, readCoverageFile } = require('../scripts/agent10-acceptance-audit.cjs');

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
  assert.throws(() => audit(altered, limits), /Production acceptance requires separately verified live evidence/);
});

test('rejects missing, duplicate, and wrong-category original atoms', () => {
  const dropped = fixture(); dropped.categories[0].details.pop();
  assert.throws(() => audit(dropped, limits), /Missing category atoms/);
  const duplicated = fixture(); duplicated.categories[0].details[1].id = '2.1.1';
  assert.throws(() => audit(duplicated, limits), /Original atom IDs\/order changed/);
  const moved = fixture(); moved.categories[0].details[1].id = '3.1.2';
  assert.throws(() => audit(moved, limits), /Atom category mismatch/);
});

test('rejects unsupported self-declared category stage counts', () => {
  const altered = fixture(); altered.categories[1].counts.tested = 0;
  assert.throws(() => audit(altered, limits), /Incorrect 2.2 count: tested/);
});

function originalZeroEvidence() {
  const categories = Object.entries(originalCategoryCounts).map(([prd, atoms]) => ({
    prd, atoms,
    counts: { coded: 0, tested: 0, merged: 0, production_accepted: 0 },
    details: Array.from({ length: atoms }, (_, i) => ({
      id: prd + '.' + (i + 1), coded: false, checkpoint: null,
      stages: { coded: false, tested: false, merged: false, production_accepted: false },
    })),
  }));
  return {
    category_count: 42, atom_count: 312, categories, checkpoints: {},
    weighted_percent: { coded: '0.00', tested: '0.00', merged: '0.00', production_accepted: '0.00' },
  };
}

test('42-category original denominators cannot be redistributed while retaining 312 atoms', () => {
  const original = originalZeroEvidence();
  assert.equal(audit(original).original_atoms, 312);
  const moved = originalZeroEvidence();
  const first = moved.categories[0], second = moved.categories[1];
  first.details.pop(); first.atoms -= 1;
  second.atoms += 1;
  second.details.push({ id: second.prd + '.' + second.atoms, coded: false, checkpoint: null,
    stages: { coded: false, tested: false, merged: false, production_accepted: false } });
  assert.throws(() => audit(moved), /Original category denominator changed/);
});

test('original category IDs and atom positions cannot be substituted', () => {
  const changed = originalZeroEvidence();
  changed.categories[0].prd = '2.99';
  assert.throws(() => audit(changed), /Original category denominator changed/);
  const duplicate = originalZeroEvidence();
  duplicate.categories[1].prd = duplicate.categories[0].prd;
  assert.throws(() => audit(duplicate), /Duplicate requirement category/);
  const reordered = originalZeroEvidence();
  [reordered.categories[0].details[0], reordered.categories[0].details[1]] =
    [reordered.categories[0].details[1], reordered.categories[0].details[0]];
  assert.throws(() => audit(reordered), /Original atom IDs\/order changed/);
});

test('generated stage flags must match implementation and checkpoint status', () => {
  const contradiction = originalZeroEvidence();
  contradiction.categories[0].details[0].stages.coded = true;
  assert.throws(() => audit(contradiction), /Software implementation flag contradicts stage/);
  const phantom = originalZeroEvidence();
  phantom.categories[0].details[0].coded = true;
  phantom.categories[0].details[0].stages.coded = true;
  phantom.categories[0].details[0].checkpoint = 'missing-checkpoint';
  assert.throws(() => audit(phantom), /Unknown checkpoint source/);
  const falsePass = originalZeroEvidence();
  falsePass.checkpoints.preliminary = { test_result: 'pending', merge_sha: null };
  falsePass.categories[0].details[0].coded = true;
  falsePass.categories[0].details[0].checkpoint = 'preliminary';
  falsePass.categories[0].details[0].stages.coded = true;
  falsePass.categories[0].details[0].stages.tested = true;
  assert.throws(() => audit(falsePass), /Test stage contradicts checkpoint/);
  const fakeMerge = originalZeroEvidence();
  fakeMerge.checkpoints.passed = { test_result: 'passed', merge_sha: null };
  fakeMerge.categories[0].details[0].coded = true;
  fakeMerge.categories[0].details[0].checkpoint = 'passed';
  fakeMerge.categories[0].details[0].stages.coded = true;
  fakeMerge.categories[0].details[0].stages.tested = true;
  fakeMerge.categories[0].details[0].stages.merged = true;
  assert.throws(() => audit(fakeMerge), /Merge stage contradicts checkpoint/);
});

test('canonical fixed 42/312 inventory independently cross-checks without release certification', () => {
  const root = path.resolve(__dirname, '..');
  const coverage = JSON.parse(fs.readFileSync(path.join(root, 'docs/evidence/requirements/coverage.json'), 'utf8'));
  const result = audit(coverage);
  assert.equal(result.original_categories, 42);
  assert.equal(result.original_atoms, 312);
  for (const stage of ['coded', 'tested', 'merged', 'production_accepted']) {
    const computed = coverage.categories.reduce((sum, category) => sum + category.counts[stage], 0);
    assert.equal(result.raw_stage_counts[stage], computed);
  }
  assert.equal(result.customer_delivery_ready, false);
  assert.equal(result.live_ci_verified, false);
});

test('read-only input uses one nonfollowing bounded descriptor, never a symbolic or hard link', () => {
  const os = require('node:os');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ev-qa-file-'));
  try {
    const candidate = path.join(directory, 'coverage.json');
    fs.writeFileSync(candidate, JSON.stringify(fixture()));
    assert.equal(readCoverageFile(candidate).atom_count, 3);
    const alias = path.join(directory, 'symlink.json');
    fs.symlinkSync(candidate, alias);
    assert.throws(() => readCoverageFile(alias));
    const hardlink = path.join(directory, 'hardlink.json');
    fs.linkSync(candidate, hardlink);
    assert.throws(() => readCoverageFile(candidate), /bounded regular file/);
    fs.unlinkSync(hardlink);
    fs.truncateSync(candidate, 0);
    assert.throws(() => readCoverageFile(candidate), /bounded regular file/);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('reject oversized source snapshots and malformed JSON without writing output', () => {
  const os = require('node:os');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ev-qa-bounded-'));
  try {
    const candidate = path.join(directory, 'coverage.json');
    fs.writeFileSync(candidate, '{bad json');
    assert.throws(() => readCoverageFile(candidate), /JSON/);
    fs.truncateSync(candidate, 2_000_001);
    assert.throws(() => readCoverageFile(candidate), /bounded regular file/);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
