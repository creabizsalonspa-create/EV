const assert = require("node:assert/strict");
const { test } = require("node:test");
const { execFileSync, spawnSync } = require("node:child_process");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { audit, parseManifest, GATES, SIGNOFFS, PREFLIGHT, APPROVALS } = require("../scripts/pilot-evidence-audit.cjs");

const hash = "a".repeat(64);
const group = (names) => Object.fromEntries(names.map((name) => [
  name, { state: "PASS", evidence_sha256: hash },
]));
function fixture() {
  return {
    schema_version: 1, release_sha: "b".repeat(40), ci_evidence_sha256: hash,
    csms_version: "2.1.0", scope: "supervised_hardware_pilot",
    approval_scope_sha256: hash, approvals: group(APPROVALS),
    preflight: group(PREFLIGHT), signoffs: group(SIGNOFFS),
    models: ["1.6", "2.0.1"].map((protocol, i) => ({
      scope_id: "synthetic_" + i, protocol,
      model_firmware_topology_sha256: String(i + 1).repeat(64),
      evidence_kind: "supervised_physical_observation", gates: group(GATES),
    })),
  };
}
function safety(result) {
  for (const key of ["evidence_authenticity_verified", "approved_inventory_verified",
    "hardware_pilot_approved", "physical_hardware_accepted",
    "production_accepted", "device_commands_sent"]) assert.equal(result[key], false);
}
test("a complete synthetic manifest is only structurally complete", () => {
  const result = audit(fixture());
  assert.equal(result.status, "structurally_complete_requires_independent_review");
  assert.equal(result.structurally_complete, true);
  assert.deepEqual(result.issue_codes, []);
  safety(result);
});
for (const [name, change, expected] of [
  ["missing approval", (m) => delete m.approvals.operator_window, "approval_coverage"],
  ["blocked preflight", (m) => { m.preflight.pinned_csms.state = "BLOCKED"; }, "preflight_not_passed"],
  ["missing signoff digest", (m) => { m.signoffs.finance.evidence_sha256 = null; }, "signoff_digest"],
  ["unsupported CSMS", (m) => { m.csms_version = "latest"; }, "csms_version"],
  ["malformed release SHA", (m) => { m.release_sha = "branch-name"; }, "release_sha"],
  ["missing CI evidence", (m) => { m.ci_evidence_sha256 = null; }, "ci_digest"],
  ["unbound approval scope", (m) => { m.approval_scope_sha256 = null; }, "approval_scope_digest"],
  ["missing protocol", (m) => { m.models[1].protocol = "1.6"; }, "protocol_coverage"],
  ["duplicate scope", (m) => { m.models[1].scope_id = m.models[0].scope_id; }, "model_scope_id"],
  ["duplicate model tuple", (m) => { m.models.push({...m.models[0], scope_id: "other"}); }, "duplicate_model_tuple"],
  ["simulator evidence", (m) => { m.models[0].evidence_kind = "simulated"; }, "model_evidence_kind"],
  ["missing model gate", (m) => delete m.models[1].gates.stop, "model_gate_coverage"],
  ["failed gate", (m) => { m.models[0].gates.metering.state = "FAIL"; }, "model_gate_not_passed"],
  ["unrecognized state", (m) => { m.models[0].gates.stop.state = "pass"; }, "model_gate_state"],
  ["unexpected secret", (m) => { m.private_token = "PRIVATE_VALUE"; }, "manifest_shape"],
]) {
  test(name, () => {
    const manifest = fixture();
    change(manifest);
    const result = audit(manifest);
    assert.equal(result.status, "blocked");
    assert(result.issue_codes.includes(expected));
    assert(!JSON.stringify(result).includes("PRIVATE_VALUE"));
    safety(result);
  });
}
test("strict manifest parser rejects duplicate decoded member names in any object", () => {
  const valid = JSON.stringify(fixture());
  assert.deepEqual(parseManifest(valid), fixture());
  for (const raw of [
    valid.replace('"schema_version":1', '"schema_version":2,"schema_version":1'),
    valid.replace('"state":"PASS"', '"state":"FAIL","state":"PASS"'),
    valid.replace('"state":"PASS"', '"st\\u0061te":"FAIL","state":"PASS"'),
    valid.replace('"protocol":"1.6"', '"protocol":"2.0.1","protocol":"1.6"'),
  ]) {
    assert.throws(() => parseManifest(raw), /duplicate object member/);
  }
  assert.deepEqual(parseManifest('{"items":[{"key":"x"},{"key":"y"}]}'),
    { items: [{ key: "x" }, { key: "y" }] });
  assert.deepEqual(parseManifest('{"text":"a \\"key\\": {}[]","key":1}'),
    { text: 'a "key": {}[]', key: 1 });
});
test("CLI rejects malformed, duplicate or oversized input without printing file paths or values", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "pilot-audit-"));
  const file = path.join(dir, "PRIVATE_VALUE.json");
  const script = path.resolve(__dirname, "../scripts/pilot-evidence-audit.cjs");
  try {
    fs.writeFileSync(file, JSON.stringify(fixture()));
    const good = JSON.parse(execFileSync(process.execPath, [script, file], { encoding: "utf8" }));
    assert.equal(good.structurally_complete, true);
    safety(good);
    for (const content of ["PRIVATE_VALUE", "x".repeat(256 * 1024 + 1),
      JSON.stringify(fixture()).replace('"state":"PASS"', '"state":"FAIL","state":"PASS"'),
      Buffer.from([0x7b, 0x22, 0xff, 0x22, 0x3a, 0x31, 0x7d])]) {
      fs.writeFileSync(file, content);
      const bad = spawnSync(process.execPath, [script, file], { encoding: "utf8" });
      assert.equal(bad.status, 2);
      assert.equal(bad.stderr, "");
      assert(!bad.stdout.includes("PRIVATE_VALUE"));
      const result = JSON.parse(bad.stdout);
      assert.deepEqual(result.issue_codes, ["input_unreadable_or_invalid"]);
      safety(result);
    }
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
