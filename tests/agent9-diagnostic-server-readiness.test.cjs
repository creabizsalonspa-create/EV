"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const {
  HEALTH_URL,
  waitForDiagnosticServerReady,
} = require("./helpers/local-service-readiness.cjs");

test("interrupted local health checks recover without replaying a business action", async () => {
  const calls = [];
  const statuses = ["throw", 503, 200, 200];
  let sleeps = 0;
  await waitForDiagnosticServerReady({
    attempts: 6,
    spacingMs: 0,
    sleep: async () => { sleeps++; },
    fetchImpl: async (url, options) => {
      calls.push({ url, options });
      const status = statuses[calls.length - 1];
      if (status === "throw") throw new Error("fetch failed: private-token=secret");
      return { status };
    },
  });
  assert.equal(calls.length, 4);
  assert.equal(sleeps, 3);
  for (const { url, options } of calls) {
    assert.equal(url, "http://127.0.0.1:8000/up");
    assert.equal(url, HEALTH_URL);
    assert.equal(options.method, "GET");
    assert.equal(options.redirect, "error");
    assert.deepEqual(options.headers, { Accept: "text/plain" });
    assert.equal(options.signal instanceof AbortSignal, true);
    assert.equal(options.body, undefined);
  }
});

test("one healthy response followed by a restart never counts as stable", async () => {
  const outcomes = [200, 503, 200, 200];
  let count = 0;
  await waitForDiagnosticServerReady({
    attempts: 4,
    spacingMs: 0,
    sleep: async () => {},
    fetchImpl: async () => ({ status: outcomes[count++] }),
  });
  assert.equal(count, 4);
});

test("persistent loopback failure stops after a finite number of read-only probes", async () => {
  let count = 0;
  await assert.rejects(
    waitForDiagnosticServerReady({
      attempts: 3,
      spacingMs: 0,
      sleep: async () => {},
      fetchImpl: async () => {
        count++;
        throw new Error("fetch failed: Authorization Basic secret credential");
      },
    }),
    (error) => {
      assert.match(error.message, /Synthetic diagnostic loopback \/up did not become stable/);
      assert.match(error.message, /no business mutation was replayed/);
      assert.doesNotMatch(error.message, /secret|credential|Authorization/);
      return true;
    },
  );
  assert.equal(count, 3);
});

test("health probes reject out-of-budget retry settings", async () => {
  for (const options of [{ attempts: 1 }, { attempts: 61 }, { spacingMs: -1 }, { spacingMs: 1001 }]) {
    await assert.rejects(waitForDiagnosticServerReady(options), /AssertionError|assertion|false/i);
  }
});

test("diagnostic source awaits local health before starting the device workflow", () => {
  const source = fs.readFileSync(path.join(__dirname, "diagnostic-files.cjs"), "utf8");
  const setup = source.indexOf('execFileSync("./scripts/diagnostic-logs-setup"');
  const ready = source.indexOf("await waitForDiagnosticServerReady();");
  const fixture = source.indexOf("tests/Fixtures/charger-diagnostics.php");
  assert(setup >= 0 && ready > setup && fixture > ready,
    "synthetic diagnostic setup must finish the health probe before first fixture and network request");
  assert.match(source, /require\("\.\/helpers\/local-service-readiness\.cjs"\)/);
});
