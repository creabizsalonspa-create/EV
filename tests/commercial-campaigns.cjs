// Actual Laravel/browser workflows; prepaid mock package and synthetic signed publisher only.
const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  fs = require("node:fs"),
  assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/campaign-browser.php", "create", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(f.fixture_only);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  const dir = "artifacts/finance-campaigns/screenshots";
  fs.mkdirSync(dir, { recursive: true });
  try {
    async function login(role) {
      const c = await browser.newContext(),
        p = await c.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));

      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`campaign-${role}-${run}@example.test`);
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
      const panel = p.locator("[data-commercial-campaigns]");
      await panel
        .getByRole("button", { name: "Open commercial campaigns", exact: true })
        .click();
      return { p, panel };
    }
    const { p: vendor, panel: v } = await login("vendor");
    await v
      .getByRole("button", { name: "Create sponsored campaign", exact: true })
      .click();
    await v.locator("[name=invoice_id]").selectOption(String(f.invoice));
    await v.locator("[name=station_id]").selectOption(String(f.station));
    await v
      .locator("[name=title]")
      .fill("Browser sponsored " + run + " <img src=x>");
    await v.locator("[name=title_ar]").fill("حملة برعاية " + run);
    await v
      .locator("[name=description]")
      .fill("Actual paid package test, not a verified human impression");
    await v
      .locator("[name=description_ar]")
      .fill("اختبار حزمة مدفوعة دون إثبات مشاهدة بشرية");
    await v.locator("[name=budget_units]").fill("3");
    const created = vendor.waitForResponse(
      (r) =>
        r.url().endsWith("/commercial/campaigns") &&
        r.request().method() === "POST",
    );
    await v
      .getByRole("button", { name: "Save campaign draft", exact: true })
      .click();
    const body = await (await created).json();
    assert(body.campaign);
    const id = body.campaign.id;
    assert.equal(body.campaign.additional_cash_charge, false);
    assert.equal(await v.locator("img").count(), 0);
    async function decision(panel, p, button, reason) {
      const form = panel
        .locator("form")
        .filter({ has: p.getByRole("button", { name: button, exact: true }) });
      await form.locator("[name=reason]").fill(reason);
      await form.getByRole("button", { name: button, exact: true }).click();
    }
    await decision(
      v,
      vendor,
      "Submit for review",
      "Reviewed synthetic vendor request",
    );
    await v.getByText("Awaiting review", { exact: true }).waitFor();
    const { p: admin, panel: a } = await login("admin");
    await a
      .locator(`[data-campaign-record="${id}"]`)
      .getByRole("button", { name: "Review campaign", exact: true })
      .click();
    await decision(
      a,
      admin,
      "Approve sponsored campaign",
      "Approved synthetic sponsored creative",
    );
    await a.getByText("Approved", { exact: true }).waitFor();
    const signed = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/campaign-browser.php", "delivery", run, String(id)],
        { encoding: "utf8" },
      ),
    );
    const delivered = await fetch(
      base + "/api/internal/commercial/campaign-deliveries",
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Campaign-Timestamp": signed.timestamp,
          "X-Campaign-Signature": signed.signature,
        },
        body: signed.raw,
      },
    );
    assert.equal(delivered.status, 200);
    assert.equal((await delivered.json()).duplicate, false);
    const forged = await vendor.request.post(
      base + "/api/internal/commercial/campaign-deliveries",
      { data: JSON.parse(signed.raw) },
    );
    assert.equal(forged.status(), 401);
    await v.getByRole("button", { name: "Campaign list", exact: true }).click();
    await v
      .locator(`[data-campaign-record="${id}"]`)
      .getByText(/1\/3/)
      .waitFor();
    await v
      .locator(`[data-campaign-record="${id}"]`)
      .getByRole("button", { name: "Review campaign", exact: true })
      .click();
    await v
      .getByText("Eligible for a sponsored placement", { exact: true })
      .waitFor();
    const feed = await vendor.request.get(
      base +
        "/api/commercial/campaign-placements?country=AE&placement=discovery_banner",
    );
    const placed = (await feed.json()).placements.find(
      (r) => r.campaign_id === id,
    );
    assert(placed);
    assert.equal(placed.sponsored, true);
    assert.equal(placed.availability_verified, false);
    assert.equal(placed.capability_override, false);
    await decision(
      a,
      admin,
      "Suspend sponsored campaign",
      "Moderator withdraws sponsored placement",
    );
    await a.locator('[data-campaign-state="suspended"]').waitFor();
    await v.getByRole("button", { name: "Campaign list", exact: true }).click();
    await v
      .locator(`[data-campaign-record="${id}"]`)
      .getByRole("button", { name: "Review campaign", exact: true })
      .click();
    assert.equal(
      await v
        .getByRole("button", { name: "Resume campaign", exact: true })
        .count(),
      0,
    );
    for (const theme of ["light", "dark"])
      for (const width of [390, 768, 1440]) {
        await vendor.setViewportSize({ width, height: 1000 });
        await vendor.locator("#theme-choice").selectOption(theme);
        await v.screenshot({ path: `${dir}/${theme}-${width}.png` });
        assert(
          await vendor.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
      }
    await vendor.locator("#language").click();
    await vendor.waitForFunction(() => document.documentElement.dir === "rtl");
    for (const theme of ["light", "dark"]) {
      await vendor.locator("#theme-choice").selectOption(theme);
      await vendor.setViewportSize({ width: 390, height: 1000 });
      await v.screenshot({ path: `${dir}/ar-${theme}-390.png` });
      assert(
        await vendor.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
    }
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel campaign UI: paid-package and owned-station choices, immutable draft, vendor submission/admin approval, signed server delivery and forged-client denial, labeled sponsored feed without capability claims, moderation suspension, EN/AR RTL Light/Dark three widths. Synthetic package and publisher evidence only; no human impression or production ad delivery verification.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
