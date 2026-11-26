"use strict";
const assert = require("node:assert/strict");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs");
const { tmpdir } = require("node:os");
const { join } = require("node:path");
const base = new URL(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
assert(
  base.protocol === "http:" &&
    ["127.0.0.1", "localhost"].includes(base.hostname) &&
    !base.username &&
    !base.password &&
    base.pathname === "/" &&
    !base.search &&
    !base.hash,
);
const password = randomBytes(24).toString("hex"),
  newPassword = randomBytes(24).toString("hex");
const directory = mkdtempSync(join(tmpdir(), "ev-native-recovery-"));
try {
  for (const role of ["customer", "vendor"]) {
    const fixture = JSON.parse(
      execFileSync(
        process.env.EV_TEST_PHP_RUNNER || "./scripts/php",
        [
          "tests/Fixtures/native-account-recovery.php",
          randomBytes(4).toString("hex"),
          password,
          role,
        ],
        { encoding: "utf8" },
      ),
    );
    const file = join(directory, "fixture.json");
    writeFileSync(
      file,
      JSON.stringify({
        ...fixture,
        password,
        new_password: newPassword,
        base: base.origin,
      }),
      { mode: 0o600 },
    );
    execFileSync(
      "flutter",
      [
        "test",
        "--no-pub",
        "test/live_account_recovery_test.dart",
        "--reporter",
        "expanded",
      ],
      {
        cwd: "apps/mobile",
        stdio: "inherit",
        env: { ...process.env, EV_NATIVE_RECOVERY_FIXTURE: file },
      },
    );
  }
  console.log(
    "PASS: customer/vendor synthetic native Laravel reset, revoked session, single-use token and new-password sign-in.",
  );
} finally {
  rmSync(directory, { recursive: true, force: true });
}
