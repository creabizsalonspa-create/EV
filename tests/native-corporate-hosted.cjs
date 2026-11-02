const { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  { mkdtempSync, writeFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path");
const dir = mkdtempSync(join(tmpdir(), "ev-native-corporate-hosted-"));
try {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/corporate-hosted-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const input = join(dir, "fixture.json");
  writeFileSync(input, JSON.stringify({ ...fixture, password }), {
    mode: 0o600,
  });
  execFileSync(
    "flutter",
    ["test", "test/live_corporate_hosted_checkout_test.dart", "--reporter", "expanded"],
    {
      cwd: "apps/mobile",
      stdio: "inherit",
      env: { ...process.env, EV_NATIVE_CORPORATE_HOSTED_FIXTURE: input },
    },
  );
} finally {
  rmSync(dir, { recursive: true, force: true });
}
