'use strict';

// Static defense-in-depth. Laravel HTTP tests must separately assert malformed
// IDs produce 404 on authenticated /api and /api/v1 routes without DB writes.
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

test('typed payment mutation IDs are route-constrained before controller dispatch', () => {
  const checkout = route('post', '/commercial/checkouts/{checkout}/{operation}');
  assert.match(checkout, /->whereNumber\(['"]checkout['"]\)/);
  assert.match(checkout, /->where\(['"]operation['"],\s*['"]authorize\|capture\|cancel\|refund['"]\)/);
  assert.doesNotMatch(checkout, /whereUuid\(/);
  const resolve = route('post', '/commercial/cases/{case}/resolve');
  assert.match(resolve, /->whereNumber\(['"]case['"]\)/);
});

test('typed invoice reconciliation ID is numeric and remains GET-only', () => {
  const reconcile = route('get', '/commercial/reconciliation/{invoice}');
  assert.match(reconcile, /->whereNumber\(['"]invoice['"]\)/);
  assert.doesNotMatch(reconcile, /Route::post|Route::match/);
});

test('private case evidence IDs stay numeric at both path levels', () => {
  const detail = route('get', '/commercial/cases/{case}/evidence/{evidence}');
  assert.match(detail, /whereNumber\(\[['"]case['"],\s*['"]evidence['"]\]\)/);
});
