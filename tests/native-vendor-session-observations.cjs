"use strict";
const assert = require("node:assert/strict"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  { mkdtempSync, writeFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path"),
  { BusinessClient } = require("./helpers/business-client.cjs");
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
  const runner = process.env.EV_TEST_PHP_RUNNER || "./scripts/php",
    run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "Aa9!";
  const invoke = (mode, ...args) =>
    JSON.parse(
      execFileSync(
        runner,
        ["tests/Fixtures/vendor-session-observations.php", mode, run, ...args],
        { encoding: "utf8" },
      ),
    );
  const f = invoke("seed", password, "meters"),
    before = invoke("snapshot"),
    owner = new BusinessClient({ base: base.origin });
  owner.csrf = (await owner.request("csrf")).token;
  await owner.request("login", "POST", { email: f.owner_email, password });
  owner.csrf = (await owner.request("csrf")).token;
  await owner.request(
    `vendor-ops/companies/${f.company_id}/staff/${f.membership_id}/permissions`,
    "PATCH",
    { expected_version: 1, permissions: ["charging.read"] },
  );
  const directory = mkdtempSync(
    join(tmpdir(), "ev-native-staff-observations-"),
  );
  try {
    const file = join(directory, "fixture.json");
    writeFileSync(file, JSON.stringify({ ...f, password, base: base.origin }), {
      mode: 0o600,
    });
    execFileSync(
      "flutter",
      [
        "test",
        "test/live_vendor_session_observations_test.dart",
        "--reporter",
        "expanded",
      ],
      {
        cwd: "apps/mobile",
        stdio: "inherit",
        env: { ...process.env, EV_NATIVE_STAFF_OBSERVATIONS_FIXTURE: file },
      },
    );
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
  assert.deepEqual(invoke("snapshot"), before);
  console.log(
    "PASS: native staff observations retained unchanged synthetic session, command and finance storage.",
  );
})().catch(() => {
  console.error(
    "FAIL: native staff observation contract (private details omitted)",
  );
  process.exitCode = 1;
});
