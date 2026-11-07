// Controlled response ordering; no real credentials, persistence or network calls.
const vm = require("node:vm"),
  fs = require("node:fs"),
  assert = require("node:assert/strict");
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((a, b) => {
    resolve = a;
    reject = b;
  });
  return { promise, resolve, reject };
};
(async () => {
  let response,
    requests = 0;
  const context = vm.createContext({
    AbortSignal,
    navigator: { onLine: true },
    fetch: () => {
      requests++;
      return response.promise;
    },
  });
  vm.runInContext(
    fs.readFileSync("services/business-api/public/client.js", "utf8"),
    context,
  );
  const api = vm.runInContext('new EvApi("synthetic-csrf")', context);
  const changed = (e) => e.name === "SessionChangedError";
  for (const [status, ok] of [
    [200, true],
    [401, false],
    [204, true],
  ]) {
    response = deferred();
    let parsed = false;
    const pending = api.request("vehicles");
    api.invalidateSession();
    response.resolve({
      status,
      ok,
      json: async () => {
        parsed = true;
        return { private: "old actor" };
      },
    });
    await assert.rejects(pending, changed);
    assert.equal(parsed, false, "stale headers must not be parsed");
  }
  for (const status of [200, 401]) {
    response = deferred();
    const body = deferred(),
      entered = deferred();
    const pending = api.request("me");
    response.resolve({
      status,
      ok: status === 200,
      json: () => {
        entered.resolve();
        return body.promise;
      },
    });
    await entered.promise;
    api.invalidateSession();
    body.resolve({ id: 1, name: "old actor" });
    await assert.rejects(pending, changed);
  }
  response = deferred();
  const brokenBody = deferred(),
    entered = deferred();
  const malformed = api.request("me");
  response.resolve({
    status: 401,
    ok: false,
    json: () => {
      entered.resolve();
      return brokenBody.promise;
    },
  });
  await entered.promise;
  api.invalidateSession();
  brokenBody.reject(new Error("malformed old body"));
  await assert.rejects(malformed, changed);
  response = deferred();
  const network = api.request("vehicles");
  api.invalidateSession();
  response.reject(new Error("old transport failed"));
  await assert.rejects(network, changed);
  for (const path of [
    "stations?page=2",
    "regions",
    "roaming/locations?page=2",
  ]) {
    response = deferred();
    const pending = api.request(path);
    api.invalidateSession();
    response.resolve({
      status: 200,
      ok: true,
      json: async () => ({ data: [] }),
    });
    assert.equal((await pending).data.length, 0);
  }
  response = deferred();
  const csrf = api.request("csrf");
  api.invalidateSession();
  response.resolve({
    status: 200,
    ok: true,
    json: async () => ({ token: "old" }),
  });
  await assert.rejects(csrf, changed);
  response = deferred();
  const fresh = api.request("vehicles");
  response.resolve({
    status: 200,
    ok: true,
    json: async () => ({ private: "current actor" }),
  });
  assert.equal((await fresh).private, "current actor");
  response = deferred();
  const rejected = api.request("stations", "POST", { name: "synthetic" });
  response.resolve({ status: 503, ok: false, json: async () => null });
  await assert.rejects(rejected, (e) => e.status === 503);
  assert.equal(
    requests,
    13,
    "No request may be replayed by generation handling.",
  );
  console.log(
    "PASS: stale protected headers/streamed JSON/401/204/errors and CSRF are discarded; public reads survive; current account responses work; no automatic replay.",
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
