const { test } = require("node:test");
const assert = require("node:assert/strict");
const { groups, runDiscoverySuites } = require("./discovery-connected.cjs");

test("discovery driver preserves all original suites and isolates shared catalog windows", async () => {
  assert.deepEqual(groups.flat(), [
    "nearby-catalog",
    "map-assets",
    "discovery-map",
    "partner-nearby",
    "open-charge-map-ui",
    "discovery-routing",
  ]);
  let clock = 0;
  const calls = [],
    waits = [];
  await runDiscoverySuites({
    now: () => clock,
    wait: async (ms) => {
      assert(ms <= 30000);
      waits.push(ms);
      clock += ms;
    },
    log: () => {},
    execute: (file, args) => {
      assert.equal(file, process.execPath);
      calls.push({ suite: args[0], at: clock });
      clock += 250;
      return { status: 0 };
    },
  });
  assert.equal(calls.length, 6);
  assert(calls[0].at >= 61000);
  assert(calls[1].at - calls[0].at >= 61250);
  assert(calls[5].at - calls[4].at >= 61250);
  assert.equal(
    waits.reduce((a, b) => a + b, 0),
    183000,
  );
});
test("a discovery assertion failure stops immediately without another request or suite retry", async () => {
  let clock = 0,
    calls = 0;
  await assert.rejects(
    runDiscoverySuites({
      now: () => clock,
      wait: async (ms) => {
        clock += ms;
      },
      log: () => {},
      execute: () => {
        calls++;
        return { status: 1 };
      },
    }),
    /no assertion retry: nearby-catalog/,
  );
  assert.equal(calls, 1);
});
