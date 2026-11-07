'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { parseEnv, reviewFile, checkValues, validAppKey } = require('../scripts/staging-preflight.cjs');

const key = 'base64:' + Buffer.alloc(32, 17).toString('base64');
const password = 'correct_horse_' + 'safe_random_database_credential_41';
const defaults = { APP_KEY: key, STAGING_HOST: 'staging.ev-example.org', ACME_EMAIL: 'ops@ev-example.org', DB_PASSWORD: password, STAGING_BIND_IP: '127.0.0.1', EV_IMAGE_TAG: 'review', PAYMENT_PROVIDER: 'mock', MAIL_MAILER: 'log' };
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

test('inherited shell binding cannot silently expose reviewed staging configuration', () => withEnv(source(), file => {
  const original = process.env.STAGING_BIND_IP;
  process.env.STAGING_BIND_IP = '0.0.0.0';
  try {
    const result = reviewFile(file);
    assert.equal(result.ok, false);
    assert.ok(result.errors.some(e => e.startsWith('STAGING_BIND_IP: inherited shell')));
    assert.ok(!JSON.stringify(result.errors).includes(password));
  } finally {
    if (original === undefined) delete process.env.STAGING_BIND_IP;
    else process.env.STAGING_BIND_IP = original;
  }
}));

test('matching inherited Compose binding does not fail review', () => withEnv(source(), file => {
  const original = process.env.STAGING_BIND_IP;
  process.env.STAGING_BIND_IP = '127.0.0.1';
  try {
    assert.equal(reviewFile(file).ok, true);
  } finally {
    if (original === undefined) delete process.env.STAGING_BIND_IP;
    else process.env.STAGING_BIND_IP = original;
  }
}));

test('Laravel database URL override is forbidden even with Compose DB_HOST pinned', () => {
  for (const name of ['DB_URL','DATABASE_URL']) {
    const errors=checkValues({...defaults,[name]:'postgresql://customer-production.example.org/app'});
    assert.ok(errors.some(x=>x.startsWith(name+':')),name);
    assert.equal(JSON.stringify(errors).includes('customer-production.example.org'),false);
  }
});
test('wrong database service or real payment provider cannot enter synthetic staging', () => {
  for (const [key,value] of [['DB_CONNECTION','mysql'],['DB_HOST','prod-db.example.org'],
    ['DB_DATABASE','customer_live'],['PAYMENT_PROVIDER','stripe'],['MAIL_MAILER','smtp']]) {
    assert.ok(checkValues({...defaults,[key]:value}).some(x=>x.startsWith(key+':')),key);
  }
});
test('all remote charger, partner and collection switches fail closed', () => {
  const flags=['SUBSCRIPTION_COLLECTION_ENABLED','PAYMENT_RECOVERY_PROVIDER_POLL',
    'PAYOUT_TEST_ENABLED','OCPI_TEST_COMMANDS_ENABLED',
    'CSMS_COMMAND_WORKER_ENABLED','CSMS_REMOTE_AUTHORIZATION_ENABLED',
    'CSMS_SESSION_BRIDGE_ENABLED','CSMS_INGRESS_AUTH_ENABLED',
    'CSMS_INGRESS_ALLOW_LOCAL_HTTP','CSMS_RIDER_AUTH_ENABLED',
    'CSMS_SAFE_RESET_ENABLED','CSMS_SIGNED_FIRMWARE_ENABLED',
    'CSMS_FIRMWARE_ENABLED','CSMS_DIAGNOSTIC_LOGS_ENABLED',
    'CSMS_FAULT_WORKER_ENABLED','CSMS_CONFIGURATION_READ_ENABLED',
    'COMMERCIAL_CAMPAIGNS_ENABLED','DISCOVERY_GEOCODING_ENABLED',
    'DISCOVERY_ROUTING_ENABLED','EV_BATTERY_SIMULATOR_ENABLED'];
  for(const name of flags){
    assert.ok(checkValues({...defaults,[name]:'true'}).some(x=>x.startsWith(name+':')),name);
    assert.ok(checkValues({...defaults,[name]:'$'+'{EXTERNAL_FLAG}'}).some(x=>x.startsWith(name+':')),name);
    assert.ok(!checkValues({...defaults,[name]:'false'}).some(x=>x.startsWith(name+':')),name);
  }
});
test('OCPI partner credentials and live PSP keys are barred without disclosure', () => {
  for (const name of ['OCPI_CONNECTIONS_JSON','OCPI_LOCAL_CREDENTIALS_JSON']) {
    assert.ok(checkValues({...defaults,[name]:'{"partner":"live"}'}).some(x=>x.startsWith(name+':')),name);
    assert.ok(!checkValues({...defaults,[name]:'{}'}).some(x=>x.startsWith(name+':')),name);
  }
  for (const name of ['STRIPE_SECRET','STRIPE_LIVE_SECRET','STRIPE_SECRET_KEY',
    'PAYPAL_CLIENT_SECRET','AWS_SECRET_ACCESS_KEY']){
    const errors=checkValues({...defaults,[name]:'very_private_not_for_staging'});
    assert.ok(errors.some(x=>x.startsWith(name+':')));
    assert.equal(JSON.stringify(errors).includes('very_private_not_for_staging'),false);
  }
});

test('Compose bare dollar interpolation cannot alter reviewed credentials', () => {
  const payload='long_private_database_password_2026_'+'$'+'UNREVIEWED_PASSWORD';
  const errors=checkValues({...defaults,DB_PASSWORD:payload});
  assert.ok(errors.some(x=>x.startsWith('DB_PASSWORD: interpolation')));
  assert.equal(JSON.stringify(errors).includes(payload),false);
});

test('loopback-only Caddy staging uses internal CA, not unreachable public ACME', () => {
  const caddy=fs.readFileSync(path.resolve(__dirname,'../infra/staging/Caddyfile'),'utf8');
  assert.match(caddy,/^\s*tls internal\s*$/m);
  assert.match(caddy,/^\{\$STAGING_HOST\} \{$/m);
  assert.match(caddy,/^\s*admin off\s*$/m);
  assert.match(caddy,/^\s*reverse_proxy application:80\s*$/m);
  assert.doesNotMatch(caddy,/^\s*tls\s+(?:\S+)\.crt/m);
  assert.doesNotMatch(caddy,/^\s*issuer\s+(?:acme|zerossl)\b/m);
});
test('staging Compose continues to publish only the private gateway', () => {
  const yaml=fs.readFileSync(path.resolve(__dirname,'../infra/staging/compose.yaml'),'utf8');
  assert.match(yaml,/STAGING_BIND_IP:-127\.0\.0\.1/);
  assert.match(yaml,/8443:443/);
  assert.match(yaml,/certificates:\/data/);
  assert.doesNotMatch(yaml,/\b443:443['"]?\s*$/m);
});

test('isolated payment and log-mail settings are required rather than inherited from unknown app defaults',()=>{
  const x={...defaults};delete x.PAYMENT_PROVIDER;
  assert.ok(checkValues(x).some(e=>e.startsWith('PAYMENT_PROVIDER: required')));
  x.PAYMENT_PROVIDER='mock';delete x.MAIL_MAILER;
  assert.ok(checkValues(x).some(e=>e.startsWith('MAIL_MAILER: required')));
});
