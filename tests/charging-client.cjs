const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
(async () => {
  let calls = [],
    waits = [];
  const payload = {
    amount_minor: 100,
    idempotency_key: "synthetic-fixed-intent",
  };
  const client = new BusinessClient({
    fetch: async (url, options) => {
      calls.push({ url, method: options.method, body: options.body });
      return calls.length === 1
        ? Response.json(
            { message: "budget" },
            { status: 429, headers: { "Retry-After": "7" } },
          )
        : Response.json({ accepted: true });
    },
    wait: async (ms) => {
      waits.push(ms);
      payload.amount_minor = 999;
    },
  });
  assert.deepEqual(
    await client.request(
      "finance/invoices/1/simulate-payment",
      "POST",
      payload,
    ),
    { accepted: true },
  );
  assert.deepEqual(waits, [8000]);
  assert.equal(calls.length, 2);
  assert.deepEqual(calls[0], calls[1]);
  assert.equal(JSON.parse(calls[1].body).amount_minor, 100);
  for (const outcome of ["503", "transport", "repeated429", "invalid429"]) {
    let attempts = 0,
      delays = [];
    const c = new BusinessClient({
      fetch: async () => {
        attempts++;
        if (outcome === "transport")
          throw new TypeError("synthetic connection failure");
        return Response.json(
          { message: "fixture rejection" },
          {
            status: outcome === "503" ? 503 : 429,
            headers: {
              "Retry-After": outcome === "invalid429" ? "Infinity" : "0",
            },
          },
        );
      },
      wait: async (ms) => delays.push(ms),
    });
    await assert.rejects(() => c.request("reservations/1/csms-start", "POST"));
    assert.equal(attempts, outcome === "repeated429" ? 2 : 1);
    assert.deepEqual(delays, outcome === "repeated429" ? [1000] : []);
  }
  console.log(
    "PASS: explicit 429 honors bounded Retry-After once with an immutable identical payload; repeated/invalid limits fail; uncertain transport and 503 commands are never replayed. Server budgets are unchanged.",
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
