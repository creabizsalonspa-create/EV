'use strict';

// Global route inventory: all historically integer-typed PHP route parameters
// should reject oversized numeric IDs before controller dispatch. The ONLY
// intentional exceptions are the two favorite actions, where string-based
// Laravel integer validation produces an established HTTP 422 response.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../services/business-api/routes');
const controllerRoot = path.resolve(root, '../app/Http/Controllers');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('all remaining whereNumber route constraints are favorite-validator exceptions', () => {
  const found = [];
  for (const file of fs.readdirSync(root).filter(file => file.endsWith('.php'))) {
    for (const match of read(file).matchAll(/->whereNumber\('([A-Za-z_]+)'\)/g)) {
      found.push(file + ':' + match[1]);
    }
  }
  assert.deepEqual(found.sort(), ['favorites.php:station', 'favorites.php:station'],
    'an unbounded int-typed route ID must not be introduced without a new explicit review');
});

test('favorites preserve validated HTTP 422 overflow behavior before any integer cast', () => {
  const favorites = read('favorites.php');
  assert.equal(favorites.split("->whereNumber('station')").length - 1, 2);
  const src = fs.readFileSync(path.join(controllerRoot, 'StationFavoriteController.php'), 'utf8');
  assert.match(src, /private function stationId\(string \$station\): int/);
  assert.match(src, /\['station_id' => 'required\|integer\|min:1'\]/);
  assert.match(src, /return \(int\) \$v\['station_id'\];/);
  assert.equal(src.split('->stationId($station)').length - 1, 2);
});

test('both OCPI location/model and sandbox order routes enforce safe numeric length', () => {
  const roaming = read('roaming_orders.php');
  assert.equal(roaming.split("->where('location', '[0-9]{1,18}')").length - 1, 2);
  assert.equal(roaming.split("->where('order', '[0-9]{1,18}')").length - 1, 4);
  const src = fs.readFileSync(path.join(controllerRoot, 'RoamingOrderController.php'), 'utf8');
  assert.match(src, /public function offers\(Request \$r, OcpiRecord \$location/);
  assert.match(src, /public function create\(Request \$r, OcpiRecord \$location/);
});
