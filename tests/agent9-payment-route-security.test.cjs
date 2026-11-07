'use strict';

// Dependency-free source guard for all externally reachable payment webhooks.
// Supplemental to Laravel feature tests; signature verification still
// requires a running Laravel application before production integration.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const routeRoot = path.join(root, 'services/business-api/routes');
const providerFile = path.join(root, 'services/business-api/app/Services/Commercial/Payments/StripeSandboxProvider.php');
const gatewayFile = path.join(root, 'services/business-api/app/Services/Commercial/Payments/GatewayService.php');
const hooks = Object.freeze([
  ['api.php', '/payments/mock/webhook'],
  ['api.php', '/payments/stripe-test/webhook'],
  ['subscription_webhooks.php', '/subscription-payments/stripe-test/webhook'],
  ['subscription_credit_webhooks.php', '/subscription-credit-payments/stripe-test/webhook'],
  ['api.php', '/payment-enrollments/stripe-test/webhook'],
  ['corporate_hosted_webhook.php', '/corporate-payments/stripe-test/webhook'],
  ['external_dispute_webhooks.php', '/disputes/stripe-test/webhook'],
  ['payout_webhooks.php', '/webhooks/payouts/stripe-test'],
]);

const load = (file) => fs.readFileSync(file, 'utf8');

test('all eight external payment webhook routes use POST plus a bounded throttle', () => {
  const seen = new Set();
  for (const [file, uri] of hooks) {
    assert.ok(!seen.has(uri), `duplicate known webhook URI ${uri}`);
    seen.add(uri);
    const source = load(path.join(routeRoot, file));
    const escaped = uri.replace(/[.*+?^$()|[\]\\{}]/g, '\\$&');
    const declaration = new RegExp(
      `^[ \\t]*Route::post\\(['"]${escaped}['"],[^\\r\\n]*\\)->middleware\\(['"]throttle:([A-Za-z0-9_,-]+)['"]\\)[;]?[ \\t]*$`,
      'gm',
    );
    const match = source.match(declaration);
    assert.ok(match && match.length === 1, `${file} must define exactly one throttled POST ${uri}`);
    assert.ok(!source.includes(`Route::get('${uri}'`), `${uri} must never be a GET operation`);
    assert.ok(!source.includes(`Route::match(['GET'`), `${uri} must never expose GET`);
  }
  assert.equal(seen.size, 8);
});

test('test-mode payment providers cannot accept unsigned or live-mode provider evidence', () => {
  const stripe = load(providerFile);
  const gateway = load(gatewayFile);
  const verify = stripe.slice(stripe.indexOf('public function verifyWebhook('));
  assert.ok(verify.startsWith('public function verifyWebhook('), 'provider signature verifier exists');
  assert.match(verify, /environment\(['"]production['"]\)/, 'live production webhook verification must be disabled');
  assert.match(verify, /whsec_/, 'Stripe test key must be explicitly scoped');
  assert.match(verify, /hash_hmac\(['"]sha256['"]/, 'raw webhook signature uses HMAC');
  assert.match(verify, /hash_equals\(/, 'signature comparison is timing-safe');
  assert.match(verify, /abs\(time\(\)\s*-\s*\(int\)\s*\$timestamp\)\s*<=\s*300/, 'webhook timestamp has five-minute tolerance');
  assert.match(verify, /livemode['"]?\]\s*\?\?\s*null\)\s*===\s*false/, 'reject live-mode event');
  assert.match(verify, /abort_if\(strlen\(\$raw\)\s*>\s*65536/, 'bound raw body size');
  assert.match(gateway, /config\(['"]payments\.provider['"]\)\s*===\s*['"]mock['"]/, 'mock event path must select mock provider');
  assert.match(gateway, /hash_equals\(hash_hmac\(['"]sha256['"]/, 'mock webhook uses verified HMAC');
});
