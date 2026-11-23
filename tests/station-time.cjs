"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const { test } = require("node:test");
function subject(language = "en") {
    const context = { window: { EvLocale: { language } } };
    vm.runInNewContext(fs.readFileSync("services/business-api/public/station-time.js", "utf8"), context);
    return context.window.EvStationTime;
}
test("station-local booking resolves Dubai and US summer/winter into exact UTC slots", () => {
    const { toUtc } = subject();
    assert.equal(toUtc("2026-11-16T10:00", "Asia/Dubai"), "2026-11-16T06:00:00Z");
    assert.equal(toUtc("2026-07-16T09:30", "America/New_York"), "2026-07-16T13:30:00Z");
    assert.equal(toUtc("2026-01-16T09:30", "America/New_York"), "2026-01-16T14:30:00Z");
    assert.equal(toUtc("2026-11-16T10:30", "UTC"), "2026-11-16T10:30:00Z");
    // Nepal's +05:45 offset means a valid UTC :30 slot ends in :15 locally.
    assert.equal(toUtc("2026-11-16T10:15", "Asia/Kathmandu"), "2026-11-16T04:30:00Z");
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

test("published station timezone renders a precise UTC instant in EN/AR; unknown data remains hidden", () => {
    const { displayStationTime } = subject();
    assert.match(displayStationTime("2026-11-16 06:00:00", "Asia/Dubai", "en"), /10:00 \(Asia\/Dubai\)/);
    assert.match(displayStationTime("2026-11-16T06:00:00Z", "Asia/Dubai", "ar"), /Asia\/Dubai/);
    assert.equal(displayStationTime("2026-11-16 06:00:00", "Invalid/Zone"), null);
    assert.equal(displayStationTime("unverified", "Asia/Dubai"), null);
    assert.equal(displayStationTime("2026-11-16T06:00:00", "Asia/Dubai"), null);
});

test("station-local time errors respect current English/Arabic language without mixed bilingual alerts", () => {
    const english = subject("en"), arabic = subject("ar");
    const message = (fn) => {
        try {
            fn();
        } catch (error) {
            return error.message;
        }
        assert.fail("Expected invalid station-local time to be rejected");
    };
    const englishGap = message(() => english.toUtc("2026-03-08T02:30", "America/New_York"));
    const arabicGap = message(() => arabic.toUtc("2026-03-08T02:30", "America/New_York"));
    assert.match(englishGap, /does not exist/);
    assert.doesNotMatch(englishGap, /[\\u0600-\\u06FF]/);
    assert.match(arabicGap, /[\\u0600-\\u06FF]/);
    assert.doesNotMatch(arabicGap, /does not exist/);
    const arabicZone = message(() => arabic.toUtc("2026-11-16T10:00", "Not/Real"));
    assert.match(arabicZone, /توقيت المحطة غير متاح/);
    assert.doesNotMatch(arabicZone, /timezone is unavailable/);
    const arabicRepeated = message(() => arabic.toUtc("2026-11-01T01:30", "America/New_York"));
    assert.match(arabicRepeated, /هذا الوقت المحلي يتكرر/);
    assert.doesNotMatch(arabicRepeated, /occurs twice/);
    const arabicInvalid = message(() => arabic.toUtc("2026-02-30T10:00", "UTC"));
    assert.match(arabicInvalid, /اختر تاريخاً صحيحاً/);
    assert.doesNotMatch(arabicInvalid, /valid calendar date/);
});
