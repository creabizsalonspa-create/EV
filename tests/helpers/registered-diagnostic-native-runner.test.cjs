"use strict";
const assert = require("node:assert/strict");
const { test } = require("node:test");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { runNativeDiagnostics } = require("./registered-diagnostic-native-runner.cjs");

async function fixture(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "ev-diag-runner-test-"));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const file = path.join(dir, "native-fixture.json");
  fs.writeFileSync(file, "{}", { mode: 0o600 });
  const marker = path.join(dir, "native.done");
  const invoke = (source, timeout = 5000) => runNativeDiagnostics({
    file, marker, command: process.execPath, args: ["-e", source], cwd: dir, timeout,
  });
  return { marker, invoke };
}
test("connected child receives fixture and parent loop remains responsive", async t => {
  const { invoke } = await fixture(t);
  let ticks = 0;
  const timer = setInterval(() => ticks++, 10);
  try {
    await invoke(`const fs=require('node:fs'),p=require('node:path');setTimeout(()=>fs.writeFileSync(p.join(p.dirname(process.env.EV_NATIVE_AUTHENTICATED_LOGS_FIXTURE),'native.done'),'ok'),100)`);
    assert.ok(ticks > 0);
  } finally { clearInterval(timer); }
});
test("successful exit without marker cannot count as acceptance", async t => {
  const { invoke } = await fixture(t);
  await assert.rejects(invoke("process.exit(0)"), /marker missing/);
});
test("failed child remains failed even with a marker", async t => {
  const { invoke } = await fixture(t);
  await assert.rejects(invoke("require('node:fs').writeFileSync('native.done','stale');process.exit(1)"), /client failed/);
});
test("bounded timeout fails rather than hanging", async t => {
  const { invoke } = await fixture(t);
  await assert.rejects(invoke("setInterval(()=>{},1000)", 100), /client failed/);
});
test("preexisting marker cannot substitute for current execution", async t => {
  const { marker, invoke } = await fixture(t);
  fs.writeFileSync(marker, "stale");
  await assert.rejects(invoke("process.exit(0)"), /preexisting/);
});
