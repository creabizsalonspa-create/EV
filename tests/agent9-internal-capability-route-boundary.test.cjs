'use strict';

// Offline canary for untrusted internal upload and signed-firmware paths.
// RouteConstraintIntegrityTest and Laravel route-matching tests are required
// to check the actual framework matcher independently.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const api = fs.readFileSync(path.join(__dirname, '../services/business-api/routes/api.php'), 'utf8');
const source = api.split(/\r?\n/).map(line => line.trim());
function route(method, uri) {
  const start = 'Route::' + method + "('" + uri + "',";
  const found = source.filter(line => line.startsWith(start));
  assert.equal(found.length, 1, 'expected one ' + method + ' ' + uri);
  return found[0];
}

test('internal signed firmware GET accepts only 64 lowercase hexadecimal token bytes', () => {
  const line = route('get', '/internal/firmware/{token}');
  assert.ok(line.includes("->where('token', '[a-f0-9]{64}')"));
  assert.match(line, /middleware\(['"]throttle:30,1['"]\)/);
  const pattern = /^[a-f0-9]{64}$/;
  assert.match('a'.repeat(64), pattern);
  assert.match('09'.repeat(32), pattern);
  for (const bad of ['a'.repeat(63), 'a'.repeat(65), 'g'.repeat(64), 'A'.repeat(64), '9'.repeat(120)]) {
    assert.doesNotMatch(bad, pattern);
  }
});

test('internal diagnostic upload integer route rejects oversized IDs and retains throttling', () => {
  const line = route('put', '/internal/diagnostic-logs/{operation}');
  assert.ok(line.includes("->where('operation', '[0-9]{1,18}')"));
  assert.match(line, /middleware\(['"]throttle:30,1['"]\)/);
  const pattern = /^[0-9]{1,18}$/;
  assert.match('1', pattern);
  assert.doesNotMatch('9'.repeat(19), pattern);
  assert.doesNotMatch('-1', pattern);
});

test('no known exception remains for ineffective route parameter constraints', () => {
  const content = fs.readFileSync(path.join(
    __dirname, '../services/business-api/tests/Feature/RouteConstraintIntegrityTest.php'
  ), 'utf8');
  assert.doesNotMatch(content, /\$knownOwnerIssue/);
  assert.match(content, /assertSame\(\[\],\s*\$misconfigured/);
});
