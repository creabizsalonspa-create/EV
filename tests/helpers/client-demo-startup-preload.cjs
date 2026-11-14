// Explicitly isolated process/clock fixture. Never used by application/runtime code.
const fs = require('node:fs'), path = require('node:path'), Module = require('node:module');
const assert = require('node:assert/strict');
assert.equal(process.env.EV_STARTUP_TEST_ONLY, 'true');
const manifest = fs.realpathSync(process.env.EV_CLIENT_DEMO_MANIFEST || '');
const root = path.resolve(__dirname, '../..');
assert(manifest.startsWith(path.join(root, 'artifacts/client-demo/startup-proof-')));
assert.equal(fs.statSync(manifest).mode & 0o077, 0);
const fixture = JSON.parse(fs.readFileSync(manifest));
assert(fixture.fixture_only && fixture.startup_test_only && fixture.real_money === false);
const script = path.basename(process.argv[1] || '');
assert(script === 'client-demo-charger.cjs' || (script === 'client-demo.cjs' && ['start', 'stop', 'status'].includes(process.argv[2])));
global.fetch = () => { throw Error('Network forbidden in startup fixture'); };
global.WebSocket = class { constructor() { throw Error('Network forbidden in startup fixture'); } };
const RealDate = Date, realNow = Date.now.bind(Date), origin = Number(process.env.EV_OWNED_STARTUP_EPOCH);
assert(Number.isFinite(origin));
const now = () => origin + (realNow() - origin) * 10;
global.Date = class extends RealDate {
    constructor(...args) { super(...(args.length ? args : [now()])); }
    static now() { return now(); }
};
const timer = setTimeout;
global.setTimeout = (fn, ms, ...args) => timer(fn, ms / 10, ...args);
const load = Module._load;
Module._load = function(request, parent, main) {
    if (/csms-local\.cjs$/.test(request)) return {
        graph: () => { throw Error('No source reads in startup fixture'); },
        SimulatedCharger: class {
            constructor() { this.commands = []; }
            async connect() { this.socket = {readyState:1}; }
            // Valid within the unchanged 25-second RPC deadline, beyond the old supervisor limit.
            async boot() { await new Promise(resolve => setTimeout(resolve, 21000)); return {status:'Accepted'}; }
            async status() {}
            async call(action) { assert.equal(action, 'Heartbeat', 'Transactions forbidden in startup fixture'); return {}; }
            async close() { if (this.socket) this.socket.readyState = 3; }
        }
    };
    return load.call(this, request, parent, main);
};
