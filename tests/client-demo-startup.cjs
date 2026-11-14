const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { CONNECT_TIMEOUT_MS, RPC_TIMEOUT_MS, SIMULATOR_STARTUP_TIMEOUT_MS } = require('./helpers/csms-timeouts.cjs');
const root = path.resolve(__dirname, '..'), base = path.join(root, 'artifacts/client-demo');
assert(SIMULATOR_STARTUP_TIMEOUT_MS >= CONNECT_TIMEOUT_MS + 2 * RPC_TIMEOUT_MS);
assert(SIMULATOR_STARTUP_TIMEOUT_MS <= 75000, 'Startup supervision must remain finitely bounded.');
fs.mkdirSync(base, {recursive:true,mode:0o700});
const directory = fs.mkdtempSync(path.join(base, 'startup-proof-'));
fs.chmodSync(directory, 0o700);
const manifest = path.join(directory, 'access.json');
const stations = [
    {protocol:'1.6',identity:'ev-test-demo-fe11aa22-16',station_id:9000001},
    {protocol:'2.0.1',identity:'ev-test-demo-fe11aa22-201',station_id:9000002}
];
const env = {...process.env,EV_STARTUP_TEST_ONLY:'true',EV_CLIENT_DEMO_MANIFEST:manifest,EV_OWNED_STARTUP_EPOCH:String(Date.now()),NODE_OPTIONS:'--require '+path.join(__dirname, 'helpers/client-demo-startup-preload.cjs')};
fs.writeFileSync(manifest, JSON.stringify({fixture_only:true,startup_test_only:true,real_money:false,base:'http://127.0.0.1:8000',stations}), {mode:0o600});
for (const station of stations) fs.writeFileSync(path.join(directory, 'device-'+station.protocol.replaceAll('.','')+'.json'), JSON.stringify({phase:'idle',meter_wh:1045}), {mode:0o600});
const run = mode => spawnSync('./scripts/client-demo', [mode], {cwd:root,env,encoding:'utf8',timeout:20000});
try {
    for (let cycle = 0; cycle < 2; cycle++) {
        const started = run('start');
        assert.equal(started.status, 0, started.stderr);
        const rows = started.stdout.trim().split('\n').map(line => JSON.parse(line));
        assert.equal(rows.length, 2);
        assert(rows.every(row => row.running === true && row.state === 'idle' && row.physical_charging_verified === false));
        const stopped = run('stop'); assert.equal(stopped.status, 0, stopped.stderr);
        for (const station of stations) {
            const journal = JSON.parse(fs.readFileSync(path.join(directory, 'device-'+station.protocol.replaceAll('.','')+'.json')));
            assert.equal(journal.phase, 'idle'); assert.equal(journal.meter_wh, 1045);
        }
    }
    const journal = path.join(directory, 'device-16.json');
    fs.writeFileSync(journal, JSON.stringify({phase:'charging',meter_wh:1045}), {mode:0o600});
    const blocked = run('start');
    assert.notEqual(blocked.status, 0);
    const reason = JSON.parse(blocked.stderr.split('\n').find(line => line.startsWith('{')));
    assert.equal(reason.failure_code, 'unresolved_journal');
    assert.equal(reason.commands_replayed, false);
    assert.equal(JSON.parse(fs.readFileSync(journal)).phase, 'charging');
    console.log('Owned dual-protocol startup/restart accepts valid slow boot, preserves meters and refuses unresolved journals; network/transactions forbidden.');
} finally {
    const stopped = run('stop');
    if (stopped.status !== 0) throw Error('Owned startup fixture cleanup requires review; private files retained.');
    fs.rmSync(directory, {recursive:true});
}
