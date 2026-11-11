'use strict';

// Source-only canary: every pre-existing numeric charger-operation route
// must reject unbounded digit strings *before* a typed controller action.
// The full authenticated HTTP/tenant tests remain independently required.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(
  path.join(__dirname, '../services/business-api/routes/vendor_ops.php'), 'utf8'
);
const guarded = src.split(/\r?\n/).filter(line => line.startsWith('Route::') && line.includes('[0-9]{1,18}'));

test('all eleven charger-operation numeric routes retain a bounded integer matcher', () => {
  assert.equal(guarded.length, 11, 'review new charger ID paths before changing the audit inventory');
  assert.doesNotMatch(src, /->whereNumber\(/, 'unbounded route digit constraints must not return');
  const actions = [
    ['delete', '/companies/{company}/chargers/{charger}/connectors/{connector}/maintenance-windows/{window}', 'window'],
    ['post', '/companies/{company}/chargers/{charger}/configuration-reads/{operation}/observe', 'operation'],
    ['patch', '/companies/{company}/chargers/{charger}/fault-alerts/{alert}/acknowledge', 'alert'],
    ['post', '/companies/{company}/chargers/{charger}/reset-operations/{operation}/review', 'operation'],
    ['delete', '/companies/{company}/firmware-releases/{release}', 'release'],
    ['post', '/companies/{company}/chargers/{charger}/firmware-operations/{operation}/review', 'operation'],
    ['post', '/companies/{company}/chargers/{charger}/firmware-operations/{operation}/cancel', 'operation'],
    ['get', '/companies/{company}/chargers/{charger}/repair-orders/{order}/history', 'order'],
    ['post', '/companies/{company}/chargers/{charger}/diagnostic-logs/{operation}/observe', 'operation'],
    ['get', '/companies/{company}/chargers/{charger}/diagnostic-logs/{operation}/download', 'operation'],
    ['delete', '/companies/{company}/chargers/{charger}/diagnostic-logs/{operation}', 'operation'],
  ];
  for (const [method, uri, param] of actions) {
    const prefix = 'Route::' + method + "('" + uri + "',";
    const matches = guarded.filter(line => line.startsWith(prefix));
    assert.equal(matches.length, 1, method + ' ' + uri);
    assert.ok(matches[0].includes("->where('" + param + "', '[0-9]{1,18}')"), uri);
  }
});

test('bounded numeric policy accepts normal IDs but rejects overflow and malformed IDs', () => {
  const allowed = /^[0-9]{1,18}$/;
  for (const id of ['0', '1', '301', '9'.repeat(18)]) assert.match(id, allowed);
  for (const id of ['-1', '1.0', '1e300', 'text', '9'.repeat(19), '9'.repeat(500)]) {
    assert.doesNotMatch(id, allowed);
  }
});
