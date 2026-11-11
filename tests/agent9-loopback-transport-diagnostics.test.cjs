"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");

test("transport refusal has a sanitized cause code and never retries a mutation", async () => {
  let calls = 0;
  const client = new BusinessClient({
    fetch: async () => {
      calls++;
      throw Object.assign(new TypeError("fetch failed"), {
        cause: { code: "ECONNREFUSED" },
      });
    },
  });
  client.csrf = "secret-csrf";
  client.cookies.set("session", "secret-cookie");

  await assert.rejects(
    client.request("diagnostic-logs?private_token=secret-url", "POST", {
      privatePayload: "secret-body",
    }),
    (error) => {
      assert.match(error.message, /Synthetic loopback POST transport failed \(ECONNREFUSED\)/);
      assert.match(error.message, /outcome unknown, no request replay/);
      assert.doesNotMatch(error.message, /secret|diagnostic-logs|privatePayload|session/);
      return true;
    },
  );
  assert.equal(calls, 1, "uncertain transport outcomes must never be replayed");
});

test("invalid cause codes and method strings cannot inject credentials into diagnostics", async () => {
  let calls = 0;
  const client = new BusinessClient({
    fetch: async () => {
      calls++;
      throw { name: "TypeError", cause: { code: "ECONNREFUSED\nsecret-auth" } };
    },
  });
  await assert.rejects(client.request("csrf?password=secret", "POST\nsecret-method"), (error) => {
    assert.match(error.message, /Synthetic loopback REQUEST transport failed \(NETWORK_FAILURE\)/);
    assert.doesNotMatch(error.message, /secret|password|ECONNREFUSED/);
    return true;
  });
  assert.equal(calls, 1);
});

test("timeouts fail closed without triggering a second fetch", async () => {
  let calls = 0;
  const client = new BusinessClient({
    fetch: async () => {
      calls++;
      throw { name: "TimeoutError" };
    },
  });
  await assert.rejects(client.request("csrf"), /Synthetic loopback GET transport failed \(TIMEOUT\)/);
  assert.equal(calls, 1);
});

test("an HTTP 503 remains an explicit failure without a second call", async () => {
  let calls = 0;
  const client = new BusinessClient({
    fetch: async () => {
      calls++;
      return {
        ok: false,
        status: 503,
        headers: { getSetCookie: () => [] },
        json: async () => ({ message: "provider down" }),
      };
    },
  });
  await assert.rejects(client.request("commercial/payment-recovery/1/reconcile", "POST", {}),
    /POST commercial\/payment-recovery\/1\/reconcile failed \(503\): provider down/);
  assert.equal(calls, 1);
});
