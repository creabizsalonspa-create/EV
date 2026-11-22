const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs");
const { tmpdir } = require("node:os");
const { join } = require("node:path");

const directory = mkdtempSync(join(tmpdir(), "ev-native-referrals-"));
try {
  const run = randomBytes(4).toString("hex");
  const password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(execFileSync("./scripts/php",
    ["tests/Fixtures/referral-admin-browser.php", "create", run, password],
    { encoding: "utf8" }));
  const input = join(directory, "fixture.json");
  writeFileSync(input, JSON.stringify({ ...fixture, run, password }), { mode: 0o600 });
  execFileSync("flutter", ["test", "test/live_referrals_test.dart", "--reporter", "expanded"], {
    cwd: "apps/mobile",
    stdio: "inherit",
    env: { ...process.env, EV_NATIVE_REFERRALS_FIXTURE: input },
  });
} finally {
  rmSync(directory, { recursive: true, force: true });
}
