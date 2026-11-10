#!/usr/bin/env node
'use strict';
// Agent 10: validate that handoff materials refer only to a private, synthetic local client demo.
// Read-only; never prints credentials, starts simulators or modifies account records.
const fs = require('node:fs');
const path = require('node:path');

const roles = ['customer', 'vendor', 'admin'];
const protocols = {'1.6': '16', '2.0.1': '201'};
const topFields = ['fixture_only', 'run', 'base', 'company_id', 'accounts', 'stations',
  'qa_current_bookings', 'physical_charging_verified', 'real_money'];
const stationFields = ['protocol', 'identity', 'station_id', 'charger_id', 'binding_id',
  'connector_mapping_id', 'reservation_id'];
const positiveId = n => Number.isSafeInteger(n) && n > 0;
const exactKeys = (record, fields) => record && typeof record === 'object' && !Array.isArray(record) &&
  Object.keys(record).sort().join(',') === [...fields].sort().join(',');

function validateFixture(fixture, opts = {}) {
  const issues = [];
  if (!exactKeys(fixture, topFields)) issues.push('Unexpected private demo manifest schema');
  if (!fixture || typeof fixture !== 'object') return issues;
  if (fixture.fixture_only !== true || fixture.real_money !== false ||
      fixture.physical_charging_verified !== false) issues.push('Real/physical charging claims are not allowed in synthetic demo');
  if (!/^[a-f0-9]{8}$/.test(String(fixture.run || ''))) issues.push('Demo identity is invalid');
  if (fixture.base !== 'http://127.0.0.1:8000') issues.push('Only the isolated local Laravel origin is permitted');
  if (!positiveId(fixture.company_id)) issues.push('Synthetic company reference is invalid');
  if (typeof fixture.qa_current_bookings !== 'boolean') issues.push('QA booking intent must be explicit');
  if (fixture.qa_current_bookings && opts.allowLocalQa !== true) issues.push('Current-slot QA shortcuts are not permitted in normal demos');
  if (!exactKeys(fixture.accounts, roles)) issues.push('Exactly three synthetic account roles are required');
  else for (const role of roles) {
    const account = fixture.accounts[role];
    if (!exactKeys(account, ['email','password']) ||
        account.email !== `client-demo-${role}-${fixture.run}@example.test` ||
        typeof account.password !== 'string' || account.password.length < 48 ||
        account.password.length > 128 || !/^[0-9a-f]{48}aA9!$/.test(account.password)) {
      issues.push(`${role}: invalid synthetic credential structure`);
    }
  }
  if (!Array.isArray(fixture.stations) || fixture.stations.length !== 2) {
    issues.push('Exactly two synthetic protocol stations required');
  } else {
    const seenProtocols = new Set(), stationIds = new Set(), chargerIds = new Set(), bindings = new Set();
    for (const station of fixture.stations) {
      if (!exactKeys(station, stationFields)) {
        issues.push('Invalid synthetic station metadata');
        continue;
      }
      const suffix = protocols[station.protocol];
      if (!suffix || seenProtocols.has(station.protocol) || station.identity !== `ev-test-demo-${fixture.run}-${suffix}`) {
        issues.push('Unexpected OCPP simulator identity or protocol');
      }
      seenProtocols.add(station.protocol);
      for (const [name, set] of [['station_id',stationIds],['charger_id',chargerIds],['binding_id',bindings]]) {
        if (!positiveId(station[name]) || set.has(station[name])) issues.push(`${name}: invalid or duplicate synthetic reference`);
        set.add(station[name]);
      }
      if (!positiveId(station.connector_mapping_id)) issues.push('Missing canonical connector mapping');
      if (fixture.qa_current_bookings === false && station.reservation_id !== null) {
        issues.push('Normal demonstration must create future reservations through the app');
      }
      if (fixture.qa_current_bookings === true && !positiveId(station.reservation_id)) {
        issues.push('Explicit local QA requires a synthetic reservation ID');
      }
    }
    if (seenProtocols.size !== 2) issues.push('Both OCPP 1.6 and 2.0.1 simulator scenarios are required');
  }
  return issues;
}

function inspectManifest(file, opts = {}) {
  const input = path.resolve(file);
  const stats = fs.lstatSync(input);
  const dir = path.dirname(input);
  const dirStats = fs.lstatSync(dir);
  if (stats.isSymbolicLink() || !stats.isFile() || dirStats.isSymbolicLink() || !dirStats.isDirectory() ||
      stats.size === 0 || stats.size > 64*1024 ||
      (process.platform !== 'win32' && ((stats.mode & 0o077) !== 0 || (dirStats.mode & 0o077) !== 0))) {
    throw Error('Demo manifest must be a private, regular small file in a private run directory');
  }
  if (path.basename(input) !== 'access.json' || path.basename(path.dirname(dir)) !== 'client-demo') {
    throw Error('Demo manifest path is not a recognized private run layout');
  }
  let fixture;
  try { fixture = JSON.parse(fs.readFileSync(input, 'utf8')); }
  catch { throw Error('Demo manifest JSON cannot be read safely'); }
  if (path.basename(dir) !== fixture?.run) throw Error('Demo directory and run identity differ');
  return validateFixture(fixture, opts);
}

function parseArgs(args) {
  if (args.length !== 2 && args.length !== 3) throw Error('Expected --manifest PRIVATE/access.json [--allow-local-qa]');
  if (args[0] !== '--manifest' || !args[1] || args[1].startsWith('--') ||
      (args.length === 3 && args[2] !== '--allow-local-qa')) throw Error('Unsupported demo manifest options');
  return {file:args[1],allowLocalQa:args[2]==='--allow-local-qa'};
}

function main(args = process.argv.slice(2)) {
  try {
    const options = parseArgs(args);
    const issues = inspectManifest(options.file, {allowLocalQa:options.allowLocalQa});
    if (issues.length) {
      issues.forEach(x => process.stderr.write(`FAIL: ${x}\n`));
      return 1;
    }
    process.stdout.write('PASS: protected local synthetic customer/vendor/Admin accounts and both OCPP simulator identities are consistent. Private credentials withheld; no live-source, financial, booking or production acceptance inferred.\n');
    return 0;
  } catch {
    process.stderr.write('FAIL: private demo manifest layout, permissions or content requires review. Credentials and file paths withheld.\n');
    return 2;
  }
}
if (require.main === module) process.exitCode = main();
module.exports = { validateFixture, inspectManifest, parseArgs, main };
