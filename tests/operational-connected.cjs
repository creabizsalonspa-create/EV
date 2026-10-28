// Preserve every original post-finance suite and source flag. Fresh windows
// prevent preceding commercial logins from exhausting charging operations.
const assert = require("node:assert/strict");
const { runPacedSuites } = require("./helpers/auth-batches.cjs");
const groups = [
  ["invoice-history", "money-input", "tariffs", "managed-gate"], // 3+0+4+2 = 9 logins
  [
    "canonical-topology",
    "reservation-lifecycle",
    "command-recovery",
    "charger-ingress",
    "charger-operations",
  ], // 2+1+1+0+1 = 5
  ["charging-business", "station-favorites"], // 3 registrations +2 registrations = 5
];
if (require.main === module) {
  const { spawnSync } = require("node:child_process");
  const fs = require("node:fs");
  fs.mkdirSync("artifacts/operational-browser", { recursive: true });
  runPacedSuites(groups, {
    wait: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    run: (suite) => {
      const extra =
        suite === "charging-business"
          ? {
              EV_CANONICAL_TARGETS: "1",
              EV_CSMS_CONSUMER: "1",
              EV_CSMS_DAEMON: "1",
            }
          : {};
      const result = spawnSync(process.execPath, ["tests/" + suite + ".cjs"], {
        encoding: "utf8",
        env: { ...process.env, ...extra },
        maxBuffer: 4 * 1024 * 1024,
      });
      const output = (result.stdout || "") + (result.stderr || "");
      fs.writeFileSync(
        "artifacts/operational-browser/" + suite + ".txt",
        output,
      );
      process.stdout.write(output);
      if (result.error) throw result.error;
      assert.equal(
        result.status,
        0,
        "Operational suite failed; no assertion retry: " + suite,
      );
    },
  }).catch((e) => {
    console.error(e);
    process.exitCode = 1;
  });
}
module.exports = { groups };
