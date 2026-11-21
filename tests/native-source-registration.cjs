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
  const invoke = (mode, ...args) =>
    JSON.parse(
      execFileSync(
        process.env.EV_TEST_PHP_RUNNER || "./scripts/php",
        ["tests/Fixtures/native-source-registration.php", mode, run, ...args],
        { encoding: "utf8" },
      ),
    );
  const f = invoke("seed", password),
    before = invoke("snapshot"),
    directory = mkdtempSync(join(tmpdir(), "ev-native-retained-registration-"));
  try {
    const file = join(directory, "fixture.json");
    writeFileSync(file, JSON.stringify({ ...f, password, base: base.origin }), {
      mode: 0o600,
    });
    execFileSync(
      "flutter",
      [
        "test",
        "--no-pub",
        "test/live_charger_source_registration_test.dart",
        "--reporter",
        "expanded",
      ],
      {
        cwd: "apps/mobile",
        stdio: "inherit",
        env: { ...process.env, EV_NATIVE_SOURCE_REGISTRATION_FIXTURE: file },
      },
    );
    assert.deepEqual(invoke("snapshot"), before);
    console.log(
      "PASS: native retained-registration projection for both OCPP protocols; synthetic history only, no source authentication/writes/hardware proof, unchanged commands/sessions/finance.",
    );
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
})().catch(() => {
  console.error(
    "FAIL: owned retained-registration projection (private fixture details omitted)",
  );
  process.exitCode = 1;
});
