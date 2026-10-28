const { test } = require("node:test");
const assert = require("node:assert/strict");
const { runFinancialSuites } = require("./financial-connected.cjs");
test("fresh login windows include initial prior-suite budget, bounded waits and fixture completion time", async () => {
  let clock = 0;
  const waits = [],
    started = [],
    ended = [];
  await runFinancialSuites({
    now: () => clock,
    log: () => {},
    wait: async (ms) => {
      assert(ms > 0 && ms <= 30000);
      waits.push(ms);
      clock += ms;
    },
    run: async (suite) => {
      started.push({ suite, time: clock });
      clock += 4000;
      ended.push(clock);
    },
  });
  assert.deepEqual(
    started.map((x) => x.suite),
    [
      "payout-documents",
      "corporate-billing",
      "external-disputes",
      "external-disputes-ui",
      "loyalty",
    ],
  );
  assert.equal(started[0].time, 61000);
  assert.equal(started[2].time - ended[1], 61000);
  assert.deepEqual(waits, [30000, 30000, 1000, 30000, 30000, 1000]);
});
test("a real assertion failure stops orchestration without replay or skipping ahead", async () => {
  let clock = 0;
  const calls = [];
  const failure = new Error("synthetic browser assertion failure");
  await assert.rejects(
    runFinancialSuites({
      now: () => clock,
      log: () => {},
      wait: async (ms) => {
        clock += ms;
      },
      run: async (suite) => {
        calls.push(suite);
        throw failure;
      },
    }),
    (e) => e === failure,
  );
  assert.deepEqual(calls, ["payout-documents"]);
});
test("post-finance operational windows preserve all suites and do not consume the previous finance window", async () => {
  const { groups } = require("./operational-connected.cjs");
  const { runPacedSuites } = require("./helpers/auth-batches.cjs");
  let clock = 0;
  const starts = [];
  const ends = [];
  await runPacedSuites(groups, {
    now: () => clock,
    log: () => {},
    wait: async (ms) => {
      assert(ms > 0 && ms <= 30000);
      clock += ms;
    },
    run: async (suite) => {
      starts.push({ suite, time: clock });
      clock += 10000;
      ends.push(clock);
    },
  });
  assert.deepEqual(
    starts.map((x) => x.suite),
    [
      "invoice-history",
      "money-input",
      "tariffs",
      "managed-gate",
      "canonical-topology",
      "reservation-lifecycle",
      "command-recovery",
      "charger-ingress",
      "charger-operations",
      "charging-business",
      "station-favorites",
    ],
  );
  assert.equal(starts[0].time, 61000);
  assert.equal(starts[4].time - ends[3], 61000);
  assert.equal(starts[9].time - ends[8], 61000);
});
