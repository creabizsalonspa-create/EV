// Public discovery pages share the real catalog/IP budget even without login.
// Schedule the original suites in fresh windows; never clear limits or retry assertions.
const assert = require("node:assert/strict");
const { spawnSync } = require("node:child_process");
const { runPacedSuites } = require("./helpers/auth-batches.cjs");
const groups = [
  ["nearby-catalog"],
  ["map-assets", "discovery-map", "partner-nearby", "open-charge-map-ui"],
  ["discovery-routing"],
];
async function runDiscoverySuites({
  execute = spawnSync,
  wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  now = () => performance.now(),
  log = console.log,
} = {}) {
  await runPacedSuites(groups, {
    now,
    wait,
    log: () =>
      log(
        "Respecting the unchanged shared public catalog window before the next discovery batch.",
      ),
    run: (suite) => {
      const result = execute(process.execPath, ["tests/" + suite + ".cjs"], {
        stdio: "inherit",
        env: process.env,
      });
      if (result.error) throw result.error;
      assert.equal(
        result.status,
        0,
        "Discovery suite failed; no assertion retry: " + suite,
      );
    },
  });
}
if (require.main === module)
  runDiscoverySuites().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
  });
module.exports = { groups, runDiscoverySuites };
