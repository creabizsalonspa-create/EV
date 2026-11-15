#!/usr/bin/env node
'use strict';
// Independent, read-only acceptance audit. No production or external verification.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const stages = ['coded', 'tested', 'merged', 'production_accepted'];
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
  const raw = Object.fromEntries(stages.map(stage => [stage, 0]));
  const weighted = Object.fromEntries(stages.map(stage => [stage, 0]));
  const softwareGaps = { not_coded: [], coded_not_tested: [], tested_not_merged: [] };
  const unaccepted = [];
  for (const category of report.categories) {
    assert.ok(typeof category.prd === 'string' && /^\d+\.\d+$/.test(category.prd), 'Invalid category');
    assert.ok(Number.isSafeInteger(category.atoms) && category.atoms > 0, 'Invalid category denominator');
    assert.ok(Array.isArray(category.details) && category.details.length === category.atoms, 'Missing category atoms');
    const perCategory = Object.fromEntries(stages.map(stage => [stage, 0]));
    for (const atom of category.details) {
      assert.ok(typeof atom.id === 'string' && atom.id.startsWith(category.prd + '.'), 'Atom category mismatch');
      assert.ok(!ids.has(atom.id), 'Duplicate atom identifier');
      ids.add(atom.id);
      assert.ok(atom.stages && typeof atom.stages === 'object', 'Atom stages missing');
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

if (require.main === module) {
  try {
    const root = path.resolve(__dirname, '..');
    const filename = process.argv[2] || path.join(root, 'docs/evidence/requirements/coverage.json');
    if (process.argv.length > 3) throw Error('Usage: node scripts/agent10-acceptance-audit.cjs [coverage.json]');
    const stat = fs.statSync(filename);
    if (!stat.isFile() || stat.size > 2_000_000) throw Error('Coverage file missing or oversized');
    console.log(JSON.stringify(audit(JSON.parse(fs.readFileSync(filename, 'utf8'))), null, 2));
  } catch (error) {
    console.error('INCOMPLETE: independent coverage audit failed:', error.message);
    process.exitCode = 1;
  }
}

module.exports = { audit, externalGates };
