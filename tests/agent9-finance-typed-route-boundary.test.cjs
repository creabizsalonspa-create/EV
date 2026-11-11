'use strict';

// Read-only source regression: sensitive finance routes must not expose
// unbounded numeric IDs to int-typed PHP controller actions.
// Laravel Feature route-matching tests independently verify runtime behavior.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../services/business-api/routes');
const targets = new Map([
  ['payouts.php', 7],
  ['settlements.php', 7],
  ['subscription_gateway.php', 3],
  ['financial_documents.php', 2],
]);
const source = new Map([...targets.keys()].map(file =>
  [file, fs.readFileSync(path.join(root, file), 'utf8')]
));

test('all nineteen sensitive finance parameter routes are length-bounded', () => {
  let count = 0;
  for (const [file, expected] of targets) {
    const text = source.get(file);
    assert.doesNotMatch(text, /->whereNumber\(/, file + ': no unbounded numeric matcher');
    const lines = text.split(/\r?\n/).filter(line => line.startsWith('Route::') &&
      line.includes("'[0-9]{1,18}'"));
    assert.equal(lines.length, expected, file + ': maintain explicit route inventory');
    for (const line of lines) {
      assert.match(line, /->where\('[a-z_]+', '\[0-9\]\{1,18\}'\)/,
        file + ': every integer parameter must have a bound');
      count++;
    }
  }
  assert.equal(count, 19);
});

test('finance route action allowlists and document format restrictions survive', () => {
  assert.match(source.get('payouts.php'), /whereIn\('action', \['verify', 'revoke'\]\)/);
  assert.match(source.get('payouts.php'), /whereIn\('action', \['approve', 'execute', 'reconcile', 'retry', 'cancel'\]\)/);
  assert.match(source.get('settlements.php'), /whereIn\('action', \['approve', 'reject', 'simulate-payout'\]\)/);
  assert.match(source.get('subscription_gateway.php'), /->where\('operation', 'authorize\|capture\|cancel'\)/);
  assert.match(source.get('financial_documents.php'), /->where\('format', 'pdf\|csv'\)/);
});

test('numeric length bound rejects oversized and malformed identifiers', () => {
  const matcher = /^[0-9]{1,18}$/;
  for (const id of ['0', '1', '123456', '9'.repeat(18)]) assert.match(id, matcher);
  for (const id of ['-1', '1e19', 'nan', '2.3', '9'.repeat(19), '9'.repeat(120)]) {
    assert.doesNotMatch(id, matcher);
  }
});
