'use strict';

// Source-only canary for the real firmware-release revocation handler.
// A normal PHP closure requires every outer variable to appear in use (...).
// Laravel authenticated HTTP and device-side behavior still require testing.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const file = path.resolve(__dirname, '../services/business-api/app/Http/Controllers/ChargerFirmwareController.php');
const src = fs.readFileSync(file, 'utf8');

function bodyOf(name) {
  const start = src.indexOf('public function ' + name + '(');
  assert.notEqual(start, -1, name + ' must remain available');
  const end = src.indexOf('\n    public function ', start + 1);
  return src.slice(start, end < 0 ? undefined : end);
}

test('firmware revocation captures its validated encrypted audit note inside the database transaction', () => {
  const body = bodyOf('revoke');
  assert.match(body, /\$v\s*=\s*\$r->validate\(\['note'/);
  assert.match(body, /DB::transaction\(function\s*\(\)\s*use\s*\(([^)]*,\s*)?\$v\s*\)/,
    'validated note must be captured, rather than referenced as an undefined variable');
  assert.match(body, /Crypt::encryptString\(\$v\['note'\]\)/);
  assert.match(body, /['"]revoked_at['"]\s*=>\s*now\(\)/);
  assert.match(body, /firmware\.release_revoked/);
  assert.doesNotMatch(body, /Http::|\->(?:post|put|patch|delete)\s*\(/,
    'revoking a release must not issue an immediate device command');
});
