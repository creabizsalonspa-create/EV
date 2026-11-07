#!/usr/bin/env node
'use strict';
// Agent 10: read-only staging Docker Compose runtime acceptance, never start/stop containers.
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..', '..', '..');
const expected = ['application', 'worker', 'scheduler', 'database', 'gateway'];
const PROJECT = 'ev-staging';
const MAX_SNAPSHOT_BYTES = 1024 * 1024;

function parseSnapshot(text) {
  if (typeof text !== 'string' || Buffer.byteLength(text) > MAX_SNAPSHOT_BYTES || !text.trim()) {
    throw Error('Docker Compose snapshot missing or excessive');
  }
  let records;
  try {
    const whole = JSON.parse(text);
    records = Array.isArray(whole) ? whole : [whole];
  } catch {
    try { records = text.trim().split(/\r?\n/).map(line => JSON.parse(line)); }
    catch { throw Error('Docker Compose snapshot is not JSON'); }
  }
  if (!Array.isArray(records) || !records.length || records.length > 30 ||
      records.some(row => !row || typeof row !== 'object' || Array.isArray(row))) {
    throw Error('Docker Compose snapshot has invalid records');
  }
  return records;
}

function checkSnapshot(records) {
  const problems = [];
  const services = new Map();
  for (const container of records) {
    const name = container.Service;
    if (typeof name !== 'string' || !expected.includes(name)) {
      problems.push('Unexpected service in staging project');
      continue;
    }
    if (services.has(name)) {
      problems.push(`${name}: multiple running or stale service containers`);
      continue;
    }
    services.set(name, container);
    if (container.Project !== PROJECT) problems.push(`${name}: unexpected Docker Compose project`);
    if (container.State !== 'running') problems.push(`${name}: container is not running`);
    if (['application', 'database'].includes(name)) {
      if (container.Health !== 'healthy') problems.push(`${name}: healthy container status required`);
    } else if (container.Health === 'unhealthy' || container.Health === 'starting') {
      problems.push(`${name}: container not ready`);
    }
    if (typeof container.Image !== 'string' || !container.Image.trim()) {
      problems.push(`${name}: missing container image identity`);
    }
    const publishers = container.Publishers == null ? [] : container.Publishers;
    if (!Array.isArray(publishers)) {
      problems.push(`${name}: invalid published-port data`);
      continue;
    }
    // Docker ps reports exposed-but-unpublished ports as PublishedPort: 0.
    // Only a positive published host port represents an external socket mapping.
    if (publishers.some(p => !p || !Number.isInteger(Number(p.PublishedPort)) || Number(p.PublishedPort) < 0)) {
      problems.push(`${name}: invalid published port number`);
    }
    const published = publishers.filter(p => p && Number(p.PublishedPort) > 0);
    if (name !== 'gateway') {
      if (published.length) problems.push(`${name}: private service publishes host port`);
    } else if (published.length !== 1 ||
      published[0]?.URL !== '127.0.0.1' ||
      Number(published[0]?.PublishedPort) !== 8443 ||
      Number(published[0]?.TargetPort) !== 443 ||
      published[0]?.Protocol !== 'tcp') {
      problems.push('gateway: expected single loopback-only 8443 to 443 TCP binding');
    }
  }
  for (const name of expected) {
    if (!services.has(name)) problems.push(`${name}: missing required staging service`);
  }
  const app = services.get('application')?.Image;
  if (typeof app === 'string') {
    if (!/^creabiz-ev-business:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/.test(app)) {
      problems.push('application: unrecognized staging image tag');
    }
    for (const service of ['worker', 'scheduler']) {
      if (services.get(service)?.Image !== app) problems.push(`${service}: application image differs from HTTP service`);
    }
  }
  if (services.has('database') &&
      !/^postgres:17-alpine(?:@sha256:[0-9a-f]{64})?$/.test(String(services.get('database').Image || ''))) {
    problems.push('database: unexpected PostgreSQL image family');
  }
  if (services.has('gateway') &&
      !/^caddy:2-alpine(?:@sha256:[0-9a-f]{64})?$/.test(String(services.get('gateway').Image || ''))) {
    problems.push('gateway: unexpected reverse-proxy image family');
  }
  return { ok: !problems.length, problems, inspected_services: services.size };
}

function parseArgs(args) {
  if (args.length === 1 && args[0] === '--live') return { mode: 'live' };
  if (args.length === 2 && args[0] === '--snapshot' && args[1] && !args[1].startsWith('--')) {
    return { mode: 'snapshot', file: args[1] };
  }
  throw Error('Usage: node scripts/staging-runtime-gate.cjs --snapshot PRIVATE_PS.json | --live');
}

function localDockerSnapshot(env = process.env, runner = execFileSync) {
  if (env.DOCKER_HOST || (env.DOCKER_CONTEXT && env.DOCKER_CONTEXT !== 'default') ||
      env.DOCKER_TLS_VERIFY || env.DOCKER_CERT_PATH) {
    throw Error('Remote Docker context disallowed');
  }
  // Docker's active context may differ from "default" even with DOCKER_CONTEXT unset.
  // Never trust checking the default socket alone while the active context is remote.
  const active = String(runner('docker', ['context', 'show'],
    { encoding: 'utf8', timeout: 8000, maxBuffer: 16384 })).trim();
  if (active !== 'default') throw Error('Active Docker context must be default');
  const host = String(runner('docker', ['context', 'inspect', 'default', '--format', '{{(index .Endpoints "docker").Host}}'],
    { encoding: 'utf8', timeout: 8000, maxBuffer: 16384 })).trim();
  if (!['unix:///var/run/docker.sock', 'unix:///run/docker.sock'].includes(host)) {
    throw Error('Docker context is not a local Unix socket');
  }
  return runner('docker', ['compose', '--project-name', PROJECT, '-f',
    path.resolve(__dirname, '../infra/staging/compose.yaml'), 'ps', '--all', '--format', 'json'],
    { encoding: 'utf8', timeout: 10000, maxBuffer: MAX_SNAPSHOT_BYTES });
}

function readSnapshotFile(file) {
  const info = fs.lstatSync(file);
  if (!info.isFile() || info.isSymbolicLink() || info.size > MAX_SNAPSHOT_BYTES) {
    throw Error('Unsafe or excessive local snapshot file');
  }
  return fs.readFileSync(file, 'utf8');
}

function main(args = process.argv.slice(2), options = {}) {
  try {
    const choice = parseArgs(args);
    const source = choice.mode === 'live'
      ? localDockerSnapshot(options.env || process.env, options.runner || execFileSync)
      : readSnapshotFile(choice.file);
    const checked = checkSnapshot(parseSnapshot(source));
    if (!checked.ok) {
      checked.problems.forEach(problem => process.stderr.write(`FAIL: ${problem}\n`));
      return 1;
    }
    process.stdout.write('PASS: five staging services running, required health checks green, private port binding and shared application image verified. Staging transactions, workers, backups and production acceptance require separate tests.\n');
    return 0;
  } catch {
    process.stderr.write('FAIL: staging runtime gate needs an authorized local Docker engine or valid private snapshot; details withheld.\n');
    return 2;
  }
}
if (require.main === module) process.exitCode = main();
module.exports = { parseSnapshot, checkSnapshot, parseArgs, localDockerSnapshot, readSnapshotFile, main };
