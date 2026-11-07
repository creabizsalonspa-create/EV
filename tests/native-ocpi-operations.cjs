// Actual Laravel + native HTTP client, synthetic records only; no partner requests.
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs");
const { tmpdir } = require("node:os");
const { join } = require("node:path");
const dir = mkdtempSync(join(tmpdir(), "ev-native-partner-"));
const run = randomBytes(4).toString("hex"),
  password = randomBytes(24).toString("hex") + "aA9!";
try {
  execFileSync(
    "./scripts/php",
    ["tests/Fixtures/roaming-workspace.php", "seed", run, password],
    { stdio: "pipe" },
  );
  const input = join(dir, "fixture.json");
  writeFileSync(input, JSON.stringify({ run, password, fixture_only: true }), {
    mode: 0o600,
  });
  execFileSync(
    "flutter",
    ["test", "test/live_ocpi_operations_test.dart", "--reporter", "expanded"],
    {
      cwd: "apps/mobile",
      stdio: "inherit",
      env: { ...process.env, EV_NATIVE_OCPI_FIXTURE: input },
    },
  );
} finally {
  // Preserve accepted source evidence; only private temporary credentials are removed.
  rmSync(dir, { recursive: true, force: true });
}
