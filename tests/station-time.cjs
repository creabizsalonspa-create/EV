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

test("published time rejects impossible calendar days, clocks and UTC offsets without rollover", () => {
    const { displayStationTime } = subject();
    for (const invalid of [
        "2026-02-30 06:00:00",
        "2026-04-31T10:00:00+04:00",
        "2026-11-16T24:30:00Z",
        "2026-11-16T10:61:00Z",
        "2026-11-16T10:00:60Z",
        "2026-11-16T10:00:00+14:30",
        "2026-11-16T10:00:00-15:00",
        "2026-11-16T10:00:00Zbogus",
        "2026-11-16T10:00:00",
    ]) {
        assert.equal(displayStationTime(invalid, "Asia/Dubai"), null, invalid);
    }
});

test("verified UTC and explicit-offset source instants display the same station-local clock in EN/AR", () => {
    const { displayStationTime } = subject();
    for (const language of ["en", "ar"]) {
        const original = displayStationTime("2026-11-16T06:00:00Z", "Asia/Dubai", language);
        assert.ok(original);
        for (const equivalent of [
            "2026-11-16T10:00:00+04:00",
            "2026-11-16T06:00:00.123456Z",
        ]) {
            const shown = displayStationTime(equivalent, "Asia/Dubai", language);
            assert.ok(shown && shown.includes("Asia/Dubai"), equivalent);
            // Fractional seconds are not displayed: exact wall-clock minute.
            assert.equal(shown, original);
        }
    }
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
    assert.doesNotMatch(englishGap, /[ء-ي]/);
    assert.match(arabicGap, /[ء-ي]/);
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

test("fractional PostgreSQL UTC timestamps preserve the same localized minute without rollover", () => {
    const { displayStationTime } = subject();
    for (const language of ["en", "ar"]) {
        for (const zone of ["UTC", "Asia/Dubai", "America/Toronto"]) {
            const iso = displayStationTime("2026-11-25T15:32:00.123456Z", zone, language);
            assert.ok(iso);
            for (const fraction of ["1", "123", "123456"]) {
                assert.equal(displayStationTime("2026-11-25 15:32:00." + fraction, zone, language), iso);
            }
            for (const malformed of ["2026-02-30 15:32:00.123456", "2026-11-25 25:32:00.123456",
                "2026-11-25 15:32:00.1234567", "2026-11-25 15:32:00.", "2026-11-25T15:32:00.123456"]) {
                assert.equal(displayStationTime(malformed, zone, language), null);
            }
        }
    }
});


test("repeated regional wall clocks disclose distinct source-instant UTC offsets in English and Arabic", () => {
    const { displayStationTime } = subject();
    for (const language of ["en", "ar"]) {
        const first = displayStationTime("2026-11-01T05:30:00Z", "America/New_York", language);
        const second = displayStationTime("2026-11-01T06:30:00Z", "America/New_York", language);
        assert.ok(first && second);
        assert.notEqual(first, second);
        assert.match(first, /\u2068UTC-04:00\u2069$/);
        assert.match(second, /\u2068UTC-05:00\u2069$/);
        assert.equal(first, displayStationTime("2026-11-01T01:30:00-04:00", "America/New_York", language));
        assert.equal(second, displayStationTime("2026-11-01T01:30:00-05:00", "America/New_York", language));
        assert.match(displayStationTime("2026-11-16T04:30:00Z", "Asia/Kathmandu", language), /UTC\+05:45/);
        assert.match(displayStationTime("2026-11-16T04:30:00Z", "Europe/London", language), /UTC\+00:00/);
        assert.match(displayStationTime("2026-11-16T04:30:00Z", "UTC", language), /\(UTC\)$/);
    }
});

 test("event clocks normalize explicit offsets and fail closed in EN/AR", () => {
    const {serverEventTime} = subject();
    for (const language of ["en","ar"]) {
        const reference = serverEventTime("2026-11-07T08:00:00Z",language);
        assert.match(reference,/\(UTC\)$/);
        assert.equal(serverEventTime("2026-11-07T12:00:00+04:00",language),reference);
        assert.equal(serverEventTime("2026-11-07 08:00:00.123456",language),reference);
        for (const invalid of [null,"2026-02-30T12:00:00Z","2026-11-07","2026-11-07T08:00:00","2026-11-07T08:00:00Z\n","<img src=x>"]) {
            assert.equal(serverEventTime(invalid,language),language==="ar"?"وقت الحدث غير متاح":"Event time unavailable");
        }
    }
});

test("mounted event labels switch through the actual locale engine without source replay", () => {
    const nodes = [{dataset:{}},{dataset:{}}];
    const document = {documentElement:{lang:"en"},querySelectorAll:()=>nodes,getElementById:()=>null,addEventListener:()=>{}};
    const window = {EV_MESSAGES:{},dispatchEvent:()=>{}};
    const env = {document,window,Element:class{},localStorage:{getItem:()=>null,setItem:()=>{}},Event:class{}};
    vm.runInNewContext(fs.readFileSync("services/business-api/public/station-time.js","utf8"),env);
    vm.runInNewContext(fs.readFileSync("services/business-api/public/locale.js","utf8"),env);
    window.EvStationTime.bindServerEventTime(nodes[0],"2026-11-07T12:00:00+04:00");
    window.EvStationTime.bindServerEventTime(nodes[1],"2026-02-30T08:00:00Z");
    assert.match(nodes[0].textContent,/08:00 \(UTC\)/);
    assert.equal(nodes[1].textContent,"Event time unavailable");
    window.EvLocale.set("ar");
    assert.equal(nodes[0].textContent,nodes[0].dataset.ar);
    assert.equal(nodes[1].textContent,"وقت الحدث غير متاح");
    window.EvLocale.set("en");
    assert.equal(nodes[0].textContent,nodes[0].dataset.en);
    assert.equal(nodes[1].textContent,"Event time unavailable");
});
