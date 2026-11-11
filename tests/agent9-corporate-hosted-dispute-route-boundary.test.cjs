'use strict';

// Supplemental source check: every int-typed external dispute, corporate
// billing and hosted payment ID must be bounded before dispatch.
// Laravel route tests assert actual matching for both authenticated mounts.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../services/business-api/routes');
const targets = new Map([
  ['corporate_billing.php', 10],
  ['hosted_checkout.php', 4],
  ['corporate_hosted_checkout.php', 4],
  ['external_disputes.php', 5],
]);
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('all 23 typed route constraints use bounded integer patterns', () => {
  let total = 0;
  for (const [file, count] of targets) {
    const src = read(file);
    assert.doesNotMatch(src, /->whereNumber\(/, file + ' has an unbounded numeric matcher');
    const constraints = [...src.matchAll(/->where\('[A-Za-z_]+', '\[0-9\]\{1,18\}'\)/g)];
    assert.equal(constraints.length, count, file + ' numeric constraint inventory changed');
    total += count;
  }
  assert.equal(total, 23);
});

test('hosted checkout reference, action names and dispute evidence IDs remain strictly controlled', () => {
  for (const file of ['hosted_checkout.php', 'corporate_hosted_checkout.php']) {
    const src = read(file);
    assert.ok(src.includes("->whereUuid('reference')"), file + ' maintains UUID-only external references');
    assert.ok(src.includes("['inspect', 'capture', 'cancel', 'simulate']"),
      file + ' must preserve the intended simulation/inspection actions');
    assert.match(src, /->where\('hosted', '\[0-9\]\{1,18\}'\)/);
  }
  const dispute = read('external_disputes.php');
  assert.match(dispute, /->where\('dispute', '\[0-9\]\{1,18\}'\)->where\('operation', '\[0-9\]\{1,18\}'\)/);
});

test('oversized or malformed integer strings fail the route-matcher policy', () => {
  const policy = /^[0-9]{1,18}$/;
  for (const id of ['0', '1', '100001', '9'.repeat(18)]) assert.match(id, policy);
  for (const bad of ['negative', '-1', '2.0', '1e19', '9'.repeat(19), '9'.repeat(120)]) {
    assert.doesNotMatch(bad, policy);
  }
});
