'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { parseEnv, reviewFile, checkValues, validAppKey } = require('../scripts/staging-preflight.cjs');

const key = 'base64:' + Buffer.alloc(32, 17).toString('base64');
const password = 'correct_horse_' + 'safe_random_database_credential_41';
const defaults = { APP_KEY: key, STAGING_HOST: 'staging.ev-example.org', ACME_EMAIL: 'ops@ev-example.org', DB_PASSWORD: password, STAGING_BIND_IP: '127.0.0.1', EV_IMAGE_TAG: 'review' };
function source(overrides = {}) {
  return Object.entries({ ...defaults, ...overrides }).map(([name, value]) => `${name}=${value}`).join('\n') + '\n';
}
function withEnv(content, cb, permissions = 0o600) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ev-stage-preflight-'));
  const file = path.join(dir, '.env');
  try {
    fs.writeFileSync(file, content, { mode: permissions });
    fs.chmodSync(file, permissions);
    return cb(file);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
}

test('valid private offline staging configuration passes', () => withEnv(source(), file => {
  assert.deepEqual(reviewFile(file), { ok: true, errors: [] });
}));
test('missing credentials are rejected without printing values', () => withEnv('STAGING_HOST=staging.ev-example.org\n', file => {
  const result = reviewFile(file);
  assert.equal(result.ok, false);
  assert.ok(result.errors.some(e => e.startsWith('APP_KEY:')));
  assert.ok(result.errors.some(e => e.startsWith('DB_PASSWORD:')));
}));
test('malformed and short app keys are rejected', () => {
  assert.equal(validAppKey('base64:a'), false);
  assert.equal(validAppKey('base64:' + Buffer.alloc(16).toString('base64')), false);
  assert.ok(checkValues({ ...defaults, APP_KEY: 'base64:broken' }).some(e => e.startsWith('APP_KEY:')));
});
test('weak secrets are rejected', () => {
  assert.ok(checkValues({ ...defaults, DB_PASSWORD: 'password123' }).some(e => e.startsWith('DB_PASSWORD:')));
});
test('public or wildcard bindings fail closed', () => {
  for (const addr of ['0.0.0.0', '::', '192.168.1.2', 'localhost']) {
    assert.ok(checkValues({ ...defaults, STAGING_BIND_IP: addr }).some(e => e.startsWith('STAGING_BIND_IP:')));
  }
});
test('placeholder domains, URLs, and private names cannot masquerade as deployable FQDNs', () => {
  for (const host of ['example.invalid', 'staging.example', 'http://staging.example.org', 'localhost', 'STAGING.EXAMPLE.ORG', 'stage.local']) {
    assert.ok(checkValues({ ...defaults, STAGING_HOST: host }).some(e => e.startsWith('STAGING_HOST:')));
  }
});
test('duplicate environment keys fail instead of silently overwriting credentials', () => {
  assert.ok(parseEnv(source() + 'DB_PASSWORD=overridden\n').errors.some(e => e.includes('duplicate')));
});
test('unexpected expressions are rejected before Docker interpolation', () => {
  assert.ok(checkValues({ ...defaults, DB_PASSWORD: '${EXTERNAL_SECRET}' + password }).some(e => e.includes('interpolation')));
});
test('invalid image tags and debug/simulator opt-ins fail', () => {
  const errors = checkValues({ ...defaults, EV_IMAGE_TAG: 'x;evil', APP_DEBUG: 'true', CSMS_SIMULATOR_ENABLED: 'true' });
  assert.ok(errors.some(e => e.startsWith('EV_IMAGE_TAG:')));
  assert.ok(errors.some(e => e.startsWith('APP_DEBUG:')));
  assert.ok(errors.some(e => e.startsWith('CSMS_SIMULATOR_ENABLED:')));
});
test('group or world-readable private environment is rejected', { skip: process.platform === 'win32' }, () => withEnv(source(), file => {
  assert.ok(reviewFile(file).errors.some(e => e.includes('chmod 600')));
}, 0o644));
test('symbolic link to credentials is rejected', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ev-stage-link-'));
  try {
    const target = path.join(dir, 'target');
    const link = path.join(dir, '.env');
    fs.writeFileSync(target, source(), { mode: 0o600 });
    fs.symlinkSync(target, link);
    assert.equal(reviewFile(link).ok, false);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});
test('reported failures never contain raw supplied credentials', () => withEnv(source({ STAGING_HOST: 'bad.invalid', DB_PASSWORD: 'unsafe_secret_value' }), file => {
  const result = reviewFile(file);
  assert.equal(result.ok, false);
  assert.equal(JSON.stringify(result.errors).includes('unsafe_secret_value'), false);
}));
