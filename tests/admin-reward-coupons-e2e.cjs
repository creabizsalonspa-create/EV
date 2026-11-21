const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes, createHash } = require("node:crypto");
const assert = require("node:assert/strict");
const fs = require("node:fs");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/coupon-admin-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(f.fixture_only);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  let checks = 0;
  const errors = [];
  try {
    async function login(role) {
      const context = await browser.newContext(),
        page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(base);
      const csrf = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute("content");
      const r = await context.request.post(base + "/api/login", {
        data: { email: f.users[role], password },
        headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
      });
      assert.equal(r.status(), 200);
      checks++;
      return { context, page };
    }
    const admin = await login("admin"),
      a = admin.page;
    await a.goto(base + "/admin/reward-coupons");
    await a.locator("#workspace").waitFor({ state: "visible" });
    await a.locator("#editor summary").click();
    await a.locator("#rewards-refresh").click();
    const card = a
      .locator("#rewards article")
      .filter({
        hasText: "<img src=x onerror=alert(1)> Synthetic coupon reward",
      })
      .first();
    await card
      .getByRole("button", { name: "Choose this reward", exact: true })
      .click();
    assert.equal(await a.locator("#rewards img").count(), 0);
    checks++;
    const form = a.locator("#coupon-form"),
      code = "SYNTHETIC-" + run.toUpperCase() + "-REWARD";
    await form.locator("[name=code]").fill(code);
    await form.locator("[name=claim_limit]").fill("2");
    await form.locator("[name=per_user_limit]").fill("1");
    // A real committed publication loses its response. Resolve only by the
    // actor-bound receipt, without reposting or manufacturing another coupon.
    let posts = 0,
      publicationKey;
    await a.route("**/api/commercial/coupons", async (route) => {
      if (route.request().method() === "POST") {
        posts++;
        publicationKey = route.request().postDataJSON().idempotency_key;
        const response = await route.fetch();
        assert.equal(response.status(), 201);
        assert(!(await response.text()).includes(code));
        await route.abort("failed");
      } else await route.continue();
    });
    await form
      .getByRole("button", { name: "Publish coupon", exact: true })
      .click();
    await a.locator("#status").filter({ hasText: "Request failed." }).waitFor();
    await form
      .getByRole("button", { name: "Find saved publication", exact: true })
      .click();
    await a.locator("#status").filter({ hasText: "Saved." }).waitFor();
    assert.equal(posts, 1);
    assert.equal(await form.locator("[name=code]").inputValue(), "");
    checks++;
    const csrf = await a
      .locator('meta[name="csrf-token"]')
      .getAttribute("content");
    const receipt = await admin.context.request.post(
      base + "/api/commercial/coupons/publication-receipt",
      {
        headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
        data: { idempotency_key: publicationKey },
      },
    );
    assert.equal(receipt.status(), 200);
    const coupon = (await receipt.json()).coupon;
    assert(coupon.id > 0);
    checks++;
    const customer = await login("customer"),
      c = customer.page;
    await c.goto(base);
    const customerCsrf = await c
      .locator('meta[name="csrf-token"]')
      .getAttribute("content");
    const key = randomBytes(24).toString("hex");
    const payload = { code, idempotency_key: key };
    const claim = await customer.context.request.post(
      base + "/api/commercial/coupons/claim",
      {
        headers: { "X-CSRF-TOKEN": customerCsrf, Accept: "application/json" },
        data: payload,
      },
    );
    assert.equal(claim.status(), 200);
    const body = (await claim.json()).claim;
    assert.equal(body.points_cost, 0);
    assert.equal(body.cash_value, false);
    assert.equal(body.status, "pending");
    assert.equal(body.delivery_verified, false);
    checks++;
    const hash = (s) => createHash("sha256").update(s).digest("hex");
    const me = await customer.context.request.get(base + "/api/me");
    const user = (await me.json()).id;
    assert.equal(
      body.request_binding,
      hash(hash(code) + ":" + hash("coupon-claim:" + user + ":" + key)),
    );
    checks++;
    const published = a
      .locator("#coupons article")
      .filter({ hasText: `#${coupon.id}` });
    await published
      .locator("textarea")
      .fill("Synthetic audited stop of new claims");
    await published
      .getByRole("button", { name: "Stop new claims", exact: true })
      .click();
    await a
      .locator("#coupons")
      .getByText("Revoked", { exact: true })
      .first()
      .waitFor();
    checks++;
    const retry = await customer.context.request.post(
      base + "/api/commercial/coupons/claim",
      {
        headers: { "X-CSRF-TOKEN": customerCsrf, Accept: "application/json" },
        data: payload,
      },
    );
    assert.equal(retry.status(), 200);
    assert.equal((await retry.json()).claim.id, body.id);
    checks++;
    const denied = await customer.context.request.post(
      base + "/api/commercial/coupons/claim",
      {
        headers: { "X-CSRF-TOKEN": customerCsrf, Accept: "application/json" },
        data: { code, idempotency_key: "new-" + key },
      },
    );
    assert.equal(denied.status(), 409);
    checks++;
    await a.locator("#language").selectOption("ar");
    await a.locator("#workspace").waitFor({ state: "visible" });
    assert.equal(await a.locator("html").getAttribute("dir"), "rtl");
    await a.locator("#theme-choice").selectOption("dark");
    await a.setViewportSize({ width: 390, height: 844 });
    assert(
      await a.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    checks++;
    fs.mkdirSync("artifacts/operational-browser/reward-coupons", {
      recursive: true,
    });
    await a.screenshot({
      path: "artifacts/operational-browser/reward-coupons/ar-dark-mobile.png",
      fullPage: true,
    });
    const forbidden = await customer.context.request.get(
      base + "/admin/reward-coupons",
    );
    assert.equal(forbidden.status(), 403);
    checks++;
    assert.deepEqual(errors, []);
    console.log(
      `Admin coupon actual-Laravel browser: ${checks} checks passed (private publication recovery, canonical noncash claim, revocation, request binding, EN/AR/RTL/theme/mobile, authority).`,
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
