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
      "payment-case-evidence",
    ],
  );
  assert.equal(started[0].time, 61000);
  assert.equal(started[2].time - ended[1], 61000);
  assert.equal(started[5].time - ended[4], 61000);
  assert.deepEqual(
    waits,
    [30000, 30000, 1000, 30000, 30000, 1000, 30000, 30000, 1000],
  );
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
      "auth-bootstrap",
      "session-cookie-read",
      "invoice-history",
      "money-input",
      "tariffs",
      "managed-gate",
      "canonical-topology",
      "reservation-lifecycle",
      "command-recovery",
      "charger-ingress",
      "charger-operations",
      "charger-diagnostics",
      "maintenance-windows",
      "signed-firmware",
      "ocpi-credentials",
      "ocpi-incoming",
      "ocpi-receiver",
      "charger-certificates",
      "rider-access",
      "charger-resets",
      "charging-business",
      "station-favorites",
      "client-demo-lifecycle",
      "vehicle-battery",
      "operating-calendar",
      "vehicle-reservation",
      "privacy-e2e",
      "privacy-admin-e2e",
      "charging-reviews",
      "ocpi-test-invoice",
      "ocpi-credit-picker",
      "partner-billing",
      "ocpi-unpaid-credit",
      "sandbox-roaming",
      "partner-favorites",
      "charger-configuration",
      "diagnostic-files",
    ],
  );
  assert.equal(starts[0].time, 61000);
  assert.equal(starts[6].time - ends[5], 61000);
  let offset = 0;
  for (const group of groups) {
    assert.equal(starts[offset].time - (offset ? ends[offset - 1] : 0), 61000);
    offset += group.length;
  }
});

test("operational flags are prepared before login windows and preparation failure stops the run", () => {
  const { prepareOperationalAdapters } = require("./operational-connected.cjs");
  const calls = [];
  prepareOperationalAdapters((script) => {
    calls.push(script);
    return { status: 0 };
  });
  assert.deepEqual(calls, [
    "./scripts/configuration-read-setup",
    "./scripts/diagnostic-logs-setup",
    "./scripts/reset-operations-setup",
  ]);
  const rejected = [];
  assert.throws(
    () =>
      prepareOperationalAdapters((script) => {
        rejected.push(script);
        return { status: 1 };
      }),
    /Local operational preparation failed/,
  );
  assert.equal(rejected.length, 1);
});

test("operational adapter setup remains intact after adding the battery discovery suite", () => {
  const { prepareOperationalAdapters } = require("./operational-connected.cjs");
  const invoked = [];
  prepareOperationalAdapters((script, args) => {
    invoked.push({ script, args });
    return { status: 0 };
  });
  assert.deepEqual(invoked, [
    { script: "./scripts/configuration-read-setup", args: [] },
    { script: "./scripts/diagnostic-logs-setup", args: [] },
    { script: "./scripts/reset-operations-setup", args: [] },
  ]);
});

test("a protected financial phase waits one real window in bounded pieces", async () => {
  const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
  let clock = 0;
  const waits = [];
  await waitForFreshWindow({
    now: () => clock,
    log: () => {},
    wait: async (ms) => {
      assert(ms > 0 && ms <= 30000);
      waits.push(ms);
      clock += ms;
    },
  });
  assert.equal(clock, 61000);
  assert.deepEqual(waits, [30000, 30000, 1000]);
});
test("phase readiness respects elapsed time without clearing or probing server state", async () => {
  const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
  let clock = 20000;
  const waits = [];
  await waitForFreshWindow({
    completed: 0,
    now: () => clock,
    log: () => {},
    wait: async (ms) => {
      waits.push(ms);
      clock += ms;
    },
  });
  assert.deepEqual(waits, [30000, 11000]);
  assert.equal(clock, 61000);
});
test("a failed rate-window wait aborts before the financial phase can run", async () => {
  const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
  const failure = new Error("timer unavailable");
  let writes = 0;
  await assert.rejects(
    async () => {
      await waitForFreshWindow({
        now: () => 0,
        log: () => {},
        wait: async () => {
          throw failure;
        },
      });
      writes++;
    },
    (error) => error === failure,
  );
  assert.equal(writes, 0);
});

test("fractional deadline crossings never schedule a zero or negative timer", async () => {
  const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
  const readings = [0, 60999.9, 61000.5];
  const waits = [];
  await waitForFreshWindow({
    now: () => readings.shift() ?? 61001,
    log: () => {},
    wait: async (ms) => {
      assert(ms > 0 && ms <= 30000);
      waits.push(ms);
    },
  });
  assert.deepEqual(waits, [1]);
});
