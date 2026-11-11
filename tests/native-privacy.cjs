const { withPrivacyFixture } = require("./helpers/privacy-local.cjs");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs");
const { tmpdir } = require("node:os");
const { join } = require("node:path");
const { execFileSync } = require("node:child_process");
withPrivacyFixture(async (fixture) => {
  const dir = mkdtempSync(join(tmpdir(), "ev-native-privacy-"));
  try {
    const input = join(dir, "fixture.json");
    writeFileSync(input, JSON.stringify(fixture), { mode: 0o600 });
    execFileSync(
      "flutter",
      [
        "test",
        "--no-pub",
        "test/live_privacy_requests_test.dart",
        "--reporter",
        "expanded",
      ],
      {
        cwd: "apps/mobile",
        stdio: "inherit",
        env: { ...process.env, EV_NATIVE_PRIVACY_FIXTURE: input },
      },
    );
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}).catch(() => {
  console.error(
    "Native canonical privacy integration failed; private fixture output withheld.",
  );
  process.exitCode = 1;
});
