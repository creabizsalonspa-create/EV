'use strict';

// Source-only complement to RefundReviewRouteBoundaryTest (real Laravel).
// This guards an int-typed finance action that historically had NO
// whereNumber matcher and so escaped previous numeric-route inventories.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const app = path.resolve(__dirname, '../services/business-api');
const routes = fs.readFileSync(path.join(app, 'routes/commercial_finance.php'), 'utf8');
const controller = fs.readFileSync(
  path.join(app, 'app/Http/Controllers/CommercialFinanceController.php'), 'utf8'
);
const line = "Route::post('/finance/refund-requests/{request}/review', [C::class, 'review'])";

test('refund-review integer IDs reject overflow before typed PHP controller dispatch', () => {
  const matches = routes.split(/\r?\n/).filter(value => value.startsWith(line));
  assert.equal(matches.length, 1, 'the API refund review route must remain unique');
  assert.equal(matches[0],
    line + "->where('request', '[0-9]{1,18}');");
  assert.match(controller, /public function review\(Request \$r, int \$request,/);
});

test('refund-review policy rejects nonnumeric and longer-than-18-digit IDs', () => {
  const policy = /^[0-9]{1,18}$/;
  for (const valid of ['1', '10001', '9'.repeat(18)]) assert.match(valid, policy);
  for (const bad of ['-1', '1.0', '1e19', 'not-an-id', '9'.repeat(19), '9'.repeat(120)]) {
    assert.doesNotMatch(bad, policy);
  }
});
