// Test orchestration only. Preserve production authentication10/IP/minute and
// every browser assertion; never retry a suite, clear cache or replay a write.
const assert = require("node:assert/strict");
const groups = [
  ["payout-documents", "corporate-billing"], // six logins
  ["external-disputes", "external-disputes-ui", "loyalty"], // seven logins
];
async function runFinancialSuites({
  run,
  wait,
  now = () => performance.now(),
  log = console.log,
}) {
  let completed = now(); // Previous financial suites share this same loopback IP.
  for (const group of groups) {
    while (now() - completed < 61000) {
      const remaining = 61000 - (now() - completed);
      log(
        "Respecting the unchanged shared authentication window before the next finance browser batch.",
      );
      await wait(Math.min(30000, remaining));
    }
    for (const suite of group) await run(suite);
    completed = now(); // Fixture/render time must never shorten the next window.
  }
}
if (require.main === module) {
  const { spawnSync } = require("node:child_process");
  const fs = require("node:fs");
  const files = {
    "payout-documents": "payout-documents",
    "corporate-billing": "corporate",
    "external-disputes": "external-disputes",
    "external-disputes-ui": "external-disputes-ui",
    loyalty: "loyalty",
  };
  fs.mkdirSync("artifacts/finance-commercial", { recursive: true });
  runFinancialSuites({
    wait: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    run: (suite) => {
      const result = spawnSync(process.execPath, ["tests/" + suite + ".cjs"], {
        encoding: "utf8",
        maxBuffer: 4 * 1024 * 1024,
      });
      const output = (result.stdout || "") + (result.stderr || "");
      fs.writeFileSync(
        "artifacts/finance-commercial/" + files[suite] + ".txt",
        output,
      );
      process.stdout.write(output);
      if (result.error) throw result.error;
      assert.equal(
        result.status,
        0,
        "Financial browser suite failed; no assertion retry: " + suite,
      );
    },
  }).catch((e) => {
    console.error(e);
    process.exitCode = 1;
  });
}
module.exports = { runFinancialSuites };
