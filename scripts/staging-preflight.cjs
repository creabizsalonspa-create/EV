#!/usr/bin/env node
'use strict';

// Safe, offline review of staging configuration. Does not invoke Docker or contact any host.
const fs = require('node:fs');
const path = require('node:path');

const required = ['APP_KEY', 'STAGING_HOST', 'ACME_EMAIL', 'DB_PASSWORD', 'PAYMENT_PROVIDER', 'MAIL_MAILER'];
const reservedSuffixes = ['.example', '.invalid', '.test', '.localhost', '.local'];
const safeTag = /^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/;

function parseEnv(text) {
  const values = Object.create(null);
  const errors = [];
  text.split(/\r?\n/).forEach((line, i) => {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) return;
    const match = trimmed.match(/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/);
    if (!match) {
      errors.push(`Line ${i + 1}: invalid environment assignment`);
      return;
    }
    const [, name, raw] = match;
    if (Object.hasOwn(values, name)) {
      errors.push(`Line ${i + 1}: duplicate environment key ${name}`);
      return;
    }
    let value = raw.trim();
    if (/^['"]/.test(value)) {
      const quote = value[0];
      if (value.length < 2 || value[value.length - 1] !== quote) {
        errors.push(`Line ${i + 1}: malformed quoted value for ${name}`);
        return;
      }
      value = value.slice(1, -1);
    } else if (/\s+#/.test(value)) {
      value = value.replace(/\s+#.*$/, '').trim();
    }
    values[name] = value;
  });
  return { values, errors };
}

function validDomain(host) {
  if (!host || host.length > 253 || !host.includes('.') || /[:/?#@\s]/.test(host)) return false;
  if (host !== host.toLowerCase() || reservedSuffixes.some(s => host.endsWith(s))) return false;
  return host.split('.').every(label => label.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label));
}

function validAppKey(value) {
  if (!value || !value.startsWith('base64:')) return false;
  const encoded = value.slice(7);
  if (!/^(?:[A-Za-z0-9+/]{4}){10}[A-Za-z0-9+/]{3}=$/.test(encoded)) return false;
  const bytes = Buffer.from(encoded, 'base64');
  return bytes.length === 32 && bytes.toString('base64') === encoded;
}

function checkValues(values) {
  const errors = [];
  for (const name of required) {
    if (!values[name]) errors.push(`${name}: required private staging setting is missing`);
  }
  if (values.APP_KEY && !validAppKey(values.APP_KEY)) {
    errors.push('APP_KEY: must be a valid base64-encoded 32-byte Laravel key');
  }
  if (values.DB_PASSWORD && (values.DB_PASSWORD.length < 24 || /^(password|changeme|example|replace|test|secret)/i.test(values.DB_PASSWORD))) {
    errors.push('DB_PASSWORD: use a strong, unique random credential of at least 24 characters');
  }
  if (values.STAGING_HOST && !validDomain(values.STAGING_HOST)) {
    errors.push('STAGING_HOST: use an authorized fully-qualified DNS hostname, not a URL or placeholder');
  }
  if (values.ACME_EMAIL) {
    const parts = values.ACME_EMAIL.split('@');
    if (parts.length !== 2 || !/^[^\s@<>]+$/.test(parts[0]) || !validDomain(parts[1])) {
      errors.push('ACME_EMAIL: use a valid responsible certificate contact');
    }
  }
  if ((values.STAGING_BIND_IP || '127.0.0.1') !== '127.0.0.1') {
    errors.push('STAGING_BIND_IP: review mode must remain loopback-only; public exposure requires separate authorization');
  }
  if (values.EV_IMAGE_TAG && !safeTag.test(values.EV_IMAGE_TAG)) {
    errors.push('EV_IMAGE_TAG: invalid Docker image tag');
  }
  if (values.APP_ENV && values.APP_ENV !== 'staging') errors.push('APP_ENV: must be staging');
  if (values.APP_DEBUG && values.APP_DEBUG !== 'false') errors.push('APP_DEBUG: must be false');
  if (values.CSMS_SIMULATOR_ENABLED && values.CSMS_SIMULATOR_ENABLED !== 'false') errors.push('CSMS_SIMULATOR_ENABLED: must be false');

  // The default private staging environment is a review environment, not live infrastructure.
  // In particular Laravel DB_URL overrides DB_* settings even when Compose pins DB_HOST.
  for (const name of ['DB_URL','DATABASE_URL']) {
    if (values[name] && values[name] !== 'null') errors.push(name + ': external database URLs are not permitted in isolated staging');
  }
  for (const [name,expected] of [['DB_CONNECTION','pgsql'],['DB_HOST','database'],
    ['DB_DATABASE','ev_business'],['PAYMENT_PROVIDER','mock'],['MAIL_MAILER','log']]) {
    if (values[name] && values[name] !== expected) {
      errors.push(name + ': non-isolated service configuration requires separate approval');
    }
  }
  // These are opt-in source/device commands, partner network or money-moving workflows.
  // They must stay disabled during anonymous or synthetic customer demonstrations.
  const disabledFlags = [
    'SUBSCRIPTION_COLLECTION_ENABLED','PAYMENT_RECOVERY_PROVIDER_POLL',
    'PAYOUT_TEST_ENABLED','OCPI_TEST_COMMANDS_ENABLED',
    'CSMS_COMMAND_WORKER_ENABLED','CSMS_REMOTE_AUTHORIZATION_ENABLED',
    'CSMS_SESSION_BRIDGE_ENABLED','CSMS_INGRESS_AUTH_ENABLED',
    'CSMS_INGRESS_ALLOW_LOCAL_HTTP','CSMS_RIDER_AUTH_ENABLED',
    'CSMS_SAFE_RESET_ENABLED','CSMS_SIGNED_FIRMWARE_ENABLED',
    'CSMS_FIRMWARE_ENABLED','CSMS_DIAGNOSTIC_LOGS_ENABLED',
    'CSMS_FAULT_WORKER_ENABLED','CSMS_CONFIGURATION_READ_ENABLED',
    'COMMERCIAL_CAMPAIGNS_ENABLED','DISCOVERY_GEOCODING_ENABLED',
    'DISCOVERY_ROUTING_ENABLED','EV_BATTERY_SIMULATOR_ENABLED',
  ];
  for (const name of disabledFlags) {
    if (Object.hasOwn(values,name) && values[name] !== 'false') {
      errors.push(name + ': isolated staging activation must remain false');
    }
  }
  for (const name of ['OCPI_CONNECTIONS_JSON','OCPI_LOCAL_CREDENTIALS_JSON']) {
    if (values[name] && values[name] !== '{}') {
      errors.push(name + ': external partner connections are not authorized in isolated staging');
    }
  }
  // A stray real PSP credential should never enter a synthetic staging container.
  for (const name of ['STRIPE_SECRET','STRIPE_LIVE_SECRET','STRIPE_SECRET_KEY',
    'PAYPAL_CLIENT_SECRET','AWS_SECRET_ACCESS_KEY']) {
    if (values[name]) errors.push(name + ': real provider credentials are not permitted in isolated staging');
  }
  for (const name of ['APP_KEY', 'DB_PASSWORD', 'STAGING_HOST', 'ACME_EMAIL', 'EV_IMAGE_TAG', 'STAGING_BIND_IP']) {
    if (values[name] && /\$\{|\$\(|\$[A-Za-z_][A-Za-z0-9_]*|\x60|[\r\n]/.test(values[name])) {
      errors.push(`${name}: interpolation or control syntax is not permitted`);
    }
  }
  if (values.EV_BUILD_CA_BUNDLE) {
    const bundle = values.EV_BUILD_CA_BUNDLE;
    if (!path.isAbsolute(bundle)) errors.push('EV_BUILD_CA_BUNDLE: must be an absolute trusted CA file path');
    else {
      try {
        if (!fs.statSync(bundle).isFile()) errors.push('EV_BUILD_CA_BUNDLE: not a regular file');
      } catch { errors.push('EV_BUILD_CA_BUNDLE: file is inaccessible'); }
    }
  }
  return errors;
}

function reviewFile(filePath) {
  const errors = [];
  let info;
  try {
    info = fs.lstatSync(filePath);
    if (!info.isFile() || info.isSymbolicLink()) errors.push('Staging .env: use a private regular file, never a symbolic link');
    if (process.platform !== 'win32' && (info.mode & 0o077) !== 0) {
      errors.push('Staging .env: restrict filesystem permissions to the owner (chmod 600)');
    }
  } catch {
    return { ok: false, errors: ['Staging .env: file not found or inaccessible'] };
  }
  if (errors.length || !info.isFile()) return { ok: false, errors };
  let source;
  try { source = fs.readFileSync(filePath, 'utf8'); }
  catch { return { ok: false, errors: ['Staging .env: cannot read private configuration'] }; }
  const parsed = parseEnv(source);
  errors.push(...parsed.errors, ...checkValues(parsed.values));
  // Compose gives exported shell variables precedence over .env; fail closed.
  const protectedKeys = ['APP_KEY', 'DB_PASSWORD', 'STAGING_HOST', 'ACME_EMAIL',
    'STAGING_BIND_IP', 'EV_IMAGE_TAG', 'APP_ENV', 'APP_DEBUG',
    'CSMS_SIMULATOR_ENABLED', 'EV_BUILD_CA_BUNDLE', 'MAIL_MAILER'];
  for (const name of protectedKeys) {
    if (Object.hasOwn(parsed.values, name) && Object.hasOwn(process.env, name) &&
        process.env[name] !== parsed.values[name]) {
      errors.push(name + ': inherited shell setting overrides reviewed .env; clear it before Compose');
    }
  }
  return { ok: errors.length === 0, errors };
}

function main(argv) {
  if (argv.length > 1 || (argv.length === 1 && argv[0] !== '--help' && argv[0].startsWith('--'))) {
    process.stderr.write('Usage: node scripts/staging-preflight.cjs [path/to/private/.env]\n');
    return 2;
  }
  if (argv[0] === '--help') {
    process.stdout.write('Offline, read-only staging .env safety review; no Docker, secrets disclosure or network calls.\n');
    return 0;
  }
  const file = argv[0] || path.resolve(__dirname, '../infra/staging/.env');
  const result = reviewFile(file);
  if (!result.ok) {
    for (const error of result.errors) process.stderr.write(`FAIL: ${error}\n`);
    return 1;
  }
  process.stdout.write('PASS: private staging settings and loopback exposure guard validated offline. No deployment, TLS issuance, hardware, payment or production acceptance verified.\n');
  return 0;
}

if (require.main === module) process.exitCode = main(process.argv.slice(2));
module.exports = { parseEnv, validDomain, validAppKey, checkValues, reviewFile, main };
