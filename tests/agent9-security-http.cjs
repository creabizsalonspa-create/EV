// Agent 9 read-only security regression against a locally launched disposable
// Laravel HTTP server. Deliberately refuses non-loopback destinations.
// Usage: node tests/agent9-security-http.cjs http://127.0.0.1:18764
const { test } = require("node:test");
const assert = require("node:assert/strict");

const input = process.argv[2];
if (!input) {
  console.error("Usage: node tests/agent9-security-http.cjs http://127.0.0.1:PORT");
  process.exit(2);
}
let base;
try {
  base = new URL(input);
} catch {
  console.error("Invalid isolated test server URL.");
  process.exit(2);
}
if (
  base.protocol !== "http:" ||
  base.hostname !== "127.0.0.1" ||
  !/^[0-9]{1,5}$/.test(base.port) ||
  Number(base.port) < 1024 ||
  Number(base.port) > 65535 ||
  base.pathname !== "/" ||
  base.search ||
  base.hash ||
  base.username ||
  base.password
) {
  console.error("Only an explicit HTTP 127.0.0.1 high port is permitted.");
  process.exit(2);
}

async function request(path, accept = "text/html") {
  const url = new URL(path, base);
  assert.equal(url.hostname, "127.0.0.1");
  const response = await fetch(url, {
    method: "GET",
    headers: { accept },
    redirect: "manual",
    signal: AbortSignal.timeout(5000),
  });
  return response;
}
function security(response, { strict = false, sensitive = false } = {}) {
  const csp = response.headers.get("content-security-policy");
  assert.ok(csp && csp.includes("object-src 'none'"));
  assert.ok(csp.includes("frame-ancestors 'none'"));
  assert.ok(csp.includes(strict ? "base-uri 'none'" : "base-uri 'self'"));
  assert.equal(response.headers.get("x-frame-options"), "DENY");
  assert.equal(response.headers.get("x-content-type-options"), "nosniff");
  if (sensitive) {
    assert.equal(response.headers.get("referrer-policy"), "no-referrer");
    const cache = response.headers.get("cache-control") || "";
    assert.match(cache, /no-store/);
    assert.match(cache, /private/);
  }
}
test("invoice hosted return is inert under spoofed success query", async () => {
  const sentinel = "qa-untrusted-provider-success-marker";
  const response = await request(
    "/payments/hosted-return?hosted_reference=" + sentinel +
      "&success=paid&payment_status=captured",
  );
  assert.equal(response.status, 200);
  security(response, { strict: true, sensitive: true });
  const body = await response.text();
  assert.ok(body.includes("Returning from a payment page does not confirm payment."));
  assert.ok(!body.includes(sentinel));
  assert.ok(!body.includes("data-simulation-checkout="));
});
test("corporate return cannot be reclassified by query-string override", async () => {
  const sentinel = "qa-untrusted-corporate-identity";
  const response = await request(
    "/payments/corporate-hosted-return?checkout_family=invoice&success=paid&hosted_reference=" +
      sentinel,
  );
  assert.equal(response.status, 200);
  security(response, { strict: true, sensitive: true });
  const body = await response.text();
  assert.ok(body.includes('data-checkout-family="corporate"'));
  assert.ok(!body.includes(sentinel));
  assert.ok(!body.includes("data-simulation-checkout="));
});
test("password reset link is not cacheable or referrer-disclosed", async () => {
  const response = await request("/reset-password/synthetic-qa-token");
  assert.equal(response.status, 200);
  security(response, { sensitive: true });
});
test("CSRF token endpoint cannot be shared through caches", async () => {
  const response = await request("/api/csrf", "application/json");
  assert.equal(response.status, 200);
  security(response);
  assert.match(response.headers.get("cache-control") || "", /no-store/);
  const origin = response.headers.get("access-control-allow-origin");
  const credentials = response.headers.get("access-control-allow-credentials");
  assert.ok(origin === null || origin === "*");
  assert.ok(origin !== "*" || credentials !== "true", "wildcard CORS must not expose credentials");
});
test("anonymous customer, vendor and Admin endpoints all deny private reads", async () => {
  const protectedPaths = [
    "/api/me",
    "/api/vehicles",
    "/api/reservations",
    "/api/fleets",
    "/api/admin/overview",
    "/api/charger-commands",
    "/api/support",
  ];
  for (const path of protectedPaths) {
    const response = await request(path, "application/json");
    assert.equal(response.status, 401, path);
    security(response);
    const cache = response.headers.get("cache-control") || "";
    assert.match(cache, /no-store/, path);
    assert.match(cache, /private/, path);
    const origin = response.headers.get("access-control-allow-origin");
  const credentials = response.headers.get("access-control-allow-credentials");
  assert.ok(origin === null || origin === "*");
  assert.ok(origin !== "*" || credentials !== "true", "wildcard CORS must not expose credentials");
  }
});
test("anonymous simulated payment routes never expose simulator controls", async () => {
  for (const path of [
    "/payments/hosted-simulation/1",
    "/payments/corporate-hosted-simulation/1",
  ]) {
    const response = await request(path);
    assert.ok([302, 403, 404].includes(response.status), path);
    security(response, { strict: response.status === 200 ? true : false, sensitive: true });
    const location = response.headers.get("location");
    if (location) {
      const destination = new URL(location, base);
      assert.equal(destination.origin, base.origin, "simulator login redirect stays on local origin");
    }
  }
});
