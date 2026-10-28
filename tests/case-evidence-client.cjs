// No network or credentials. Guard the shared multipart transport boundary.
const vm = require("node:vm"),
  fs = require("node:fs"),
  assert = require("node:assert/strict");
(async () => {
  let sent;
  const context = vm.createContext({
    FormData,
    AbortSignal,
    navigator: { onLine: true },
    fetch: async (path, options) => {
      sent = { path, options };
      return { status: 201, ok: true, json: async () => ({ id: 1 }) };
    },
  });
  vm.runInContext(
    fs.readFileSync("services/business-api/public/client.js", "utf8"),
    context,
  );
  const api = vm.runInContext('new EvApi("synthetic-csrf")', context),
    form = new FormData();
  form.set("kind", "receipt");
  form.set("consent", "1");
  await api.request("commercial/cases/7/evidence", "POST", form);
  assert.equal(sent.path, "/api/commercial/cases/7/evidence");
  assert.equal(sent.options.body, form);
  assert.equal(
    sent.options.headers["Content-Type"],
    undefined,
    "Browser must generate the multipart boundary",
  );
  assert.equal(sent.options.headers["X-CSRF-TOKEN"], "synthetic-csrf");
  assert.equal(sent.options.credentials, "same-origin");
  assert.equal(sent.options.redirect, "error");
  assert.equal(sent.options.cache, "no-store");
  await api.request("commercial/cases", "POST", { invoice_id: 7 });
  assert.equal(sent.options.headers["Content-Type"], "application/json");
  assert.equal(sent.options.body, '{"invoice_id":7}');
  console.log(
    "PASS multipart boundary, same-origin CSRF/cookie, no redirects/cache and unchanged JSON serialization.",
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
