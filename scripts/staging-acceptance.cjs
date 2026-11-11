#!/usr/bin/env node
'use strict';
// Agent 10: immutable evidence for read-only, privately reachable staging prerequisites.
// Never starts or changes services; an operator must independently authorize staging.
const fs = require('node:fs');
const path = require('node:path');
const {randomBytes} = require('node:crypto');
const {ancestorSnapshot,assertAncestorsUnchanged} = require('./staging-path-integrity.cjs');
const { execFileSync } = require('node:child_process');
const { parseSnapshot, checkSnapshot, readSnapshotFile } = require('./staging-runtime-gate.cjs');
const { inspectManifest } = require('./demo-fixture-guard.cjs');
const { checks: canonicalHttpChecks, parseArgs: parseHealthArgs, run: checkHttp } = require('./staging-health.cjs');

const HEX_SHA = /^[a-f0-9]{40}$/;
const evidenceSchema = 'ev-private-staging-prerequisites-v1';
const requiredArgs = ['--runtime-snapshot', '--url', '--demo-manifest', '--source-sha', '--output'];
const optionalFlags = ['--tls-loopback', '--allow-local-qa'];

function parseArgs(args) {
  if (!Array.isArray(args)) throw Error('Invalid options');
  const values = Object.create(null);
  for (let i = 0; i < args.length; i++) {
    const item = args[i];
    if (![...requiredArgs, ...optionalFlags].includes(item) || Object.hasOwn(values, item)) {
      throw Error('Unknown or duplicate argument');
    }
    if (optionalFlags.includes(item)) values[item] = true;
    else if (!args[i+1] || args[i+1].startsWith('--')) throw Error('Missing argument value');
    else values[item] = args[++i];
  }
  if (!requiredArgs.every(k => typeof values[k] === 'string')) throw Error('Missing mandatory arguments');
  if (!HEX_SHA.test(values['--source-sha'])) throw Error('Source SHA must be exact lower-case hexadecimal commit ID');
  const health = parseHealthArgs(['--url', values['--url'], ...(values['--tls-loopback'] ? ['--tls-loopback'] : [])]);
  return {
    runtime: values['--runtime-snapshot'],
    demo: values['--demo-manifest'],
    source_sha: values['--source-sha'],
    output: values['--output'],
    allowLocalQa: Boolean(values['--allow-local-qa']),
    health,
  };
}

function getCurrentHead(runner = execFileSync) {
  const root = path.resolve(__dirname, '..');
  const head = String(runner('git', ['rev-parse', '--verify', 'HEAD'], {
    cwd: root, timeout: 7000, encoding: 'utf8', maxBuffer: 4096,
  })).trim();
  if (!HEX_SHA.test(head)) throw Error('Missing checkout commit');
  const dirty = String(runner('git', ['status', '--porcelain', '--untracked-files=normal'], {
    cwd: root, timeout: 7000, encoding: 'utf8', maxBuffer: 16384,
  })).trim();
  if (dirty) throw Error('Tracked or untracked non-ignored source differs from checked out commit');
  return head;
}

function checkOutputDestination(filePath) {
  const target = path.resolve(filePath);
  const parent = path.dirname(target);
  ancestorSnapshot(target); // Reject symlinks in every ancestor, not only the leaf parent.
  const details = fs.lstatSync(parent);
  if (!details.isDirectory() || details.isSymbolicLink()) throw Error('Evidence directory is not a real directory');
  if (process.platform !== 'win32' && (details.mode & 0o077) !== 0) {
    throw Error('Evidence directory must be owner-only');
  }
  if (!/^[a-zA-Z0-9_.-]+\.json$/.test(path.basename(target))) throw Error('Evidence filename invalid');
  if (fs.existsSync(target)) throw Error('Evidence file already exists; overwrite forbidden');
  return target;
}

function writeEvidence(filePath, data, deps = {}) {
  const target = checkOutputDestination(filePath);
  const parent = path.dirname(target);
  const ancestors = ancestorSnapshot(target);
  const directory = fs.lstatSync(parent);
  // Serialize before creating anything: a failed serializer never leaves an
  // apparently final JSON report. An interrupted write leaves no final path.
  const serialized = JSON.stringify(data, null, 2);
  if (typeof serialized !== 'string') throw Error('Invalid staging evidence JSON');
  const bytes = Buffer.from(serialized + '\n', 'utf8');
  if (bytes.length === 0 || bytes.length > 256 * 1024) {
    throw Error('Staging evidence exceeds the private report size boundary');
  }
  const temporary = path.join(parent, '.ev-stage-proof-' + randomBytes(16).toString('hex') + '.tmp');
  const flags = fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL |
    (fs.constants.O_NOFOLLOW || 0);
  let fd, tempIdentity, linked = false;
  try {
    fd = fs.openSync(temporary, flags, 0o600);
    tempIdentity = fs.fstatSync(fd);
    if (!tempIdentity.isFile() || tempIdentity.nlink !== 1 ||
        (process.platform !== 'win32' && (tempIdentity.mode & 0o077) !== 0)) {
      throw Error('Temporary staging evidence inode is unsafe');
    }
    // Optional injected writer is exclusively for source-isolated fault tests.
    (deps.writePayload || ((handle, payload) => fs.writeFileSync(handle, payload)))(fd, bytes);
    if (fs.fstatSync(fd).size !== bytes.length) {
      throw Error('Private staging evidence write is incomplete');
    }
    fs.fsyncSync(fd);
    fs.closeSync(fd);
    fd = undefined;

    assertAncestorsUnchanged(ancestors);
    const current = fs.lstatSync(parent);
    if (current.dev !== directory.dev || current.ino !== directory.ino ||
        !current.isDirectory() || current.isSymbolicLink() ||
        (process.platform !== 'win32' && (current.mode & 0o077) !== 0)) {
      throw Error('Private staging evidence directory changed');
    }
    // Hardlink publication is atomic and refuses an existing regular file,
    // symlink, or dangling symlink. Rename would silently overwrite evidence.
    fs.linkSync(temporary, target);
    linked = true;
    fs.unlinkSync(temporary);
    // Persist the directory rename/link operations as well as file contents.
    if (process.platform !== 'win32') {
      const directoryFd = fs.openSync(parent, fs.constants.O_RDONLY |
        (fs.constants.O_DIRECTORY || 0) | (fs.constants.O_NOFOLLOW || 0));
      try {
        const opened = fs.fstatSync(directoryFd);
        if (opened.dev !== directory.dev || opened.ino !== directory.ino) {
          throw Error('Private evidence directory changed during publication');
        }
        fs.fsyncSync(directoryFd);
      } finally {
        fs.closeSync(directoryFd);
      }
    }
  } catch (error) {
    if (fd !== undefined) fs.closeSync(fd);
    // Never delete a substituted inode in a changed directory. A successfully
    // published target must not be unlinked on cleanup failures.
    if (tempIdentity) {
      try {
        const latest = fs.lstatSync(temporary);
        if (latest.isFile() && latest.dev === tempIdentity.dev &&
            latest.ino === tempIdentity.ino) fs.unlinkSync(temporary);
      } catch (cleanup) {
        if (cleanup.code !== 'ENOENT') {
          // Preserve original failure without deleting unverified files.
        }
      }
    }
    throw error;
  }
}

async function inspect(config, dependencies = {}) {
  const now = dependencies.now || (() => new Date().toISOString());
  const getHead = dependencies.getHead || getCurrentHead;
  const readSnapshot = dependencies.readSnapshot || readSnapshotFile;
  const inspectDemo = dependencies.inspectDemo || inspectManifest;
  const runHttp = dependencies.runHttp || checkHttp;
  const beginning = now();
  const result = {
    schema: evidenceSchema,
    source_sha: config.source_sha,
    observed_from_utc: beginning,
    observed_until_utc: null,
    environment: 'private_staging_preparation',
    status: 'INCOMPLETE',
    prerequisites: { source_checkout: 'not_checked', docker_snapshot: 'not_checked', synthetic_demo: 'not_checked', http_routes: 'not_checked' },
    http_checks: [],
    production_accepted: false,
    release_authorized: false,
    live_charger_verified: false,
    payment_processor_verified: false,
    running_image_matches_source_verified: false,
    independent_checks_outside_scope: ['private staging secrets', 'backup_restore', 'authenticated end_to_end', 'mobile_source_build', 'real_hardware', 'real_payments', 'external_ocpi', 'production_signing'],
  };
  try {
    if (getHead() !== config.source_sha) throw Error('Staging source checkpoint mismatches local HEAD');
    result.prerequisites.source_checkout = 'passed';
    const runtime = checkSnapshot(parseSnapshot(readSnapshot(config.runtime)));
    if (!runtime.ok) throw Error('Staging container, health or private port criteria did not pass');
    result.prerequisites.docker_snapshot = 'passed';
    const demoErrors = inspectDemo(config.demo, { allowLocalQa: config.allowLocalQa });
    if (!Array.isArray(demoErrors) || demoErrors.length) throw Error('Demo data/privacy prerequisites failed');
    result.prerequisites.synthetic_demo = 'passed';
    const http = await runHttp(config.health);
    if (!http || !Array.isArray(http.results)) throw Error('HTTP inspection did not return a valid result');
    result.http_checks = canonicalHttpChecks.map((expected, index) => ({
      route: expected.path,
      result: http.results[index]?.path === expected.path && http.results[index]?.ok === true ? 'passed' : 'failed',
    }));
    if (http.ok !== true || http.results.length !== canonicalHttpChecks.length ||
        result.http_checks.some(row => row.result !== 'passed')) {
      throw Error('HTTP readiness or privacy boundaries failed');
    }
    result.prerequisites.http_routes = 'passed';
    result.status = 'PRIVATE_PREREQUISITES_PASSED';
  } catch {
    // Preserve non-sensitive stage evidence, never include response bodies or source credentials.
    result.status = 'INCOMPLETE';
  }
  result.observed_until_utc = now();
  return result;
}

async function main(args = process.argv.slice(2), dependencies = {}) {
  try {
    const config = parseArgs(args);
    // Reject unsafe destinations before touching even a private local staging service.
    checkOutputDestination(config.output);
    const result = await inspect(config, dependencies);
    writeEvidence(config.output, result);
    if (result.status !== 'PRIVATE_PREREQUISITES_PASSED') {
      process.stderr.write('FAIL: private staging prerequisites remain incomplete. Evidence captured without credentials or private response bodies.\n');
      return 1;
    }
    process.stdout.write('PASS: private staging prerequisite evidence recorded. This is not deployment, authenticated system acceptance or production approval.\n');
    return 0;
  } catch {
    process.stderr.write('FAIL: offline staging acceptance configuration or private evidence location invalid; details withheld.\n');
    return 2;
  }
}
if (require.main === module) main().then(code => { process.exitCode = code; });
module.exports = { evidenceSchema, parseArgs, getCurrentHead, checkOutputDestination, writeEvidence, inspect, main };
