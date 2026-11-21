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
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/referral-admin-browser.php", "create", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(fixture.fixture_only);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  const errors = [];
  let checks = 0;
  try {
    async function login(role) {
      const context = await browser.newContext(),
        page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(base);
      const csrf = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute("content");
      const response = await context.request.post(base + "/api/login", {
        data: { email: fixture.users[role], password },
        headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
      });
      assert.equal(response.status(), 200);
      await page.goto(base);
      return {
        context,
        page,
        csrf: await page
          .locator('meta[name="csrf-token"]')
          .getAttribute("content"),
      };
    }
    async function post(client, path, body) {
      return client.context.request.post(base + "/api/" + path, {
        data: body,
        headers: { "X-CSRF-TOKEN": client.csrf, Accept: "application/json" },
      });
    }
    const admin = await login("admin"),
      a = admin.page;
    await a.goto(base + "/admin/referral-programs");
    admin.csrf = await a
      .locator('meta[name="csrf-token"]')
      .getAttribute("content");
    await a.locator("#workspace").waitFor({ state: "visible" });
    await a.locator("#editor summary").click();
    await a.locator("#rewards-refresh").click();
    await a
      .locator("#rewards article")
      .filter({ hasText: "Synthetic referrer " + run })
      .getByRole("button", { name: "Choose for inviter", exact: true })
      .click();
    await a
      .locator("#rewards article")
      .filter({ hasText: "Synthetic member " + run })
      .getByRole("button", { name: "Choose for new member", exact: true })
      .click();
    const form = a.locator("#programme-form"),
      name = "<img src=x onerror=alert(1)> Referral " + run;
    for (const [k, v] of Object.entries({
      name,
      name_ar: "برنامج إحالة تجريبي",
      terms:
        "Explicit consent before the first charging payment. A fully captured qualifying post-consent first invoice reserves manual noncash benefits. No money, discount or delivery proof.",
      terms_ar:
        "موافقة صريحة قبل أول دفعة شحن؛ تمنح الفاتورة المدفوعة المؤهلة مكافأتين يدويتين غير نقديتين. لا يوجد تحويل أموال أو خصم أو إثبات تسليم.",
      minimum: "١٫٠٠",
      claim_limit: "5",
      per_referrer_limit: "3",
    }))
      await form.locator(`[name="${k}"]`).fill(v);
    let publicationKey,
      posts = 0;
    await a.route("**/api/commercial/referral-programs", async (route) => {
      if (route.request().method() === "POST") {
        posts++;
        publicationKey = route.request().postDataJSON().idempotency_key;
        const response = await route.fetch();
        assert.equal(response.status(), 201);
        await route.abort("failed");
      } else await route.continue();
    });
    await form
      .getByRole("button", { name: "Publish programme", exact: true })
      .click();
    await a.locator("#status").filter({ hasText: "Request failed." }).waitFor();
    await form
      .getByRole("button", { name: "Find saved programme", exact: true })
      .click();
    await a.locator("#status").filter({ hasText: "Saved." }).waitFor();
    assert.equal(posts, 1);
    assert.equal(await a.locator("#programmes img").count(), 0);
    checks++;
    const recovered = await post(
      admin,
      "commercial/referral-programs/publication-receipt",
      { idempotency_key: publicationKey },
    );
    assert.equal(recovered.status(), 200);
    const program = (await recovered.json()).program;
    assert.equal(program.minimum_minor, "100");
    assert.equal(program.cash_value, false);
    assert.equal(program.fulfillment, "MANUAL_NON_MONETARY");
    checks++;
    const referrer = await login("referrer");
    const codeReply = await post(referrer, "commercial/referrals/codes", {
      program_id: program.id,
      idempotency_key: "own-" + run,
    });
    assert.equal(codeReply.status(), 201);
    const code = (await codeReply.json()).code;
    assert.match(code, /^REF-[A-F0-9]{20}$/);
    checks++;
    const member = await login("member");
    const key = "accept-" + run,
      payload = {
        code,
        terms_version: program.terms_version,
        consent: true,
        idempotency_key: key,
      };
    const accepted = await post(member, "commercial/referrals/accept", payload);
    assert.equal(accepted.status(), 201);
    const referral = (await accepted.json()).referral;
    const hash = (s) => createHash("sha256").update(s).digest("hex");
    const me = await member.context.request.get(base + "/api/me");
    const memberId = (await me.json()).id;
    assert.equal(
      referral.request_binding,
      hash(
        hash(code) +
          ":" +
          program.terms_version +
          ":" +
          hash("referral-accept:" + memberId + ":" + key),
      ),
    );
    checks++;
    const invoice = JSON.parse(
      execFileSync(
        "./scripts/php",
        [
          "tests/Fixtures/referral-admin-browser.php",
          "invoice",
          run,
          "synthetic-only",
        ],
        { encoding: "utf8" },
      ),
    );
    assert(invoice.fixture_only);
    const qualified = await post(member, "commercial/referrals/qualify", {
      invoice_id: invoice.invoice_id,
      idempotency_key: "qualify-" + run,
    });
    assert.equal(qualified.status(), 200);
    assert.equal((await qualified.json()).referral.status, "qualified");
    checks++;
    const again = await post(member, "commercial/referrals/qualify", {
      invoice_id: invoice.invoice_id,
      idempotency_key: "qualify-" + run,
    });
    assert.equal(again.status(), 200);
    checks++;
    for (const [client, role] of [
      [referrer, "referrer"],
      [member, "member"],
    ]) {
      const r = await client.context.request.get(
        base + "/api/commercial/referrals/rewards",
      );
      assert.equal(r.status(), 200);
      const rows = await r.json();
      assert.equal(rows.data.length, 1);
      assert.equal(rows.data[0].role, role);
      assert.equal(rows.data[0].points_cost, 0);
      assert.equal(rows.data[0].cash_value, false);
      assert.equal(rows.data[0].delivery_verified, false);
      assert.equal(rows.data[0].source_currently_eligible, true);
      assert(!Object.hasOwn(rows.data[0], "invoice_id"));
      assert(!Object.hasOwn(rows.data[0], "user_id"));
      checks++;
    }
    const card = a.locator("#programmes article").filter({ hasText: name });
    await card.locator("textarea").fill("Synthetic audited programme closure");
    await card
      .getByRole("button", { name: "Stop future qualifications", exact: true })
      .click();
    await a
      .locator("#programmes")
      .getByText("Not active for new qualifications", { exact: true })
      .first()
      .waitFor();
    checks++;
    const account = await member.context.request.get(
      base + "/api/commercial/referrals/account?program_id=" + program.id,
    );
    assert.equal(account.status(), 200);
    assert.equal((await account.json()).referral.status, "qualified");
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
    fs.mkdirSync("artifacts/operational-browser/referral-programmes", {
      recursive: true,
    });
    await a.screenshot({
      path: "artifacts/operational-browser/referral-programmes/ar-dark-mobile.png",
      fullPage: true,
    });
    checks++;
    const denied = await member.context.request.get(
      base + "/admin/referral-programs",
    );
    assert.equal(denied.status(), 403);
    checks++;
    assert.deepEqual(errors, []);
    console.log(
      `Referral actual-Laravel browser: ${checks} checks passed (Admin lost acknowledgement recovery, exact money parsing, verified code/consent/source qualification, private paired manual entitlements, no money/points, revocation, EN/AR/RTL/mobile).`,
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
