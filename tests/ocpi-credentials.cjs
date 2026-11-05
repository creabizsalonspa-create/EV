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
  const rotated = await protocol("PUT", f.local_token, {
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
  });
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
    assert(!(await detail.textContent()).includes(newToken));
    assert(
      !(await page.locator("body").textContent()).includes(
        "SYNTHETIC-PEER-ROTATED",
      ),
    );
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.dir === "rtl");
    await page
      .getByRole("button", { name: "بيانات اعتماد الشريك", exact: true })
      .first()
      .click();
    await detail
      .getByText("قام الشريك بتدوير بيانات الاعتماد", { exact: false })
      .waitFor();
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
  console.log(
    "PASS: actual local protocol peer rotation/revocation and old-token denial; Admin EN/AR390px credential history, no tokens in UI, no external peer request or charging/billing action. Initial authority is explicitly synthetic.",
  );
}).catch(() => {
  console.error(
    "Synthetic OCPI credentials workflow failed; private protocol details withheld.",
  );
  process.exitCode = 1;
});
