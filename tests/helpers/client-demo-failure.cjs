// Whitelist-only diagnostics for an owned synthetic fixture. Never serialize
// private manifests, credential/token payloads, journals or arbitrary errors.
const fs = require("node:fs");
const path = require("node:path");
const { execFileSync } = require("node:child_process");
const states = new Set([
  "idle",
  "connecting",
  "starting",
  "authorizing",
  "charging",
  "stopping",
  "stopped",
  "requires_operator_review",
]);
const failureCodes = new Set([
  "worker_requires_operator_review",
  "source_observation_requires_reconciliation",
  "pre_dispatch_window_unavailable",
  "pre_dispatch_company_gate",
  "pre_dispatch_operating_calendar",
  "csms_rejected_dispatch",
  "unexpected_confirmation",
  "dispatch_requires_reconciliation",
  "cancelled_before_dispatch",
  "expired_before_dispatch",
]);
const safeState = (value) => (states.has(value) ? value : "unavailable");
function redact({ protocol, status = {}, journal = {}, commands = [] }) {
  return {
    protocol: ["1.6", "2.0.1"].includes(protocol) ? protocol : "unknown",
    device_state: safeState(status.state),
    journal_phase: safeState(journal.phase),
    meter_wh:
      Number.isSafeInteger(journal.meter_wh) && journal.meter_wh >= 0
        ? journal.meter_wh
        : null,
    commands: commands.slice(0, 2).map((row) => ({
      id: Number.isSafeInteger(row.id) && row.id > 0 ? row.id : null,
      action: ["start", "stop"].includes(row.action) ? row.action : "unknown",
      status: [
        "pending",
        "dispatching",
        "submitted",
        "rejected",
        "outcome_unknown",
        "source_observed",
        "cancelled",
      ].includes(row.status)
        ? row.status
        : "unknown",
      failure_code: failureCodes.has(row.failure_code)
        ? row.failure_code
        : null,
    })),
    evidence: "SIMULATED_CSMS_FAILURE_DIAGNOSTIC",
    commands_replayed: false,
    physical_charging_verified: false,
    real_payment_processed: false,
  };
}
function capture(file, station) {
  const root = path.resolve("artifacts/client-demo") + path.sep;
  const manifest = fs.realpathSync(file);
  if (
    !manifest.startsWith(root) ||
    !Number.isSafeInteger(station.reservation_id) ||
    station.reservation_id < 1 ||
    !["1.6", "2.0.1"].includes(station.protocol)
  )
    throw Error("Owned fixture diagnostic scope required.");
  const fixture = JSON.parse(fs.readFileSync(manifest));
  if (
    !fixture.fixture_only ||
    fixture.real_money ||
    !fixture.stations.some(
      (row) =>
        row.reservation_id === station.reservation_id &&
        row.protocol === station.protocol,
    )
  )
    throw Error("Owned fixture diagnostic scope required.");
  const read = (prefix) => {
    try {
      return JSON.parse(
        fs.readFileSync(
          path.join(
            path.dirname(manifest),
            prefix + station.protocol.replaceAll(".", "") + ".json",
          ),
        ),
      );
    } catch {
      return {};
    }
  };
  let commands = [];
  try {
    const php =
      'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if (!app()->environment(["local","testing"])) exit(2); $session=App\\Models\\ChargingSession::where("reservation_id",' +
      station.reservation_id +
      ')->first(); echo json_encode($session ? App\\Models\\ChargerCommand::where("payload->session_id",$session->id)->orderBy("id")->limit(2)->get(["id","action","status","failure_code"]) : []);';
    commands = JSON.parse(
      execFileSync("./scripts/php", ["-r", php], {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "ignore"],
        timeout: 15000,
        maxBuffer: 16384,
      }),
    );
  } catch {
    /* Missing diagnostics never replace or retry the original failure. */
  }
  const result = redact({
    protocol: station.protocol,
    status: read("status-"),
    journal: read("device-"),
    commands,
  });
  fs.mkdirSync("artifacts/operational-browser", { recursive: true });
  fs.writeFileSync(
    "artifacts/operational-browser/client-demo-failure-" +
      station.protocol.replaceAll(".", "") +
      ".json",
    JSON.stringify(result, null, 2) + "\n",
    { mode: 0o600 },
  );
  return result;
}
module.exports = { redact, capture };
