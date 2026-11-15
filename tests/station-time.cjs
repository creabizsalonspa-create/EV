"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const { test } = require("node:test");
function subject() {
    const context = { window: {} };
    vm.runInNewContext(fs.readFileSync("services/business-api/public/station-time.js", "utf8"), context);
    return context.window.EvStationTime;
}
test("station-local booking resolves Dubai and US summer/winter into exact UTC slots", () => {
    const { toUtc } = subject();
    assert.equal(toUtc("2026-11-16T10:00", "Asia/Dubai"), "2026-11-16T06:00:00Z");
    assert.equal(toUtc("2026-07-16T09:30", "America/New_York"), "2026-07-16T13:30:00Z");
    assert.equal(toUtc("2026-01-16T09:30", "America/New_York"), "2026-01-16T14:30:00Z");
    assert.equal(toUtc("2026-11-16T10:30", "UTC"), "2026-11-16T10:30:00Z");
});
test("bad timezone cannot silently fall back to a device locale", () => {
    const { validZone, toUtc } = subject();
    assert.equal(validZone(""), null);
    assert.equal(validZone("Unknown/Zone"), null);
    assert.throws(() => toUtc("2026-11-16T10:00", "Unknown/Zone"), /timezone is unavailable/);
});
test("DST gaps, repeated hours and incompatible UTC half-hour slots fail closed", () => {
    const { toUtc } = subject();
    assert.throws(() => toUtc("2026-03-08T02:30", "America/New_York"), /does not exist/);
    assert.throws(() => toUtc("2026-11-01T01:30", "America/New_York"), /occurs twice/);
    assert.throws(() => toUtc("2026-11-16T10:00", "Asia/Kathmandu"), /30-minute UTC slot/);
    assert.throws(() => toUtc("2026-11-16T10:15", "UTC"), /30-minute UTC slot/);
});
test("invalid dates and incomplete values never reach the API", () => {
    const { toUtc } = subject();
    assert.throws(() => toUtc("2026-02-30T10:00", "UTC"), /valid calendar date/);
    assert.throws(() => toUtc("2026-11-16T10", "UTC"), /complete date and time/);
});
