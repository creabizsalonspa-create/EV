const fs = require("node:fs");

const GATES = Object.freeze([
  "admission", "boot_clock", "evse_topology", "authorization",
  "reservation", "start", "metering", "stop", "faults", "recovery",
  "firmware", "reset", "privacy", "rollback",
]);
const SIGNOFFS = Object.freeze([
  "implementation_migrations", "model_firmware_matrix", "security",
  "site_safety", "fault_recovery_rollback", "finance", "business_owner",
]);
const PREFLIGHT = Object.freeze([
  "deployment_backup_restore", "database_separation", "pinned_csms",
  "signed_inbox_isolation", "registered_admission", "tariff_payment_sandbox",
  "session_capacity", "source_lineage", "capacity_monitoring",
  "audit_rollback", "operational_notices",
]);
const APPROVALS = Object.freeze([
  "operator_window", "site_technician", "manufacturer_identity",
  "security_transport", "business_limits", "incident_contacts",
  "availability_notice", "rollback_authority",
]);
const STATES = ["PASS", "FAIL", "BLOCKED", "NOT TESTED"];
const MAX_BYTES = 256 * 1024;
const digest = (value) => typeof value === "string" && /^[a-f0-9]{64}$/.test(value);
const id = (value) => typeof value === "string" && /^[a-zA-Z0-9_-]{1,64}$/.test(value);
const record = (v) => v !== null && typeof v === "object" && !Array.isArray(v);
const exactKeys = (v, keys) => record(v) &&
  Object.keys(v).length === keys.length &&
  keys.every((key) => Object.hasOwn(v, key));

function audit(manifest) {
  const issues = new Set();
  const issue = (code) => issues.add(code);
  const evidence = (row, label) => {
    if (!exactKeys(row, ["state", "evidence_sha256"])) {
      issue(label + "_shape"); return;
    }
    if (!STATES.includes(row.state)) issue(label + "_state");
    if (row.state !== "PASS") issue(label + "_not_passed");
    if (row.state === "PASS" && !digest(row.evidence_sha256))
      issue(label + "_digest");
    if (row.state !== "PASS" && row.evidence_sha256 !== null && !digest(row.evidence_sha256))
      issue(label + "_digest");
  };
  const group = (rows, names, label) => {
    if (!exactKeys(rows, names)) { issue(label + "_coverage"); return; }
    for (const name of names) evidence(rows[name], label);
  };
  const fields = ["schema_version", "release_sha", "ci_evidence_sha256",
    "csms_version", "scope", "approval_scope_sha256", "approvals", "preflight", "models", "signoffs"];
  if (!exactKeys(manifest, fields)) issue("manifest_shape");
  if (record(manifest)) {
    if (manifest.schema_version !== 1) issue("schema_version");
    if (manifest.scope !== "supervised_hardware_pilot") issue("scope");
    if (manifest.csms_version !== "2.1.0") issue("csms_version");
    if (typeof manifest.release_sha !== "string" || !/^[a-f0-9]{40}$/.test(manifest.release_sha))
      issue("release_sha");
    if (!digest(manifest.ci_evidence_sha256)) issue("ci_digest");
    if (!digest(manifest.approval_scope_sha256)) issue("approval_scope_digest");
    group(manifest.approvals, APPROVALS, "approval");
    group(manifest.preflight, PREFLIGHT, "preflight");
    group(manifest.signoffs, SIGNOFFS, "signoff");
    const models = manifest.models;
    if (!Array.isArray(models) || models.length < 2 || models.length > 100) {
      issue("model_coverage");
    } else {
      const modelIds = new Set(), tuples = new Set(), protocols = new Set();
      for (const model of models) {
        if (!exactKeys(model, ["scope_id", "protocol", "model_firmware_topology_sha256",
          "evidence_kind", "gates"])) {
          issue("model_shape"); continue;
        }
        if (!id(model.scope_id) || modelIds.has(model.scope_id)) issue("model_scope_id");
        modelIds.add(model.scope_id);
        const knownProtocol = ["1.6", "2.0.1"].includes(model.protocol);
        if (!knownProtocol) issue("model_protocol");
        else protocols.add(model.protocol);
        const validIdentity = digest(model.model_firmware_topology_sha256);
        if (!validIdentity) issue("model_identity_digest");
        if (knownProtocol && validIdentity) {
          const tuple = model.protocol + ":" + model.model_firmware_topology_sha256;
          if (tuples.has(tuple)) issue("duplicate_model_tuple");
          tuples.add(tuple);
        }
        if (model.evidence_kind !== "supervised_physical_observation") issue("model_evidence_kind");
        group(model.gates, GATES, "model_gate");
      }
      if (protocols.size !== 2) issue("protocol_coverage");
    }
  }
  // Even perfect structure says nothing about authenticity, actual coverage
  // of an approved inventory, physical execution, or valid signatures.
  // Never echo identifiers, input values, paths, raw evidence or secrets.
  return {
    status: issues.size ? "blocked" : "structurally_complete_requires_independent_review",
    structurally_complete: issues.size === 0,
    issue_codes: [...issues].sort(),
    evidence_authenticity_verified: false,
    approved_inventory_verified: false,
    hardware_pilot_approved: false,
    physical_hardware_accepted: false,
    production_accepted: false,
    device_commands_sent: false,
  };
}

// JSON.parse alone silently accepts duplicate object members (last value wins).
// Scan only after successful JSON syntax validation, using decoded member names
// and a separate key set for each object, including escaped aliases.
function parseManifest(text) {
  const manifest = JSON.parse(text);
  const tokens = /"(?:[^"\\]|\\[\s\S])*"|[{}\[\]:]/g;
  const colon = /\s*:/y;
  const containers = [];
  let token;
  while ((token = tokens.exec(text)) !== null) {
    const value = token[0];
    if (value === "{") containers.push(new Set());
    else if (value === "[") containers.push(null);
    else if (value === "}" || value === "]") containers.pop();
    else if (value[0] === '"') {
      colon.lastIndex = tokens.lastIndex;
      if (colon.test(text)) {
        const keys = containers[containers.length - 1];
        const key = JSON.parse(value);
        if (!(keys instanceof Set) || keys.has(key)) throw Error("duplicate object member");
        keys.add(key);
      }
    }
  }
  return manifest;
}

function readManifest(path) {
  // Read at most one extra byte rather than loading an unbounded file.
  const fd = fs.openSync(path, fs.constants.O_RDONLY | fs.constants.O_NONBLOCK);
  try {
    if (!fs.fstatSync(fd).isFile()) throw Error("not a regular file");
    const buffer = Buffer.alloc(MAX_BYTES + 1);
    let length = 0;
    while (length < buffer.length) {
      const count = fs.readSync(fd, buffer, length, buffer.length - length, null);
      if (count === 0) break;
      length += count;
    }
    if (length > MAX_BYTES) throw Error("oversized manifest");
    return parseManifest(new TextDecoder("utf-8", { fatal: true }).decode(buffer.subarray(0, length)));
  } finally {
    fs.closeSync(fd);
  }
}

if (require.main === module) {
  let result;
  try {
    if (process.argv.length !== 3) throw Error("one input required");
    result = audit(readManifest(process.argv[2]));
  } catch {
    result = audit(null);
    result.issue_codes = ["input_unreadable_or_invalid"];
  }
  console.log(JSON.stringify(result));
  process.exitCode = result.structurally_complete ? 0 : 2;
}

module.exports = { audit, parseManifest, GATES, SIGNOFFS, PREFLIGHT, APPROVALS };
