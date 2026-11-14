const withFixture = require("./helpers/certificate-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { createIngress } = require("../services/charger-ingress/server.cjs");
const https = require("node:https"),
  http = require("node:http"),
  assert = require("node:assert/strict");
const { randomBytes, randomUUID, createHash } = require("node:crypto");
const fs = require("node:fs"),
  path = require("node:path"),
  { spawn } = require("node:child_process");
withFixture(async (f) => {
  if (process.env.EV_CERTIFICATE_NATIVE === "1") {
    const input = path.join(f.directory, "native.json");
    fs.writeFileSync(
      input,
      JSON.stringify({
        fixture_only: true,
        base: f.base,
        owner: f.owner,
        other: f.other,
        password: f.password,
        company_id: f.company_id,
        chargers: f.chargers.map(
          ({ id, protocol, identity, pem, issuer_chain }) => ({
            id,
            protocol,
            identity,
            pem,
            issuer_chain,
          }),
        ),
      }),
      { mode: 0o600 },
    );
    await new Promise((resolve, reject) => {
      const child = spawn(
        "flutter",
        [
          "test",
          "--no-pub",
          "test/live_charger_certificates_test.dart",
          "--reporter",
          "expanded",
        ],
        {
          cwd: "apps/mobile",
          stdio: "inherit",
          env: { ...process.env, EV_NATIVE_CERTIFICATE_FIXTURE: input },
        },
      );
      child.on("error", reject);
      child.on("exit", (code) =>
        code === 0
          ? resolve()
          : reject(Error("Native certificate HTTP proof failed")),
      );
    });
    assert.deepEqual(f.inspect(), { commands: 0, invoices: 0 });
    return;
  }
  const owner = new BusinessClient({ base: f.base });
  owner.csrf = (await owner.request("csrf")).token;
  await owner.request("login", "POST", {
    email: f.owner,
    password: f.password,
  });
  owner.csrf = (await owner.request("csrf")).token;
  const actor = (await owner.request("me")).id;
  for (const charger of f.chargers) {
    charger.base = `vendor-ops/companies/${f.company_id}/chargers/${charger.id}/admission`;
    charger.profile = await owner.request(charger.base, "POST", {
      identity: charger.identity,
    });
  }
  // Native transport fixtures do not execute browser rendering.
  const { chromium } = require("playwright");
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    // Browser logout must not invalidate the API fixture's transport-proof session.
    const browserOwner = new BusinessClient({ base: f.base });
    browserOwner.csrf = (await browserOwner.request("csrf")).token;
    await browserOwner.request("login", "POST", {
      email: f.owner,
      password: f.password,
    });
    await context.addCookies(
      [...browserOwner.cookies].map(([name, value]) => ({
        name,
        value,
        url: f.base,
      })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    let attempts = 0;
    const first = f.chargers[0];
    await page.route(
      `**/api/${first.base}/certificates/enroll`,
      async (route) => {
        attempts++;
        const response = await route.fetch();
        assert.equal(response.status(), 200);
        await route.abort("failed");
      },
    );
    const open = async () => {
      const ops = page.locator("#vendor-operations");
      await ops
        .getByRole("button", { name: "Open company", exact: true })
        .first()
        .click();
      await ops
        .getByRole("button", { name: "Open chargers", exact: true })
        .click();
      await ops
        .getByRole("button", { name: "Secure charger admission", exact: true })
        .first()
        .click();
      await ops
        .getByRole("button", { name: "Charger certificates", exact: true })
        .click();
      return ops.locator("[data-charger-certificates]");
    };
    await page.goto(f.base);
    let pane = await open();
    await pane
      .getByLabel("Public PEM client certificate", { exact: true })
      .fill(first.pem);
    if (first.issuer_chain)
      await pane
        .getByLabel("Intermediate issuer certificates (optional)", {
          exact: true,
        })
        .fill(first.issuer_chain);
    await pane
      .getByLabel("Certificate operation reason", { exact: true })
      .fill("Explicit isolated browser certificate enrollment");
    await pane.locator("input[type=checkbox]").check();
    await pane
      .getByRole("button", {
        name: "Enroll or renew public certificate",
        exact: true,
      })
      .click();
    await pane.getByText("Operation unconfirmed.", { exact: false }).waitFor();
    assert.equal(attempts, 1);
    const stored = await page.evaluate(() =>
      Object.keys(sessionStorage)
        .filter((k) => k.startsWith("ev-charger-certificate:"))
        .map((k) => sessionStorage.getItem(k)),
    );
    assert.equal(stored.length, 1);
    assert.deepEqual(Object.keys(JSON.parse(stored[0])).sort(), [
      "action",
      "key",
    ]);
    await page.reload();
    pane = await open();
    await pane
      .getByRole("button", {
        name: "Resolve result or cancel unissued request",
        exact: true,
      })
      .click();
    await page
      .locator("#vendor-operations [data-admission]")
      .getByRole("button", { name: "Charger certificates", exact: true })
      .click();
    pane = page.locator("[data-charger-certificates]");
    await pane.getByText("Expiring soon", { exact: false }).waitFor();
    assert.equal(attempts, 1);
    await page.locator("#language").click();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth + 1,
      ),
      false,
    );
    await page.locator("#logout").click();
    await page.waitForFunction(
      () => document.querySelector("#vendor-operations").hidden,
    );
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  const upstream = http.createServer(),
    upstreamSockets = new Set();
  upstream.on("upgrade", (request, socket) => {
    upstreamSockets.add(socket);
    socket.on("error", () => {});
    socket.on("close", () => upstreamSockets.delete(socket));
    const accept = createHash("sha1")
      .update(
        request.headers["sec-websocket-key"] +
          "258EAFA5-E914-47DA-95CA-C5AB0DC85B11",
      )
      .digest("base64");
    socket.write(
      "HTTP/1.1 101 Switching Protocols\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Accept: " +
        accept +
        "\r\nSec-WebSocket-Protocol: " +
        request.headers["sec-websocket-protocol"] +
        "\r\n\r\n",
    );
  });
  await new Promise((r) => upstream.listen(0, "127.0.0.1", r));
  const gateway = createIngress({
    key: f.server.key,
    cert: f.server.pem,
    clientCa: f.root,
    secret: f.secret,
    authUrl: f.base + "/api/internal/charger-ingress/authenticate",
    upstreamUrl: "http://127.0.0.1:" + upstream.address().port,
    localTest: true,
    recheckMs: 1000,
  });
  await new Promise((r) => gateway.listen(0, "127.0.0.1", r));
  const sockets = new Set();
  const connect = (charger, secret, present = true, includeChain = true) =>
    new Promise((resolve, reject) => {
      const req = https.request({
        hostname: "127.0.0.1",
        port: gateway.address().port,
        path: "/" + charger.identity,
        ca: f.root,
        ...(present
          ? {
              key: charger.key,
              cert:
                charger.pem +
                (includeChain && charger.issuer_chain
                  ? "\n" + charger.issuer_chain
                  : ""),
            }
          : {}),
        headers: {
          Connection: "Upgrade",
          Upgrade: "websocket",
          "Sec-WebSocket-Key": randomBytes(16).toString("base64"),
          "Sec-WebSocket-Version": "13",
          "Sec-WebSocket-Protocol": "ocpp" + charger.protocol,
          Authorization:
            "Basic " +
            Buffer.from(charger.identity + ":" + secret).toString("base64"),
        },
      });
      req.setTimeout(10000, () => req.destroy(Error("Synthetic mTLS timeout")));
      req.on("error", reject);
      req.on("response", (r) => {
        r.resume();
        resolve({ status: r.statusCode });
      });
      req.on("upgrade", (r, s) => {
        sockets.add(s);
        s.on("error", () => {});
        resolve({ status: r.statusCode, socket: s });
      });
      req.end();
    });
  try {
    for (const charger of f.chargers) {
      let result = await owner.request(charger.base);
      if (!result.profile.managed_certificate_id) {
        await owner.request(charger.base + "/certificates/enroll", "POST", {
          idempotency_key: randomUUID(),
          expected_actor_id: actor,
          expected_profile_id: charger.profile.id,
          expected_version: 0,
          reason: "Explicit isolated TLS certificate enrollment",
          consent: true,
          public_certificate: charger.pem,
          ...(charger.issuer_chain
            ? { issuer_chain: charger.issuer_chain }
            : {}),
        });
      }
      result = await owner.request(charger.base);
      const rotation = await owner.request(
        charger.base + "/credential",
        "POST",
        { expected_version: result.profile.credential_version, days: 1 },
      );
      const connected = await connect(charger, rotation.secret);
      assert.equal(connected.status, 101);
      await assert.rejects(() => connect(charger, rotation.secret, false));
      if (charger.issuer_chain)
        await assert.rejects(() =>
          connect(charger, rotation.secret, true, false),
        );
      const revoke = await owner.request(
        charger.base + "/certificates/revoke",
        "POST",
        {
          idempotency_key: randomUUID(),
          expected_actor_id: actor,
          expected_profile_id: charger.profile.id,
          expected_version: rotation.profile.credential_version,
          reason: "Explicit isolated local certificate revocation",
          consent: true,
          certificate_id: result.profile.managed_certificate_id,
        },
      );
      assert.equal(revoke.device_commands_sent, false);
      if (!connected.socket.destroyed)
        await new Promise((resolve, reject) => {
          const timer = setTimeout(
            () => reject(Error("Revoked connection did not close at recheck")),
            8000,
          );
          connected.socket.once("close", () => {
            clearTimeout(timer);
            resolve();
          });
        });
      assert.equal((await connect(charger, rotation.secret)).status, 401);
    }
    assert.deepEqual(f.inspect(), { commands: 0, invoices: 0 });
    console.log(
      "PASS public certificate enrollment/lost response/reload recovery/EN-AR privacy; actual local TLS/mTLS + signed Laravel admission on both protocol routes, revocation recheck closes transport; no OCPP command, physical charging or billing",
    );
  } finally {
    for (const socket of sockets) socket.destroy();
    for (const socket of upstreamSockets) socket.destroy();
    await new Promise((r) => gateway.close(r));
    await new Promise((r) => upstream.close(r));
  }
}).catch((error) => {
  console.error(
    "Isolated certificate proof: " +
      error.name +
      ": " +
      String(error.message).split("\n")[0],
  );
  if (typeof error.actual === "number" && typeof error.expected === "number")
    console.error(
      "Expected status/count",
      error.expected,
      "actual",
      error.actual,
    );
  console.error(
    String(error.stack || "")
      .split("\n")
      .filter((line) => line.includes("charger-certificates.cjs"))
      .join("\n"),
  );
  process.exitCode = 1;
});
