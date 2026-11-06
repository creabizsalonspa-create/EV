#!/usr/bin/env node
const fs = require("node:fs"),
  path = require("node:path"),
  assert = require("node:assert/strict");
const { randomBytes } = require("node:crypto");
const { spawn, execFileSync } = require("node:child_process");
const root = path.resolve(__dirname, ".."),
  base = path.join(root, "artifacts/client-demo");
process.chdir(root);
const mode = process.argv[2] || "status";
function write(file, value) {
  fs.writeFileSync(file, JSON.stringify(value, null, 2) + "\n", {
    mode: 0o600,
    flag: "wx",
  });
}
function owned(pid, manifest) {
  if (!Number.isInteger(pid) || pid <= 1) return false;
  try {
    process.kill(pid, 0);
    const command = fs.existsSync("/proc/" + pid + "/cmdline")
      ? fs.readFileSync("/proc/" + pid + "/cmdline", "utf8")
      : execFileSync("ps", ["-p", String(pid), "-o", "args="], {
          encoding: "utf8",
        });
    if (
      !command &&
      fs.existsSync("/proc/" + pid + "/stat") &&
      /^\d+ \(.+\) Z /.test(fs.readFileSync("/proc/" + pid + "/stat", "utf8"))
    )
      return false;
    assert(
      command.includes(path.join(root, "scripts/client-demo-charger.cjs")) &&
        command.includes(manifest),
      "PID belongs to another process.",
    );
    return true;
  } catch (e) {
    if (e.code === "ESRCH") return false;
    if (e.code === "ENOENT") return false;
    throw e;
  }
}
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
        owned(owner.pid, manifest) &&
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
    let pid = fs.existsSync(file)
      ? JSON.parse(fs.readFileSync(file, "utf8")).pid
      : null;
    let running = pid ? owned(pid, manifest) : false;
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
      write(file, { pid });
      running = true;
      let ready = false;
      for (let attempt = 0; attempt < 80; attempt++) {
        const readyFile = path.join(dir, "status-" + suffix + ".json");
        if (
          fs.existsSync(readyFile) &&
          JSON.parse(fs.readFileSync(readyFile, "utf8")).pid === pid &&
          JSON.parse(fs.readFileSync(readyFile, "utf8")).state === "idle"
        ) {
          ready = true;
          break;
        }
        if (!owned(pid, manifest))
          throw Error("Simulator did not remain running.");
        await new Promise((resolve) => setTimeout(resolve, 250));
      }
      assert(ready, "Simulator has not reached online idle readiness.");
    } else if (mode === "stop" && running) {
      process.kill(pid, "SIGTERM");
      // In-flight simulator RPCs have a 25s deadline, then a bounded socket close.
      for (let attempt = 0; attempt < 160 && owned(pid, manifest); attempt++)
        await new Promise((resolve) => setTimeout(resolve, 250));
      running = owned(pid, manifest);
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
main().catch(() => {
  console.error(
    "Local demo setup/state requires review. Private credentials and source details withheld.",
  );
  process.exitCode = 1;
});
