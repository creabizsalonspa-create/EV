"use strict";
const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const { waitForRetainedDiagnosticRequest } = require("./helpers/diagnostic-source-ready.cjs");
const station = { id: 42, protocol: "ocpp1.6" };
const request = { id: 73, createdAt: "2026-11-20T00:00:00.123456Z" };
const options = { identity: "ev-test-diagnostic-order", protocol: "1.6", payload: { location: "synthetic-private-capability" }, spacingMs: 0 };

test("device acknowledgement waits for its exact retained tenant/station/action/payload", async () => {
  const calls = []; let reads = 0, waits = 0;
  const id = await waitForRetainedDiagnosticRequest({ ...options, wait: async () => { waits++; }, graph: async (query, variables) => {
    calls.push({ query, variables });
    return query.includes("ChargingStations") ? { ChargingStations: [station] } : { OCPPMessages: ++reads === 1 ? [] : [request] };
  }});
  assert.equal(id, 73); assert.equal(waits, 1); assert.equal(reads, 2);
  for (const call of calls) { assert(!/mutation/.test(call.query)); assert.match(call.query, /tenantId:\{_eq:1\}/); }
  assert.deepEqual(calls[1].variables, { station: 42, action: "GetDiagnostics", match: options.payload });
  assert.match(calls[1].query, /origin:\{_eq:"csms"\}.*type:\{_eq:2\}.*payload:\{_eq:\$match\}/);
});

test("2.0.1 retains the GetLog request and full operation-specific payload", async () => {
  let matched;
  await waitForRetainedDiagnosticRequest({ ...options, protocol: "2.0.1", payload: { requestId: 17, log: { remoteLocation: "synthetic" } }, graph: async (query, variables) => {
    if (query.includes("ChargingStations")) return { ChargingStations: [{ ...station, protocol: "ocpp2.0.1" }] };
    matched = variables; return { OCPPMessages: [request] };
  }});
  assert.equal(matched.action, "GetLog"); assert.equal(matched.match.requestId, 17);
});

test("absent retention exhausts bounded reads without a command replay", async () => {
  let reads = 0;
  await assert.rejects(waitForRetainedDiagnosticRequest({ ...options, attempts: 3, wait: async () => {}, graph: async query => query.includes("ChargingStations") ? { ChargingStations: [station] } : (reads++, { OCPPMessages: [] }) }), /bounded read window; acknowledgement withheld/);
  assert.equal(reads, 3);
});

test("ambiguous or malformed retained source fails closed and redacts errors", async () => {
  for (const rows of [[request, request], [{ ...request, id: "73" }], [{ ...request, createdAt: "invalid" }]]) {
    await assert.rejects(waitForRetainedDiagnosticRequest({ ...options, graph: async query => query.includes("ChargingStations") ? { ChargingStations: [station] } : { OCPPMessages: rows } }), /retention is unavailable; acknowledgement withheld/);
  }
  await assert.rejects(waitForRetainedDiagnosticRequest({ ...options, graph: async () => { throw Error("Authorization private-token"); } }), error => !/Authorization|private-token/.test(error.message));
});

test("foreign protocol and non-synthetic identities never acquire an acknowledgement", async () => {
  let reads = 0;
  await assert.rejects(waitForRetainedDiagnosticRequest({ ...options, identity: "live-device", graph: async () => { reads++; } }));
  assert.equal(reads, 0);
  await assert.rejects(waitForRetainedDiagnosticRequest({ ...options, graph: async () => ({ ChargingStations: [{ ...station, protocol: "ocpp2.0.1" }] }) }), /acknowledgement withheld/);
});

test("actual diagnostic simulator awaits retention before returning protocol acknowledgement", () => {
  const source = fs.readFileSync(require.resolve("./diagnostic-files.cjs"), "utf8");
  const handler = source.slice(source.indexOf("device.diagnosticHandler ="), source.indexOf("    try {\n      await device.connect"));
  assert.match(handler, /diagnosticHandler = async/);
  assert(handler.indexOf("await waitForRetainedDiagnosticRequest") < handler.indexOf("setTimeout("));
  assert(handler.indexOf("await waitForRetainedDiagnosticRequest") < handler.indexOf('fileName: "private-device-filename.log"'));
});
