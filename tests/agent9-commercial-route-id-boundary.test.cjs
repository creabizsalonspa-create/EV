'use strict';

// Static defense-in-depth. Laravel authenticated HTTP tests must independently
// verify malformed IDs return 404 with no DB or gateway-side effects.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../services/business-api/routes/payments.php'), 'utf8');
const routes = source.split(/\r?\n/).map(line => line.trim()).filter(line => line.startsWith('Route::'));
function route(method, uri) {
  const expected = 'Route::' + method + "('" + uri + "',";
  const candidates = routes.filter(line => line.startsWith(expected));
  assert.equal(candidates.length, 1, 'exactly one ' + method + ' ' + uri);
  return candidates[0];
}
function bounded(line, param) {
  const guard = "->where('" + param + "', '[0-9]{1,18}')";
  assert.ok(line.includes(guard), 'integer ID must reject oversized digit strings: ' + param);
}

test('payment mutations use bounded integer IDs while preserving the operation allowlist', () => {
  const checkout = route('post', '/commercial/checkouts/{checkout}/{operation}');
  bounded(checkout, 'checkout');
  assert.match(checkout, /->where\(['"]operation['"],\s*['"]authorize\|capture\|cancel\|refund['"]\)/);
  bounded(route('post', '/commercial/cases/{case}/resolve'), 'case');
});

test('invoice reconciliation stays a bounded GET', () => {
  bounded(route('get', '/commercial/reconciliation/{invoice}'), 'invoice');
});

test('private evidence lists and file IDs cannot reach typed controllers with oversized digits', () => {
  for (const method of ['get', 'post']) {
    bounded(route(method, '/commercial/cases/{case}/evidence'), 'case');
  }
  const detail = route('get', '/commercial/cases/{case}/evidence/{evidence}');
  bounded(detail, 'case');
  bounded(detail, 'evidence');
});

test('numeric route policy admits normal IDs but excludes overflow and nonnumeric input', () => {
  const policy = /^[0-9]{1,18}$/;
  for (const value of ['0', '1', '123456', '9'.repeat(18)]) assert.match(value, policy);
  for (const value of ['9'.repeat(19), '9'.repeat(100), '-1', '1.0', '1e300', 'abc', '']) {
    assert.doesNotMatch(value, policy);
  }
});
