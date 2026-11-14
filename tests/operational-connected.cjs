// Preserve every original post-finance suite and source flag. Fresh windows
// prevent preceding commercial logins from exhausting charging operations.
const assert = require("node:assert/strict");
const { runPacedSuites } = require("./helpers/auth-batches.cjs");
const groups = [
  ["auth-bootstrap", "session-cookie-read"], // real cookie ordering/account-switch regressions; 3+4 authentication requests
  ["invoice-history", "money-input", "tariffs", "managed-gate"], // 3+0+4+2 = 9 logins
  [
    "canonical-topology",
    "reservation-lifecycle",
    "command-recovery",
    "charger-ingress",
    "charger-operations",
    "charger-diagnostics",
    "maintenance-windows",
  ], // 2+1+1+0+1+2+2 = 9 logins
  ["signed-firmware", "ocpi-credentials", "ocpi-incoming", "ocpi-receiver"], // signed delivery and two synthetic partner logins
  ["charger-certificates"], // isolated PKI/TLS/owner controls; dedicated unchanged auth window
  ["rider-access", "charger-resets"], // six logins; separate unchanged auth window
  ["charging-business", "station-favorites"], // 3 registrations +2 registrations = 5
  ["client-demo-lifecycle"], // eight private fixture/browser logins; dedicated window
  ["vehicle-battery"], // existing stopped fixture; private alerts -> compatible nearby discovery
  ["operating-calendar"], // explicit operator calendar; six synthetic authentication requests, no charging
  ["vehicle-reservation"], // real garage/selected vehicle/future booking; scoped three-role reads, no charging
  ["privacy-e2e", "privacy-admin-e2e"], // canonical rights UI; three synthetic logins; no erasure or money
  ["privacy-access-copy"], // dedicated unchanged authentication window for reviewed private copies
  ["charging-reviews"], // own unchanged authentication window
  ["ocpi-test-invoice", "ocpi-credit-picker"], // TEST billing and source-credit review: at most 4 logins; unchanged auth window
  ["partner-billing"], // scoped source-to-ledger workbench; separate authentication window
  ["ocpi-unpaid-credit"], // source-qualified noncash correction; own unchanged auth window
  ["sandbox-roaming"], // isolated synthetic CPO contract; own auth window
  ["partner-favorites"], // own customer/Admin authentication window
  ["charger-configuration"], // two explicit source-device read workflows; own authentication window
  ["diagnostic-files"], // two scoped file-transfer proofs; dedicated unchanged authentication window
];
// Prepare local adapter flags before the existing initial authentication wait.
// Rewriting .env immediately before a suite races artisan serve's process restart.
function prepareOperationalAdapters(execute) {
  for (const script of [
    "./scripts/configuration-read-setup",
    "./scripts/diagnostic-logs-setup",
  ]) {
    const result = execute(script, [], { encoding: "utf8" });
    if (result.error) throw result.error;
    assert.equal(
      result.status,
      0,
      "Local operational preparation failed: " + script,
    );
  }
}
if (require.main === module) {
  const { spawnSync } = require("node:child_process");
  const fs = require("node:fs");
  fs.mkdirSync("artifacts/operational-browser", { recursive: true });
  prepareOperationalAdapters(spawnSync);
  runPacedSuites(groups, {
    wait: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    run: (suite) => {
      const extra =
        suite === "charging-business"
          ? {
              EV_CANONICAL_TARGETS: "1",
              EV_CSMS_CONSUMER: "1",
              EV_CSMS_DAEMON: "1",
              EV_CSMS_AUTO_DISPATCH: "1",
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
module.exports = { groups, prepareOperationalAdapters };
