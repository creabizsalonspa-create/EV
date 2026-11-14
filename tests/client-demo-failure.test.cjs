const { test } = require("node:test");
const assert = require("node:assert/strict");
const { redact } = require("./helpers/client-demo-failure.cjs");
test("failure evidence whitelists owned operational state and excludes credentials/payloads", () => {
  const result = redact({
    protocol: "2.0.1",
    status: { state: "idle", token: "SECRET", pid: 2 },
    journal: {
      phase: "starting",
      meter_wh: 1045,
      id_token: "SECRET",
      transaction_id: "SECRET",
    },
    commands: [
      {
        id: 8,
        action: "start",
        status: "outcome_unknown",
        failure_code: "dispatch_requires_reconciliation",
        payload: { id_token: "SECRET" },
        confirmation: "SECRET",
      },
    ],
  });
  assert.equal(
    result.commands[0].failure_code,
    "dispatch_requires_reconciliation",
  );
  assert.equal(result.journal_phase, "starting");
  assert.equal(result.commands_replayed, false);
  assert(!JSON.stringify(result).includes("SECRET"));
  assert(!JSON.stringify(result).includes("payload"));
  assert(!JSON.stringify(result).includes("transaction_id"));
});
test("malformed diagnostic fields cannot copy arbitrary private strings into artifacts", () => {
  const result = redact({
    protocol: "SECRET",
    backend: {exception_kind:"SECRET",application_file:"https://SECRET?token=key",application_line:"SECRET",message:"SECRET"},
    status: { state: "SECRET" },
    journal: { phase: "SECRET", meter_wh: -1 },
    commands: Array.from({ length: 4 }, () => ({
      id: "SECRET",
      action: "SECRET",
      status: "SECRET",
      failure_code: "https://SECRET?token=key",
    })),
  });
  assert.equal(result.commands.length, 2);
  assert.equal(result.meter_wh, null);
  assert(!JSON.stringify(result).includes("SECRET"));
});
