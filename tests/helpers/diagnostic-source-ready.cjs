"use strict";

const assert = require("node:assert/strict");

// Synthetic-device acknowledgement barrier. Read the exact retained outbound
// request before acknowledging it; never resend a command or relax API causality.
async function waitForRetainedRequest({ graph, identity, protocol, payload,
  attempts = 50, spacingMs = 100, wait = ms => new Promise(resolve => setTimeout(resolve, ms)),
}, kind, retiredIds = []) {
  assert.equal(typeof graph, "function");
  assert(/^ev-test-[a-zA-Z0-9-]+$/.test(identity));
  assert(["1.6", "2.0.1"].includes(protocol));
  assert(payload && typeof payload === "object" && !Array.isArray(payload));
  assert(Number.isInteger(attempts) && attempts >= 1 && attempts <= 50);
  assert(Number.isInteger(spacingMs) && spacingMs >= 0 && spacingMs <= 100);
  assert(["diagnostic", "configuration"].includes(kind));
  assert(Array.isArray(retiredIds) && retiredIds.length <= 10 &&
    retiredIds.every(id => Number.isInteger(id) && id > 0 && id <= 2147483647) &&
    new Set(retiredIds).size === retiredIds.length);
  const action = kind === "diagnostic" ? (protocol === "1.6" ? "GetDiagnostics" : "GetLog") :
    (protocol === "1.6" ? "GetConfiguration" : "GetVariables");
  try {
    const result = await graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id protocol}}', { name: identity });
    const stations = result?.ChargingStations;
    assert(Array.isArray(stations) && stations.length === 1);
    const station = stations[0];
    assert(Number.isInteger(station.id) && station.id > 0 && station.id <= 2147483647);
    assert([protocol, "ocpp" + protocol].includes(station.protocol));
    for (let attempt = 0; attempt < attempts; attempt++) {
      const base = 'query($station:Int!,$action:String!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"csms"},type:{_eq:2},action:{_eq:$action},payload:{_eq:$match}},limit:2){id createdAt}}';
      const variables = { station: station.id, action, match: payload };
      // Identical sequential configuration payloads require distinct retained IDs.
      const query = kind === "configuration" ?
        base.replace('$match:jsonb!', '$match:jsonb!,$retired:[Int!]!')
          .replace('payload:{_eq:$match}', 'payload:{_eq:$match},id:{_nin:$retired}') : base;
      if (kind === "configuration") variables.retired = retiredIds;
      const result = await graph(query, variables);
      const rows = result?.OCPPMessages;
      assert(Array.isArray(rows) && rows.length <= 1);
      if (rows.length === 1) {
        assert(Number.isInteger(rows[0].id) && rows[0].id > 0 && rows[0].id <= 2147483647 && !retiredIds.includes(rows[0].id));
        assert(typeof rows[0].createdAt === "string" && Number.isFinite(Date.parse(rows[0].createdAt)));
        return rows[0].id;
      }
      if (attempt + 1 < attempts) await wait(spacingMs);
    }
  } catch {
    // Source errors may contain private capability material. Never echo them.
    throw new Error("Synthetic " + kind + " request retention is unavailable; acknowledgement withheld.");
  }
  throw new Error("Synthetic " + kind + " request was not retained within the bounded read window; acknowledgement withheld.");
}

const waitForRetainedDiagnosticRequest = options => waitForRetainedRequest(options, "diagnostic");
const waitForRetainedConfigurationRequest = (options, retiredIds) =>
  waitForRetainedRequest(options, "configuration", retiredIds);
module.exports = { waitForRetainedDiagnosticRequest, waitForRetainedConfigurationRequest };
