// CI owns these disposable workers. Never stop a developer's running services.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const { execFileSync } = require("node:child_process");
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const run = (file, args = [], env = {}) =>
  execFileSync(file, args, {
    stdio: "inherit",
    env: { ...process.env, ...env },
  });
async function main() {
  for (const name of [
    "ev-csms-command-worker",
    "ev-csms-source-worker",
    "ev-csms-fault-worker",
  ]) {
    const result = require("node:child_process").spawnSync(
      "docker",
      ["inspect", "--format", "{{.State.Running}}", name],
      { encoding: "utf8" },
    );
    assert(
      result.status !== 0 || result.stdout.trim() !== "true",
      "Existing running workers must remain owned by their operator.",
    );
  }
  let manifest;
  try {
    run("./scripts/charging-workers", ["start"]);
    for (const flags of [[], ["--rfid"]]) {
      // Avoid crossing a reservation boundary during fixture setup, never change the server clock/window.
      while (1800000 - (Date.now() % 1800000) < 180000) await sleep(30000);
      run("./scripts/client-demo", ["prepare", "--qa-current-slot"]);
      manifest = JSON.parse(
        fs.readFileSync("artifacts/client-demo/current.json"),
      ).manifest;
      const env = { EV_CLIENT_DEMO_MANIFEST: manifest };
      run("./scripts/client-demo", ["start"], env);
      run(process.execPath, ["tests/client-demo.cjs", ...flags], env);
      run("./scripts/client-demo", ["stop"], env);
      // An idle restart retains the durable meter and never replays a transaction.
      run("./scripts/client-demo", ["start"], env);
      run("./scripts/client-demo", ["stop"], env);
      manifest = null;
    }
  } finally {
    try {
      if (manifest)
        run("./scripts/client-demo", ["stop"], {
          EV_CLIENT_DEMO_MANIFEST: manifest,
        });
    } finally {
      run("./scripts/charging-workers", ["stop"]);
    }
  }
}
main().catch(() => {
  console.error(
    "Owned local demo lifecycle failed; unresolved source journals retained.",
  );
  process.exitCode = 1;
});
