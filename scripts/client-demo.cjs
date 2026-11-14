#!/usr/bin/env node
const fs = require("node:fs"),
  path = require("node:path"),
  assert = require("node:assert/strict");
const { randomBytes } = require("node:crypto");
const { spawn, spawnSync, execFileSync } = require("node:child_process");
const { SIMULATOR_STARTUP_TIMEOUT_MS } = require('../tests/helpers/csms-timeouts.cjs');
const root = path.resolve(__dirname, ".."),
  base = path.join(root, "artifacts/client-demo");
process.chdir(root);
const mode = process.argv[2] || "status";
if (mode === "business") {
  const result = spawnSync(process.execPath,
    [path.join(__dirname, "client-demo-business.cjs"), ...process.argv.slice(3)],
    {stdio:"inherit"});
  process.exit(result.status ?? 1);
}
function write(file, value) {
  fs.writeFileSync(file, JSON.stringify(value, null, 2) + "\n", {
    mode: 0o600,
    flag: "wx",
  });
}
const processIdentity = require('../tests/helpers/owned-simulator-process.cjs');
const owned = (pid, manifest, startTicks = null) => processIdentity.owned(pid, manifest, root, startTicks);

async function main() {
  fs.mkdirSync(base, { recursive: true, mode: 0o700 });
  const current = path.join(base, "current.json");
  if (mode === "prepare") {
    const run = randomBytes(4).toString("hex"),
      dir = path.join(base, run);
    fs.mkdirSync(dir, { mode: 0o700 });
    const args = [
      "tests/Fixtures/client-demo.php",
      run,
      ...(process.argv.includes("--qa-current-slot")
        ? ["--qa-current-slot"]
        : []),
    ];
    const data = JSON.parse(
      execFileSync("./scripts/php", args, { encoding: "utf8" }),
    );
    assert(data.fixture_only && !data.real_money);
    const file = path.join(dir, "access.json");
    write(file, data);
    fs.writeFileSync(current, JSON.stringify({ manifest: file }) + "\n", {
      mode: 0o600,
    });
    console.log(
      "Private synthetic accounts and lab mappings prepared: " + file,
    );
    if (data.qa_current_bookings)
      console.log(
        "Explicit local QA current-slot bookings were seeded; this is not a customer booking acceptance test.",
      );
    console.log(
      "Normal demonstrations must create future bookings in the applications; no booking window is bypassed.",
    );
    return;
  }
  assert(["start", "stop", "status", "swipe"].includes(mode));
  const supplied = process.env.EV_CLIENT_DEMO_MANIFEST;
  const manifest = fs.realpathSync(
    supplied || JSON.parse(fs.readFileSync(current, "utf8")).manifest,
  );
  assert(
    manifest.startsWith(base + path.sep) &&
      (fs.statSync(manifest).mode & 0o077) === 0,
  );
  const data = JSON.parse(fs.readFileSync(manifest, "utf8"));
  assert(data.fixture_only && !data.real_money);
  const dir = path.dirname(manifest);
  if (mode === "swipe") {
    const protocol = process.argv[3],
      type = process.argv[4] || "ISO14443";
    assert(
      ["1.6", "2.0.1"].includes(protocol) &&
        ["ISO14443", "ISO15693", "Central"].includes(type),
    );
    const device = JSON.parse(
      fs.readFileSync(
        path.join(dir, "status-" + protocol.replaceAll(".", "") + ".json"),
        "utf8",
      ),
    );
    const owner = JSON.parse(
      fs.readFileSync(
        path.join(dir, "pid-" + protocol.replaceAll(".", "") + ".json"),
        "utf8",
      ),
    );
    assert(
      device.state === "idle" &&
        device.pid === owner.pid &&
        owned(owner.pid, manifest, owner.start_ticks ?? null) &&
        Date.now() - Date.parse(device.observed_at) >= 0 &&
        Date.now() - Date.parse(device.observed_at) <= 35000,
      "An idle fresh device is required for a swipe.",
    );
    const chunks = [];
    for await (const chunk of process.stdin) chunks.push(chunk);
    const token = Buffer.concat(chunks).toString("utf8").trim();
    assert(token.length > 0 && token.length <= (protocol === "1.6" ? 20 : 36));
    write(path.join(dir, "swipe-" + protocol.replaceAll(".", "") + ".json"), {
      token,
      type,
      queued_at: Date.now(),
    });
    console.log(
      "One local managed-card swipe queued; current online authority is still required.",
    );
    return;
  }
  for (const station of data.stations) {
    const suffix = station.protocol.replaceAll(".", ""),
      file = path.join(dir, "pid-" + suffix + ".json");
    const recorded = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, "utf8")) : {};
    let pid = recorded.pid ?? null, startTicks = recorded.start_ticks ?? null;
    let running = pid ? owned(pid, manifest, startTicks) : false;
    if (mode === "start" && !running) {
      if (fs.existsSync(file)) fs.unlinkSync(file);
      const journal = path.join(dir, "device-" + suffix + ".json");
      assert(
        !fs.existsSync(journal) ||
          JSON.parse(fs.readFileSync(journal, "utf8")).phase === "idle",
        "Unresolved device state must be reconciled; never remove its journal.",
      );
      const log = fs.openSync(
        path.join(dir, "simulator-" + suffix + ".log"),
        "a",
        0o600,
      );
      const child = spawn(
        process.execPath,
        [
          path.join(root, "scripts/client-demo-charger.cjs"),
          manifest,
          station.protocol,
        ],
        { cwd: root, detached: true, stdio: ["ignore", log, log] },
      );
      fs.closeSync(log);
      child.unref();
      pid = child.pid;
      startTicks = processIdentity.startTicks(pid);
      write(file, { pid, start_ticks: startTicks });
      running = true;
      let ready = false;
      // Connection, BootNotification and initial status each have their own
      // existing transport deadline. Readiness must allow those valid replies.
      const readinessDeadline = Date.now() + SIMULATOR_STARTUP_TIMEOUT_MS;
      while (Date.now() < readinessDeadline) {
        const readyFile = path.join(dir, "status-" + suffix + ".json");
        if (fs.existsSync(readyFile)) {
          const observed = JSON.parse(fs.readFileSync(readyFile, 'utf8'));
          if (observed.pid === pid) {
            assert(observed.state !== 'requires_operator_review', 'Simulator requires source/operator review.');
            const age = Date.now() - Date.parse(observed.observed_at);
            if (observed.state === 'idle' && age >= 0 && age <= 35000) {
              ready = true;
              break;
            }
          }
        }
        if (!owned(pid, manifest, startTicks))
          throw Error("Simulator did not remain running.");
        await new Promise((resolve) => setTimeout(resolve, 250));
      }
      assert(ready, "Simulator has not reached online idle readiness.");
    } else if (mode === "stop" && running) {
      process.kill(pid, "SIGTERM");
      // In-flight simulator RPCs have a 25s deadline, then a bounded socket close.
      for (let attempt = 0; attempt < 160 && owned(pid, manifest, startTicks); attempt++)
        await new Promise((resolve) => setTimeout(resolve, 250));
      running = owned(pid, manifest, startTicks);
      assert(!running, "Simulator shutdown requires review.");
    }
    const stateFile = path.join(dir, "status-" + suffix + ".json");
    const state = fs.existsSync(stateFile)
      ? JSON.parse(fs.readFileSync(stateFile, "utf8")).state
      : "not_observed";
    console.log(
      JSON.stringify({
        protocol: station.protocol,
        station_id: station.station_id,
        running,
        state,
        physical_charging_verified: false,
      }),
    );
  }
}
main().catch((error) => {
  // Fixed operator reason codes only; never emit errors, manifests, tokens or journals.
  const reasons = new Map([
    ['Unresolved device state must be reconciled; never remove its journal.', 'unresolved_journal'],
    ['PID belongs to another process.', 'foreign_process'],
    ['Simulator did not remain running.', 'child_exited'],
    ['Simulator has not reached online idle readiness.', 'readiness_unobserved'],
    ['Simulator shutdown requires review.', 'shutdown_incomplete'],
    ['Simulator requires source/operator review.', 'source_review_required'],
  ]);
  console.error(JSON.stringify({component:'local_demo', action:['prepare','start','stop','status','swipe'].includes(mode) ? mode : 'unknown', failure_code:reasons.get(error?.message) || 'operator_review_required', commands_replayed:false, physical_charging_verified:false}));
  console.error(
    "Local demo setup/state requires review. Private credentials and source details withheld.",
  );
  process.exitCode = 1;
});
