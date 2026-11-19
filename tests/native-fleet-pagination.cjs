'use strict';
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {mkdtempSync, writeFileSync, rmSync} = require('node:fs');
const {tmpdir} = require('node:os');
const {join} = require('node:path');
const base = new URL(process.env.EV_TEST_URL || 'http://127.0.0.1:8000');
assert(base.protocol === 'http:' && ['127.0.0.1','localhost'].includes(base.hostname) &&
  !base.username && !base.password && base.pathname === '/' && !base.search && !base.hash,
  'Owned loopback Laravel API required');
const directory = mkdtempSync(join(tmpdir(), 'ev-native-fleet-'));
try {
  const fixture = JSON.parse(execFileSync('./scripts/php', ['tests/Fixtures/native-fleet-pagination.php'], {encoding:'utf8'}));
  assert.equal(fixture.fixture_only, true);
  const file = join(directory, 'fixture.json');
  writeFileSync(file, JSON.stringify({...fixture,base:base.origin}), {mode:0o600});
  execFileSync('flutter', ['test','test/live_fleet_pagination_test.dart','--reporter','expanded'], {
    cwd:'apps/mobile',stdio:'inherit',env:{...process.env,EV_NATIVE_FLEET_FIXTURE:file},
  });
} finally {
  rmSync(directory,{recursive:true,force:true});
}
