"use strict";
const assert = require("node:assert/strict");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs");
const { tmpdir } = require("node:os");
const { join } = require("node:path");
(async () => {
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
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const invoke = (value) =>
    JSON.parse(
      execFileSync(
        process.env.EV_TEST_PHP_RUNNER || "./scripts/php",
        ["tests/Fixtures/native-coupons.php", run, value],
        { encoding: "utf8" },
      ),
    );
  const f = invoke(password),
    before = invoke("snapshot"),
    directory = mkdtempSync(join(tmpdir(), "ev-native-coupons-"));
  try {
    const file = join(directory, "fixture.json");
    writeFileSync(file, JSON.stringify({ ...f, password }), { mode: 0o600 });
    execFileSync(
      "flutter",
      [
        "test",
        "--no-pub",
        "test/live_coupons_test.dart",
        "--reporter",
        "expanded",
      ],
      {
        cwd: "apps/mobile",
        stdio: "inherit",
        env: {
          ...process.env,
          EV_NATIVE_COUPONS_FIXTURE: file,
          EV_TEST_URL: base.origin,
        },
      },
    );
    assert.deepEqual(invoke("snapshot"), before);
    console.log(
      "PASS: native coupon binding, exact replay, receipt lookup and permanent cancellation preserve canonical financial storage.",
    );
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
})().catch(() => {
  console.error(
    "FAIL: owned native contract (private fixture details omitted)",
  );
  process.exitCode = 1;
});
