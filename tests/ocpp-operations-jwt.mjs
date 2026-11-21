// Contract against the pinned CitrineOS implementation, not a replacement CSMS.
// Run inside its image: no listeners, devices, providers or persisted tokens.
import assert from "node:assert/strict";
import { generateKeyPairSync, createPublicKey, sign } from "node:crypto";
import { createRequire } from "node:module";
import { dirname, join } from "node:path";
import { pathToFileURL } from "node:url";
import { test } from "node:test";
const require = createRequire(
  "/usr/local/apps/citrineos/apps/ocpp-server/package.json",
);
const upstream = dirname(require.resolve("@citrineos/ocpp"));
const { JwtAuthProvider } = await import(
  pathToFileURL(
    join(upstream, "src/apis/authorization/provider/jwt-auth-provider.js"),
  )
);
const { apiAuthPluginFp } = await import(
  pathToFileURL(join(upstream, "src/apis/authorization/api-auth-plugin.js"))
);
const fastify = createRequire(require.resolve("@citrineos/base"))("fastify");
const { privateKey, publicKey } = generateKeyPairSync("rsa", {
  modulusLength: 2048,
});
const logger = {
  getSubLogger() {
    return this;
  },
  child() {
    return this;
  },
  info() {},
  warn() {},
  error() {},
  debug() {},
  trace() {},
};
const issuer = "https://identity.example.test";
const audience = "https://csms.example.test";
function token(overrides = {}, key = privateKey) {
  const now = Math.floor(Date.now() / 1000);
  const encode = (value) =>
    Buffer.from(JSON.stringify(value)).toString("base64url");
  const input =
    encode({ alg: "RS256", typ: "JWT", kid: "current" }) +
    "." +
    encode({
      iss: issuer,
      aud: audience,
      sub: "business-operations",
      tenantId: 1,
      iat: now,
      exp: now + 300,
      roles: ["admin"],
      scope: "csms:operations",
      ...overrides,
    });
  return (
    input +
    "." +
    sign("RSA-SHA256", Buffer.from(input), key).toString("base64url")
  );
}
async function fixture() {
  // A deliberately narrow fixture policy; production policy enrollment remains separate.
  const policy = {
    hasPermission: (roles, permission) =>
      roles.includes("admin") && permission === "fixture.operations",
  };
  const provider = new JwtAuthProvider(
    {
      publicKey: createPublicKey(
        publicKey.export({ type: "spki", format: "pem" }),
      ),
      issuer,
      audience,
      rolesClaim: "roles",
      tenantClaim: "tenantId",
    },
    policy,
    logger,
  );
  const app = fastify({ logger: false });
  await app.register(apiAuthPluginFp, { provider, logger });
  for (const protocol of ["1.6", "2.0.1"]) {
    app.post(
      "/ocpp/" + protocol + "/fixture-operation",
      { config: { permission: "fixture.operations" } },
      async () => ({ authenticated: true, device_commands_sent: 0 }),
    );
  }
  app.post("/missing-permission", async () => ({ unexpected: true }));
  await app.ready();
  return app;
}
for (const protocol of ["1.6", "2.0.1"]) {
  test(
    protocol +
      " accepts the canonical signed service-identity claim contract without device effects",
    async () => {
      const app = await fixture();
      try {
        const r = await app.inject({
          method: "POST",
          url: "/ocpp/" + protocol + "/fixture-operation?tenantId=1",
          headers: { authorization: "Bearer " + token() },
        });
        assert.equal(r.statusCode, 200);
        assert.deepEqual(r.json(), {
          authenticated: true,
          device_commands_sent: 0,
        });
      } finally {
        await app.close();
      }
    },
  );
  for (const [name, claims, status] of [
    ["wrong issuer", { iss: "https://other.example.test" }, 401],
    ["wrong audience", { aud: "https://other.example.test" }, 401],
    ["expired", { exp: 1 }, 401],
    ["future not-before", { nbf: 9999999999 }, 401],
    ["wrong tenant", { tenantId: 2 }, 403],
    ["insufficient role", { roles: ["viewer"] }, 403],
  ]) {
    test(protocol + " rejects " + name, async () => {
      const app = await fixture();
      try {
        const r = await app.inject({
          method: "POST",
          url: "/ocpp/" + protocol + "/fixture-operation?tenantId=1",
          headers: { authorization: "Bearer " + token(claims) },
        });
        assert.equal(r.statusCode, status);
        assert.equal(r.json().device_commands_sent, undefined);
      } finally {
        await app.close();
      }
    });
  }
}
test("pinned upstream plugin rejects missing authority, an unrelated signer and undeclared permissions", async () => {
  const app = await fixture();
  try {
    const other = generateKeyPairSync("rsa", { modulusLength: 2048 });
    for (const headers of [
      {},
      { authorization: "Bearer " + token({}, other.privateKey) },
    ]) {
      const r = await app.inject({
        method: "POST",
        url: "/ocpp/1.6/fixture-operation?tenantId=1",
        headers,
      });
      assert.equal(r.statusCode, 401);
    }
    const missing = await app.inject({
      method: "POST",
      url: "/missing-permission?tenantId=1",
      headers: { authorization: "Bearer " + token() },
    });
    assert.equal(missing.statusCode, 403);
  } finally {
    await app.close();
  }
});
