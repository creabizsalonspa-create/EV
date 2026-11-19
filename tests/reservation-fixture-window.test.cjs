"use strict";
const assert = require("node:assert/strict");
const { test } = require("node:test");
const {
  waitForReservationFixtureWindow,
} = require("./helpers/reservation-fixture-window.cjs");

test("a fresh slot needs no delay and does not alter the clock", async () => {
  let calls = 0;
  await waitForReservationFixtureWindow({
    now: () => Date.parse("2026-11-19T06:30:00Z"),
    wait: async () => calls++,
    log: () => {},
  });
  assert.equal(calls, 0);
});
test("a fixture at the reproduced 06:29:59 boundary waits before creating any intent", async () => {
  let clock = Date.parse("2026-11-19T06:29:59Z");
  const waits = [];
  await waitForReservationFixtureWindow({
    now: () => clock,
    wait: async (ms) => {
      waits.push(ms);
      clock += ms;
    },
    log: () => {},
  });
  assert.deepEqual(waits, [1001]);
  assert.equal(new Date(clock).toISOString(), "2026-11-19T06:30:00.001Z");
});
test("near-expiry waits stay bounded and fail before fixture creation if waiting fails", async () => {
  let clock = Date.parse("2026-11-19T06:27:01Z");
  const waits = [];
  await waitForReservationFixtureWindow({
    now: () => clock,
    wait: async (ms) => {
      assert(ms > 0 && ms <= 30000);
      waits.push(ms);
      clock += ms;
    },
    log: () => {},
  });
  assert.equal(
    waits.reduce((sum, ms) => sum + ms, 0),
    179001,
  );
  await assert.rejects(
    waitForReservationFixtureWindow({
      now: () => Date.parse("2026-11-19T06:29:59Z"),
      wait: async () => {
        throw new Error("interrupted");
      },
      log: () => {},
    }),
    /interrupted/,
  );
});
test("invalid fixture clocks fail closed rather than scheduling an immediate expired booking", async () => {
  for (const value of [NaN, Infinity, -1, 1.5])
    await assert.rejects(
      waitForReservationFixtureWindow({ now: () => value }),
      /Invalid synthetic reservation clock/,
    );
});
