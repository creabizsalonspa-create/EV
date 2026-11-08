'use strict';

// Dependency-free source guard. Laravel Feature runtime tests must also
// confirm /api and /api/v1 route matching and actual public catalog 404s.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../services/business-api/routes');

const files = Object.fromEntries(['charging_reviews.php', 'favorites.php', 'api.php', 'web.php']
  .map(name => [name, fs.readFileSync(path.join(root, name), 'utf8')]));
const lines = name => files[name].split(/\r?\n/);
const bound = line => line.includes("->where('") && line.includes("', '[0-9]{1,18}')");
function find(name, segment) {
  const matches = lines(name).filter(line => line.includes(segment) && line.startsWith('Route::'));
  assert.equal(matches.length, 1, name + ': one route for ' + segment);
  return matches[0];
}

test('all five charging-review ID constraints are bounded', () => {
  const checks = [
    ['charging_reviews.php', 'sessions/{session}', 'session'],
    ['charging_reviews.php', 'Route::put', 'review'],
    ['charging_reviews.php', 'Route::delete', 'review'],
    ['charging_reviews.php', '{review}/history', 'review'],
    ['charging_reviews.php', '{review}/moderate', 'review'],
  ];
  for (const [name, marker, param] of checks) {
    const line = find(name, marker);
    assert.ok(bound(line), name + ' ' + marker);
    assert.ok(line.includes("->where('" + param + "', '[0-9]{1,18}')"));
  }
  assert.doesNotMatch(files['charging_reviews.php'], /whereNumber\(/);
});

test('favorites retain integer validation and the existing overflow 422 API contract', () => {
  // Favorites accept a string param and validate it before converting to int.
  // Removing overlong digits at the router would change established HTTP 422
  // behavior to 404 (see StationFavoriteTest), so the defense stays here.
  for (const verb of ['Route::put', 'Route::delete']) {
    const line = find('favorites.php', verb);
    assert.ok(line.includes("->whereNumber('station')"));
  }
  const controller = fs.readFileSync(path.join(root, '../app/Http/Controllers/StationFavoriteController.php'), 'utf8');
  assert.match(controller, /private function stationId\(string \$station\): int/);
  assert.match(controller, /\['station_id' => 'required\|integer\|min:1'\]/);
  assert.match(controller, /return \(int\) \$v\['station_id'\];/);
  assert.equal(controller.split('->stationId($station)').length - 1, 2);
});

test('both public station-review routes retain catalog throttling and bounded station IDs', () => {
  for (const [file, uri] of [
    ['api.php', "/v1/stations/{station}/reviews"],
    ['web.php', "/api/stations/{station}/reviews"],
  ]) {
    const line = find(file, uri);
    assert.ok(line.includes("->where('station', '[0-9]{1,18}')"));
    assert.match(line, /middleware\('throttle:catalog'\)/);
  }
});

test('hosted checkout simulator pages remain authenticated and reject overflowing IDs', () => {
  const web = files['web.php'];
  const matches = [...web.matchAll(/\}\)->where\('checkout', '\[0-9\]\{1,18\}'\)->middleware\('auth'\);/g)];
  assert.equal(matches.length, 2, 'both simulator checkout pages require auth and bounded IDs');
  for (const marker of ["/payments/hosted-simulation/{checkout}", "/payments/corporate-hosted-simulation/{checkout}"]) {
    assert.equal(web.split(marker).length - 1, 1, marker);
  }
  const re = /^[0-9]{1,18}$/;
  assert.match('12345', re);
  for (const bad of ['-1', '1e300', '9'.repeat(19), '9'.repeat(100)]) assert.doesNotMatch(bad, re);
});
