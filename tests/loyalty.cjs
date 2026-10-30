// Actual Laravel/browser evidence. All charging/payment identities and reward economics are synthetic.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const fs = require("node:fs");
const assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/loyalty-browser.php", "create", run, password],
      { encoding: "utf8" },
    ),
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [],
    artifacts = "artifacts/finance-v3/loyalty";
  fs.mkdirSync(artifacts, { recursive: true });
  try {
    async function login(role) {
      const context = await browser.newContext(),
        p = await context.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`loyalty-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      async function attempt() {
        const pending = p.waitForResponse(
          (r) =>
            r.url().endsWith("/api/login") && r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        return pending;
      }
      let response = await attempt();
      if (response.status() === 429) {
        const seconds = Number(response.headers()["retry-after"]);
        assert(Number.isInteger(seconds) && seconds >= 1 && seconds <= 60);
        await p.waitForTimeout((seconds + 1) * 1000);
        response = await attempt();
      }
      assert.equal(response.status(), 200);
      await p.locator('a[href="#commercial-finance"]').click();
      await p
        .locator("[data-loyalty]")
        .getByRole("button", { name: "Open loyalty & rewards", exact: true })
        .waitFor();
      return p;
    }
    async function mutation(p, path, click, status = 201) {
      const pending = p.waitForResponse(
        (r) =>
          new URL(r.url()).pathname.endsWith("/commercial/loyalty/" + path) &&
          r.request().method() === "POST",
      );
      await click();
      const response = await pending;
      assert.equal(response.status(), status, await response.text());
      return response.json();
    }
    const admin = await login("admin"),
      ap = admin.locator("[data-loyalty]");
    await ap
      .getByRole("button", { name: "Open loyalty & rewards", exact: true })
      .click();
    await ap
      .getByRole("button", { name: "Points policies", exact: true })
      .click();
    const policyForm = ap.locator(
      '[data-loyalty-form="Publish points policy"]',
    );
    await policyForm.locator("[name=enabled]").selectOption("true");
    const effective = await admin.evaluate((raw) => {
      const d = new Date(raw);
      return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 19);
    }, fixture.invoice_created_at);
    await policyForm.locator("[name=effective_from]").fill(effective);
    await mutation(admin, "policies", () =>
      policyForm
        .getByRole("button", { name: "Publish points policy", exact: true })
        .click(),
    );
    await ap
      .getByRole("button", { name: "Reward catalog", exact: true })
      .click();
    const rewardForm = ap.locator(
      '[data-loyalty-form="Publish loyalty reward"]',
    );
    await rewardForm.locator("[name=name]").fill("Synthetic reward " + run);
    await rewardForm.locator("[name=name_ar]").fill("مكافأة تجريبية " + run);
    await rewardForm
      .locator("[name=description]")
      .fill(
        "Synthetic non-monetary manual fulfillment <img src=x onerror=alert(1)>",
      );
    await rewardForm
      .locator("[name=description_ar]")
      .fill("مكافأة غير نقدية بتسليم يدوي للاختبار فقط");
    await rewardForm.locator("[name=points_cost]").fill("10");
    await rewardForm.locator("[name=stock]").fill("2");
    await rewardForm.locator("[name=per_user_limit]").fill("2");
    const published = await mutation(admin, "rewards", () =>
      rewardForm
        .getByRole("button", { name: "Publish loyalty reward", exact: true })
        .click(),
    );
    const customer = await login("customer"),
      cp = customer.locator("[data-loyalty]");
    await cp
      .getByRole("button", { name: "Open loyalty & rewards", exact: true })
      .click();
    const earn = cp.locator(
      '[data-loyalty-form="Earn points from paid invoice"]',
    );
    await earn.locator("[name=invoice_id]").fill(String(fixture.invoice_id));
    const earned = await mutation(
      customer,
      `invoices/${fixture.invoice_id}/earn`,
      () =>
        earn
          .getByRole("button", {
            name: "Earn points from paid invoice",
            exact: true,
          })
          .click(),
    );
    assert.equal(earned.award.points, 100);
    assert.equal(earned.account.spendable_points, 100);
    assert.equal(earned.account.cash_value, false);
    const redemptions = [];
    for (let attempt = 0; attempt < 2; attempt++) {
      await cp
        .getByRole("button", { name: "Reward catalog", exact: true })
        .click();
      const reward = cp.locator(".finance-row").filter({
        has: customer.getByRole("heading", {
          name: "Synthetic reward " + run,
          exact: true,
        }),
      });
      assert.equal(await reward.locator("img").count(), 0);
      await reward.locator("[type=checkbox]").check();
      const result = await mutation(
        customer,
        `rewards/${published.reward.id}/redeem`,
        () =>
          reward
            .getByRole("button", {
              name: "Request reward with points",
              exact: true,
            })
            .click(),
      );
      assert.equal(result.redemption.status, "pending");
      redemptions.push(result.redemption.id);
    }
    await ap
      .getByRole("button", { name: "Reward requests", exact: true })
      .click();
    for (const [index, action] of [
      [0, "fulfill"],
      [1, "reject"],
    ]) {
      const row = ap.locator(".finance-row").filter({
        has: admin.getByRole("heading", {
          name: "Reward request #" + redemptions[index],
          exact: true,
        }),
      });
      const decision = row.locator(
        '[data-loyalty-form="Record manual reward decision"]',
      );
      await decision.locator("[name=action]").selectOption(action);
      await decision
        .locator("[name=reason]")
        .fill(
          action === "fulfill"
            ? "Synthetic manual reward delivered in browser regression"
            : "Synthetic rejection releases unfulfilled points",
        );
      const decided = await mutation(
        admin,
        `redemptions/${redemptions[index]}/${action}`,
        () =>
          decision
            .getByRole("button", {
              name: "Record manual reward decision",
              exact: true,
            })
            .click(),
        200,
      );
      assert.equal(
        decided.redemption.status,
        action === "fulfill" ? "fulfilled" : "rejected",
      );
    }
    await cp
      .getByRole("button", { name: "Points & membership", exact: true })
      .click();
    const beforeRefund = await customer.request.get(
      base + "/api/commercial/loyalty/account",
      { headers: { Accept: "application/json" } },
    );
    assert.equal((await beforeRefund.json()).balance_points, 90);
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/loyalty-browser.php", "refund", run],
      { encoding: "utf8" },
    );
    const reconciled = await mutation(
      customer,
      "reconcile",
      () =>
        cp
          .getByRole("button", {
            name: "Reconcile point reversals and expiry",
            exact: true,
          })
          .click(),
      200,
    );
    assert.equal(reconciled.account.balance_points, -10);
    assert.equal(reconciled.account.spendable_points, 0);
    await cp
      .getByText(
        "Refund reversals exceed the remaining points balance. New points must cover this non-monetary deficit before further rewards.",
        { exact: true },
      )
      .waitFor();
    await cp
      .getByRole("button", { name: "Points history", exact: true })
      .click();
    await cp
      .getByText("Refund or chargeback reversal", { exact: true })
      .waitFor();
    const foreign = await login("foreign");
    const hidden = await foreign.request.get(
      base + `/api/commercial/loyalty/redemptions/${redemptions[0]}`,
      { headers: { Accept: "application/json" } },
    );
    assert.equal(hidden.status(), 404);
    const foreignAccount = await foreign.request.get(
      base + "/api/commercial/loyalty/account",
      { headers: { Accept: "application/json" } },
    );
    assert.equal((await foreignAccount.json()).balance_points, 0);
    await cp
      .getByRole("button", { name: "Points & membership", exact: true })
      .click();
    for (const width of [390, 768, 1440]) {
      await customer.setViewportSize({ width, height: 1000 });
      for (const theme of ["light", "dark"]) {
        await customer.locator("#theme-choice").selectOption(theme);
        await cp.evaluate((n) => n.scrollIntoView({ block: "start" }));
        assert(
          await customer.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
        await customer.screenshot({
          path: `${artifacts}/${theme}-${width}.png`,
        });
      }
    }
    await customer.locator("#language").click();
    await cp
      .getByRole("heading", { name: "نقاط العضوية ومكافآتها", exact: true })
      .waitFor();
    assert.equal(await customer.locator("html").getAttribute("dir"), "rtl");
    await customer.setViewportSize({ width: 390, height: 1000 });
    for (const theme of ["light", "dark"]) {
      await customer.locator("#theme-choice").selectOption(theme);
      await cp.evaluate((n) => n.scrollIntoView({ block: "start" }));
      assert(
        await customer.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
      await customer.screenshot({ path: `${artifacts}/${theme}-ar-390.png` });
    }
    await customer.locator("#logout").click();
    await customer.waitForFunction(
      () => document.querySelector("#commercial-finance").hidden,
    );
    assert.equal(
      await customer.locator("#commercial-finance").textContent(),
      "",
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel loyalty browser: admin policy/reward publishing, qualified immutable paid invoice earns 100 points, two redemptions, manual fulfill/reject, canonical refund creates -10 non-monetary debt after reconcile, private foreign scope, literal text, Light/Dark three widths, Arabic RTL both themes, logout cleanup. Synthetic economics only; no partner reward or cash-value verification.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
