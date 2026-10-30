const { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  { mkdtempSync, writeFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path");
const dir = mkdtempSync(join(tmpdir(), "ev-native-analytics-"));
try {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/native-financial-analytics.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const input = join(dir, "fixture.json");
  writeFileSync(
    input,
    JSON.stringify({
      ...fixture,
      customer: `payout-customer-${run}@example.test`,
      vendor: `payout-vendor-${run}@example.test`,
      foreign: `payout-foreign-${run}@example.test`,
      password,
    }),
    { mode: 0o600 },
  );
  execFileSync(
    "flutter",
    ["test", "test/live_financial_analytics_test.dart", "--reporter", "expanded"],
    {
      cwd: "apps/mobile",
      stdio: "inherit",
      env: { ...process.env, EV_NATIVE_ANALYTICS_FIXTURE: input },
    },
  );
} finally {
  rmSync(dir, { recursive: true, force: true });
}
