#!/usr/bin/env node
'use strict';
// Independent, read-only acceptance audit. No production or external verification.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const stages = ['coded', 'tested', 'merged', 'production_accepted'];
// Independently preserve the 42 original PRD categories and denominators.
// A correct grand total alone cannot stop an atom being moved between weights.
const originalCategoryCounts = Object.freeze(Object.fromEntries([
  ['2', [9,7,9,8,6,7,9,10,7,8]],
  ['3', [8,8,6,8,9,6,7,6]],
  ['4', [7,7,9,6,7,6]],
  ['5', [6,11,8,5]],
  ['6', [6,4,8,8,3]],
  ['7', [10,7,9,2]],
  ['8', [8]],
  ['9', [13,10,8,6]],
].flatMap(([section, counts]) => counts.map((count, index) => [section + '.' + (index + 1), count]))));
const externalGates = [
  'physical_ocpp_16_and_201_hardware',
  'authorized_live_ocpi_partner',
  'activated_psp_refund_and_bank_settlement',
  'approved_legal_tax_and_country_controls',
  'connected_staging_migration_backup_restore_rollback',
  'independent_security_accessibility_and_load_acceptance',
  'android_signed_release_and_store_approval',
  'ios_signed_release_and_store_approval',
];

function audit(report, expected = { categories: 42, atoms: 312 }) {
  assert.equal(report?.category_count, expected.categories, 'Category inventory changed');
  assert.equal(report?.atom_count, expected.atoms, 'Requirement inventory changed');
  assert.ok(Array.isArray(report.categories), 'Category rows missing');
  assert.equal(report.categories.length, expected.categories, 'Category rows incomplete');
  const ids = new Set();
  const seenCategories = new Set();
  const canonical = expected.categories === 42 && expected.atoms === 312;
  if (canonical) assert.equal(Object.keys(originalCategoryCounts).length, 42, 'QA category lock invalid');
  const raw = Object.fromEntries(stages.map(stage => [stage, 0]));
  const weighted = Object.fromEntries(stages.map(stage => [stage, 0]));
  const softwareGaps = { not_coded: [], coded_not_tested: [], tested_not_merged: [] };
  const unaccepted = [];
  for (const category of report.categories) {
    assert.ok(typeof category.prd === 'string' && /^\d+\.\d+$/.test(category.prd), 'Invalid category');
    assert.ok(!seenCategories.has(category.prd), 'Duplicate requirement category');
    seenCategories.add(category.prd);
    if (canonical) assert.equal(category.atoms, originalCategoryCounts[category.prd], 'Original category denominator changed');
    assert.ok(Number.isSafeInteger(category.atoms) && category.atoms > 0, 'Invalid category denominator');
    assert.ok(Array.isArray(category.details) && category.details.length === category.atoms, 'Missing category atoms');
    const perCategory = Object.fromEntries(stages.map(stage => [stage, 0]));
    for (const [indexInCategory, atom] of category.details.entries()) {
      assert.ok(typeof atom.id === 'string' && atom.id.startsWith(category.prd + '.'), 'Atom category mismatch');
      assert.equal(atom.id, category.prd + '.' + (indexInCategory + 1), 'Original atom IDs/order changed');
      assert.ok(!ids.has(atom.id), 'Duplicate atom identifier');
      ids.add(atom.id);
      assert.ok(atom.stages && typeof atom.stages === 'object', 'Atom stages missing');
      // Re-evaluate credited stages against the recorded implementation/checkpoint fields.
      // This is a metadata consistency check, not independent CI authentication.
      if (canonical) {
        assert.equal(typeof atom.coded, 'boolean', 'Software implementation flag missing');
        assert.equal(atom.stages.coded, atom.coded, 'Software implementation flag contradicts stage');
        const checkpoint = atom.checkpoint === null ? null : report.checkpoints?.[atom.checkpoint];
        if (atom.checkpoint !== null) assert.ok(checkpoint, 'Unknown checkpoint source');
        const tested = Boolean(atom.coded && checkpoint && checkpoint.test_result === 'passed');
        const merged = Boolean(tested && checkpoint.merge_sha);
        assert.equal(atom.stages.tested, tested, 'Test stage contradicts checkpoint');
        assert.equal(atom.stages.merged, merged, 'Merge stage contradicts checkpoint');
      }
      // The coverage JSON alone cannot authenticate independent live signoff.
      assert.equal(atom.stages.production_accepted, false, 'Production acceptance requires separately verified live evidence');
      for (let index = 0; index < stages.length; index++) {
        const stage = stages[index];
        assert.equal(typeof atom.stages[stage], 'boolean', 'Invalid ' + stage + ' stage');
        if (index > 0) assert.ok(!atom.stages[stage] || atom.stages[stages[index-1]], 'Stage precedes prerequisite');
        perCategory[stage] += Number(atom.stages[stage]);
      }
      if (!atom.stages.coded) softwareGaps.not_coded.push(atom.id);
      else if (!atom.stages.tested) softwareGaps.coded_not_tested.push(atom.id);
      else if (!atom.stages.merged) softwareGaps.tested_not_merged.push(atom.id);
      if (!atom.stages.production_accepted) unaccepted.push(atom.id);
    }
    for (const stage of stages) {
      assert.equal(category.counts?.[stage], perCategory[stage], 'Incorrect ' + category.prd + ' count: ' + stage);
      raw[stage] += perCategory[stage];
      weighted[stage] += perCategory[stage] / category.atoms / expected.categories;
    }
  }
  assert.equal(ids.size, expected.atoms, 'Original requirement atom count differs');
  if (canonical) assert.deepEqual([...seenCategories].sort(), Object.keys(originalCategoryCounts).sort(), 'Original category IDs changed');
  const percentages = {};
  for (const stage of stages) {
    percentages[stage] = (100 * weighted[stage]).toFixed(2);
    assert.equal(String(report.weighted_percent?.[stage]), percentages[stage], 'Weighted ' + stage + ' misrepresented');
  }
  return {
    schema: 'ev-agent10-independent-acceptance-audit-v1',
    evidence_class: 'OFFLINE_COVERAGE_CROSS_CHECK_NOT_RUNTIME_ATTESTATION',
    original_categories: ids.size ? expected.categories : 0,
    original_atoms: ids.size,
    raw_stage_counts: raw,
    weighted_percent: percentages,
    software_gaps: softwareGaps,
    atoms_without_production_acceptance: unaccepted,
    external_acceptance_gates: Object.fromEntries(externalGates.map(gate => [gate, 'NOT_VERIFIED_BY_OFFLINE_AUDIT'])),
    exact_git_sha_verified: false,
    live_ci_verified: false,
    signed_binary_provenance_verified: false,
    customer_delivery_ready: false,
    production_ready: false,
  };
}

function readCoverageFile(filename) {
  // A path-only lstat followed by readFileSync can read a *different* file
  // after a concurrent replacement. Pin the same nonfollowing descriptor.
  const flags = fs.constants.O_RDONLY | (fs.constants.O_NOFOLLOW || 0) |
    (fs.constants.O_NONBLOCK || 0);
  const fd = fs.openSync(filename, flags);
  try {
    const before = fs.fstatSync(fd, { bigint: true });
    assert.ok(before.isFile() && before.nlink === 1n && before.size > 0n &&
      before.size <= 2_000_000n, 'Coverage input must be a bounded regular file');
    const content = fs.readFileSync(fd, 'utf8');
    const after = fs.fstatSync(fd, { bigint: true });
    const visible = fs.lstatSync(filename, { bigint: true });
    const identity = stat => [stat.dev, stat.ino, stat.size, stat.mtimeNs,
      stat.ctimeNs, stat.mode, stat.nlink].map(String).join(':');
    assert.ok(visible.isFile() && !visible.isSymbolicLink(), 'Coverage path replaced');
    assert.equal(identity(before), identity(after), 'Coverage modified during read');
    assert.equal(identity(before), identity(visible), 'Coverage path replaced during read');
    assert.equal(Buffer.byteLength(content), Number(before.size), 'Coverage changed byte length');
    return JSON.parse(content);
  } finally {
    fs.closeSync(fd);
  }
}

if (require.main === module) {
  try {
    const root = path.resolve(__dirname, '..');
    const filename = process.argv[2] || path.join(root, 'docs/evidence/requirements/coverage.json');
    if (process.argv.length > 3) throw Error('Usage: node scripts/agent10-acceptance-audit.cjs [coverage.json]');
    console.log(JSON.stringify(audit(readCoverageFile(filename)), null, 2));
  } catch (error) {
    console.error('INCOMPLETE: independent coverage audit failed:', error.message);
    process.exitCode = 1;
  }
}

module.exports = { audit, externalGates, originalCategoryCounts, readCoverageFile };
