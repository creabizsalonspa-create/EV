const { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  { mkdtempSync, writeFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path");
const directory = mkdtempSync(join(tmpdir(), "ev-native-vendor-onboarding-"));
try {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/native-vendor-onboarding.php", "prepare", run, password],
      { encoding: "utf8" },
    ),
  );
  const input = join(directory, "fixture.json");
  writeFileSync(
    input,
    JSON.stringify({
      ...fixture,
      owner: `onboarding-owner-${run}@example.test`,
      staff: `onboarding-staff-${run}@example.test`,

      password,
      fixture_only: true,
    }),
    { mode: 0o600 },
  );
  execFileSync(
    "flutter",
    ["test", "test/live_vendor_onboarding_test.dart", "--reporter", "expanded"],
    {
      cwd: "apps/mobile",
      stdio: "inherit",
      env: { ...process.env, EV_NATIVE_VENDOR_ONBOARDING_FIXTURE: input },
    },
  );
} finally {
  rmSync(directory, { recursive: true, force: true });
}
