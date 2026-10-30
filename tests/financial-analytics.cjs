// Real Laravel financial source, private downloads and responsive EN/AR report controls.
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
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/payout-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  const dir = "artifacts/financial-analytics";
  fs.mkdirSync(dir + "/screenshots", { recursive: true });
  try {
    async function login(role) {
      const context = await browser.newContext();
      const p = await context.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`payout-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      async function submit() {
        const r = p.waitForResponse(
          (r) =>
            r.url().endsWith("/api/login") && r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        return r;
      }
      let r = await submit();
      if (r.status() === 429) {
        const seconds = Number(r.headers()["retry-after"]);
        assert(Number.isInteger(seconds) && seconds >= 1 && seconds <= 60);
        await p.waitForTimeout((seconds + 1) * 1000);
        r = await submit();
      }
      assert.equal(r.status(), 200);
      await p.locator('a[href="#commercial-finance"]').click();
      await p.locator("[data-financial-analytics]").waitFor();
      return p;
    }
    const vendor = await login("vendor"),
      panel = vendor.locator("[data-financial-analytics]");
    await panel
      .getByRole("button", { name: "Open financial analytics", exact: true })
      .click();
    const collected = panel.locator(
      '[data-analytics-category="charging_collected"]',
    );
    await collected.getByText("AED 10.50", { exact: true }).waitFor();
    assert.equal(
      await panel
        .locator('[data-analytics-category="vendor_allocated"]')
        .count(),
      1,
    );
    assert.equal(await collected.locator("meter").count(), 2);
    const csvDownload = vendor.waitForEvent("download");
    await panel
      .getByRole("link", { name: "Download report CSV", exact: true })
      .click();
    const csv = await csvDownload;
    await csv.saveAs(dir + "/vendor-report.csv");
    const text = fs.readFileSync(dir + "/vendor-report.csv", "utf8");
    assert(text.includes("charging_collected"));
    assert(text.includes("1050"));
    const pdfResponse = await vendor.request.get(
      base + "/api/financial/analytics/pdf?locale=ar",
    );
    assert.equal(pdfResponse.status(), 200);
    assert.match(pdfResponse.headers()["cache-control"], /no-store/);
    const bytes = await pdfResponse.body();
    assert.equal(bytes.subarray(0, 5).toString(), "%PDF-");
    fs.writeFileSync(dir + "/arabic-management-report.pdf", bytes);
    await panel.locator("[name=currency]").fill("USD");
    await panel
      .getByRole("button", { name: "Apply financial filters", exact: true })
      .click();
    await panel
      .getByText("No financial postings match these filters.", { exact: true })
      .waitFor();
    await panel.locator("[name=currency]").fill("AED");
    await panel
      .getByRole("button", { name: "Apply financial filters", exact: true })
      .click();
    await collected.waitFor();
    for (const theme of ["light", "dark"])
      for (const width of [390, 768, 1440]) {
        await vendor.setViewportSize({ width, height: 1000 });
        await vendor.locator("#theme-choice").selectOption(theme);
        assert(
          await vendor.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
        await panel.screenshot({
          path: `${dir}/screenshots/${theme}-${width}.png`,
        });
      }
    await vendor.locator("#language").click();
    await vendor.waitForFunction(() => document.documentElement.dir === "rtl");
    await collected
      .getByText("مقبوضات الشحن المسجلة", { exact: true })
      .waitFor();
    const arabicDownload = vendor.waitForEvent("download");
    await panel
      .getByRole("link", { name: "تنزيل التقرير PDF", exact: true })
      .click();
    const downloadedArabic = await arabicDownload;
    assert.equal(
      new URL(downloadedArabic.url()).searchParams.get("locale"),
      "ar",
    );
    await downloadedArabic.saveAs(dir + "/arabic-ui-management-report.pdf");
    for (const theme of ["light", "dark"]) {
      await vendor.setViewportSize({ width: 390, height: 1000 });
      await vendor.locator("#theme-choice").selectOption(theme);
      await panel.screenshot({
        path: `${dir}/screenshots/ar-${theme}-390.png`,
      });
      assert(
        await vendor.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
    }
    const customer = await login("customer"),
      privatePanel = customer.locator("[data-financial-analytics]");
    await privatePanel
      .getByRole("button", { name: "Open financial analytics", exact: true })
      .click();
    await privatePanel
      .locator('[data-analytics-category="charging_collected"]')
      .waitFor();
    assert.equal(
      await privatePanel
        .locator('[data-analytics-category="commission_allocated"]')
        .count(),
      0,
    );
    assert.equal(
      await privatePanel
        .locator('[data-analytics-category="settlement_instruction"]')
        .count(),
      0,
    );
    const anonymous = await browser.newContext();
    assert.equal(
      (
        await anonymous.request.get(base + "/api/financial/analytics", {
          headers: { Accept: "application/json" },
        })
      ).status(),
      401,
    );
    await anonymous.close();
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel financial analytics: source receipts/refunds/instructions remain separate, vendor/customer privacy, currency filters, real CSV/Arabic management PDF downloads, actual comparative meters, Light/Dark390/768/1440 and Arabic RTL. No real-money, legal-tax or recognized-revenue acceptance.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
