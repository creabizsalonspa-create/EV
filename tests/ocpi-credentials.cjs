const {
  withCredentialsFixture,
} = require("./helpers/ocpi-credentials-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
withCredentialsFixture(async (f) => {
  assert(f.fixture_only);
  const client = new BusinessClient({ base: f.base });
  client.csrf = (await client.request("csrf")).token;
  await client.request("login", "POST", {
    email: f.admin,
    password: f.password,
  });
  client.csrf = (await client.request("csrf")).token;
  const path = `ocpi/providers/${f.provider_id}/credentials`;
  const state = await client.request(path);
  assert.equal(state.state, "active");
  assert.deepEqual(state.available_actions, ["rotate", "unregister"]);
  assert.equal(state.capability_matrix.evidence, "NEGOTIATED_AND_CONFIGURED_SOFTWARE_ONLY");
  assert.equal(state.capability_matrix.credentials_current, true);
  assert.equal(state.capability_matrix.partner_conformance_verified, false);
  assert.equal(state.capability_matrix.live_roaming_verified, false);
  assert.equal(state.capability_matrix.payment_authority, false);
  assert.deepEqual(state.capability_matrix.modules.map((m) => m.module), ["locations", "tariffs", "sessions", "cdrs"]);
  assert(state.capability_matrix.modules.every((m) => Object.values(m).every((v) => typeof v === "string" || typeof v === "boolean")));
  assert(!JSON.stringify(state).includes(f.local_token));
  const protocol = async (method, token, body) => {
    const r = await fetch(f.base + "/api/roaming-protocol/2.2.1/credentials", {
      method,
      headers: {
        Authorization: "Token " + Buffer.from(token).toString("base64"),
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: r.status, body: await r.json() };
  };
  const unauthenticated = await fetch(
    f.base + "/api/roaming-protocol/versions",
  );
  assert.equal(unauthenticated.status, 401);
  const offered = {
    token: "SYNTHETIC-PEER-ROTATED",
    url: "https://partner.example/ocpi/versions",
    roles: [
      {
        role: "CPO",
        country_code: "NL",
        party_id: "ABC",
        business_details: { name: "Synthetic peer" },
      },
    ],
  };
  // Deterministic fixture transport rejects unknown token proof without DNS,
  // without replacing the previous generation or logging the offered token.
  const rejected = await protocol("PUT", f.local_token, {
    ...offered,
    token: "SYNTHETIC-UNPROVEN-PEER",
  });
  assert.equal(rejected.status, 400);
  assert.equal(rejected.body.status_code, 2000);
  assert.equal(rejected.body.data, null);
  assert.equal((await protocol("GET", f.local_token)).status, 200);
  const unchanged = await client.request(path);
  assert.equal(unchanged.generation, state.generation);
  assert(!unchanged.operations.data.some(row => row.action === "peer_rotate"));
  const rotated = await protocol("PUT", f.local_token, offered);
  assert.equal(rotated.status, 200);
  const newToken = rotated.body.data.token;
  assert.notEqual(newToken, f.local_token);
  assert.equal((await protocol("GET", f.local_token)).status, 401);
  assert.equal((await protocol("GET", newToken)).status, 200);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    await context.addCookies(
      [...client.cookies].map(([name, value]) => ({
        name,
        value,
        url: f.base,
      })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(f.base);
    const card = page.locator("#roaming-operations article").filter({
      has: page.getByRole("heading", { name: f.name, exact: true }),
    });
    await card
      .getByRole("button", { name: "Partner credentials", exact: true })
      .click();
    const detail = page.locator("[data-roaming-detail]");
    await detail
      .getByText("Peer rotated credentials", { exact: false })
      .waitFor();
    await detail.getByText("Configured or negotiated modules only", { exact: false }).waitFor();
    await detail.getByText("locations: Partner read", { exact: false }).waitFor();
    assert(!(await detail.textContent()).includes(newToken));
    assert(
      !(await page.locator("body").textContent()).includes(
        "SYNTHETIC-PEER-ROTATED",
      ),
    );
    // A malformed API capability cannot leave the old Admin lifecycle forms
    // or a forged charging-authority badge on screen.
    const credentialPattern = `**/api/ocpi/providers/${f.provider_id}/credentials*`;
    const forgeCapability = async (route) => {
      const response = await route.fetch();
      const body = await response.json();
      body.capability_matrix.modules[0].local_sender_active = true;
      body.capability_matrix.physical_availability_verified = true;
      await route.fulfill({ response, json: body });
    };
    await page.route(credentialPattern, forgeCapability);
    await detail.getByRole("button", { name: "Refresh", exact: true }).click();
    await detail
      .getByText("Partner capability evidence is inconsistent", { exact: false })
      .waitFor();
    assert.equal(await detail.locator("form").count(), 0);
    assert(!(await detail.textContent()).includes(newToken));
    await page.unroute(credentialPattern, forgeCapability);
    await detail.getByRole("button", { name: "Refresh", exact: true }).click();
    await detail
      .getByText("Peer rotated credentials", { exact: false })
      .waitFor();
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.dir === "rtl");
    await page
      .getByRole("button", { name: "بيانات اعتماد الشريك", exact: true })
      .first()
      .click();
    await detail
      .getByText("قام الشريك بتدوير بيانات الاعتماد", { exact: false })
      .waitFor();
    await detail.getByText("وحدات الشريك المُهيأة", { exact: false }).waitFor();
    await detail.getByText("locations: قراءة الشريك", { exact: false }).waitFor();
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth + 1,
      ),
    );
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
  }
  assert.equal((await protocol("DELETE", newToken)).status, 200);
  assert.equal((await protocol("GET", newToken)).status, 401);
  const revoked = await client.request(path);
  assert.equal(revoked.state, "revoked");
  assert.deepEqual(revoked.available_actions, []);
  assert.equal(revoked.capability_matrix.credentials_current, false);
  assert(revoked.capability_matrix.modules.every((m) => m.local_sender_active === false));
  console.log(
    "PASS: actual local protocol peer rotation/revocation and old-token denial; Admin EN/AR390px credential history, no tokens in UI, refused proof preserves generation, valid proof uses isolated HTTP fake only; no external network or charging/billing action.",
  );
}).catch(() => {
  console.error(
    "Synthetic OCPI credentials workflow failed; private protocol details withheld.",
  );
  process.exitCode = 1;
});
