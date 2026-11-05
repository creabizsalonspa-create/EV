const {
  withCredentialsFixture,
} = require("./helpers/ocpi-credentials-local.cjs");
const { execFileSync } = require("node:child_process");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs");
const { tmpdir } = require("node:os");
const { join } = require("node:path");
withCredentialsFixture(async (fixture) => {
  const directory = mkdtempSync(join(tmpdir(), "ev-native-credentials-"));
  try {
    const input = join(directory, "fixture.json");
    writeFileSync(input, JSON.stringify(fixture), { mode: 0o600 });
    execFileSync(
      "flutter",
      [
        "test",
        "test/live_ocpi_credentials_test.dart",
        "--reporter",
        "expanded",
      ],
      {
        cwd: "apps/mobile",
        stdio: "inherit",
        env: { ...process.env, EV_NATIVE_OCPI_CREDENTIALS_FIXTURE: input },
      },
    );
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
}).catch(() => {
  console.error(
    "Native synthetic OCPI credentials validation failed; private protocol details withheld.",
  );
  process.exitCode = 1;
});
