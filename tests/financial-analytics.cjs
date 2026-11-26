// Real Laravel financial source, private downloads and responsive EN/AR report controls.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const fs = require("node:fs");
const assert = require("node:assert/strict");
function verifyRevenueCohortParser() {
  const source = fs.readFileSync("services/business-api/public/commercial.js", "utf8");
  const start = source.indexOf("    function validateRevenueCohort(");
  const end = source.indexOf("    function vendorRevenue(", start);
  assert(start >= 0 && end > start);
  const validate = new Function(source.slice(start, end) + "\nreturn validateRevenueCohort;")();
  const fields = {
    invoice_count: 1, invoiced_minor: 1050, vendor_allocated_minor: 900,
    commission_allocated_minor: 150, paid_minor: 1050, refunded_minor: 100,
    vendor_refunded_minor: 80, unpaid_credited_minor: 20, vendor_credited_minor: 15,
    net_collected_minor: 950, net_vendor_allocated_minor: 805,
  };
  const fixture = () => ({
    vendor_id: 42, currency: "AED", minor_units: 2, from: "2026-11-01", to: "2026-11-01",
    as_of: "2026-11-26T01:02:03+00:00", evidence: "SIMULATED_CANONICAL_FINANCE",
    basis: "INVOICE_ISSUANCE_DATE_UTC_COHORT",
    payment_and_refund_totals: "LIFETIME_OF_SELECTED_INVOICE_COHORT_AS_OF_QUERY",
    external_psp_or_bank_verified: false, real_money_movement: false,
    not_cashflow_or_payout_eligibility: true,
    data: [{date_utc: "2026-11-01", ...fields}], totals: {...fields},
  });
  const run = report => validate(report, 42, "AED", "2026-11-01", "2026-11-01");
  assert.equal(run(fixture()).totals.net_vendor_allocated_minor, 805);
  const legacy = fixture(); delete legacy.as_of;
  assert.equal(run(legacy).totals.invoice_count, 1);
  const corruptions = [
    r => r.vendor_id = "42", r => r.currency = "USD", r => r.from = "2026-11-02",
    r => r.minor_units = "2", r => r.minor_units = 4, r => r.evidence = "LIVE",
    r => r.real_money_movement = true, r => r.external_psp_or_bank_verified = true,
    r => r.not_cashflow_or_payout_eligibility = false, r => r.payment_and_refund_totals = "OTHER",
    r => r.as_of = "2026-02-30T01:02:03Z", r => r.as_of = "2026-11-26T01:02:03+04:00",
    r => r.data = [], r => r.data[0].date_utc = "2026-11-02",
    r => r.data[0].paid_minor = "1050", r => r.data[0].refunded_minor = -1,
    r => r.data[0].commission_allocated_minor = 1.5,
    r => r.data[0].net_collected_minor = 951, r => r.data[0].net_vendor_allocated_minor = 806,
    r => r.totals.invoice_count = 2, r => r.totals.paid_minor = 1049,
    r => r.totals.net_vendor_allocated_minor = 806,
    r => r.data[0].invoice_count = Number.MAX_SAFE_INTEGER + 1,
  ];
  for (const change of corruptions) {
    const report = fixture(); change(report);
    assert.throws(() => run(report), /Revenue cohort source cannot be verified/);
  }
  const overflow = fixture();
  overflow.to = "2026-11-02";
  overflow.data[0].invoiced_minor = Number.MAX_SAFE_INTEGER;
  overflow.data.push({...overflow.data[0], date_utc: "2026-11-02", invoiced_minor: 1});
  assert.throws(() => validate(overflow, 42, "AED", overflow.from, overflow.to),
    /Revenue cohort source cannot be verified/);
  console.log("PASS exact revenue cohort parser: 2 valid and 24 rejected source cases");
}

(async () => {
  verifyRevenueCohortParser();
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
      assert.equal(
        await p.evaluate(() => typeof document.querySelector("#account")?.onsubmit),
        "function",
        `Sign-in handler missing after load; script errors: ${errors.slice(-5).join(" | ")}`,
      );
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
        try {
          return await r;
        } catch (cause) {
          const state = await p.evaluate(() => ({
            formValid: document.querySelector("#account")?.checkValidity(),
            handlerInstalled: typeof document.querySelector("#account")?.onsubmit === "function",
            buttonDisabled: document.querySelector('button[value="login"]')?.disabled,
            path: location.pathname,
          })).catch(() => ({pageClosed: true}));
          throw new Error(`Login POST response absent; state=${JSON.stringify(state)}; pageErrors=${errors.slice(-5).join(" | ")}`, {cause});
        }
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
    // Accessible names must track the selected language, not read two
    // languages simultaneously or expose raw unlocalized period identifiers.
    assert.equal(
      await panel.locator('nav[data-i18n-aria="Private report downloads"]')
        .getAttribute("aria-label"),
      "Private report downloads",
    );
    assert.equal(
      await panel.locator('select[data-i18n-aria="Financial activity"]')
        .getAttribute("aria-label"),
      "Financial activity",
    );
    const currentMeter = collected.locator('meter[data-i18n-aria="Current period"]').first();
    assert.equal(await currentMeter.getAttribute("aria-label"), "Current period");
    assert.match(await currentMeter.getAttribute("aria-valuetext"), /^[A-Z]{3} /);
    const originalMeterValue = await currentMeter.getAttribute("value");
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
    // End-to-end vendor workspace: canonical itemized earnings, scoped CSV
    // and source-only integrity evidence via authenticated Laravel endpoints.
    const earningsForm = vendor.locator("form").filter({
      has: vendor.getByRole("button", { name: "View earnings statement", exact: true }),
    });
    await earningsForm.locator("[name=vendor]").fill(String(f.vendor));
    await earningsForm.locator("[name=currency]").fill("AED");
    await earningsForm.getByRole("button", { name: "View earnings statement", exact: true }).click();
    await vendor.getByRole("button", { name: "View itemized earnings", exact: true }).click();
    const earningsPane = vendor.locator("[data-vendor-earnings]");
    await earningsPane.locator('[data-earnings-invoice-id="' + f.invoice + '"]').waitFor();
    assert.match(await earningsPane.innerText(), /Vendor allocation/);
    const earningsDownload = vendor.waitForEvent("download");
    await earningsPane.getByRole("link", { name: "Download earnings CSV", exact: true }).click();
    await (await earningsDownload).saveAs(dir + "/vendor-earnings.csv");
    const earnedCsv = fs.readFileSync(dir + "/vendor-earnings.csv", "utf8");
    assert(earnedCsv.includes("vendor_allocated_minor"));
    assert(earnedCsv.includes("pending_reserved_minor"));
    await vendor.getByRole("button", {
      name: "Inspect accessible ledger integrity", exact: true,
    }).click();
    const integrityPane = vendor.locator("[data-financial-integrity]");
    await integrityPane.locator('[data-audit-invoice-id="' + f.invoice + '"]').waitFor();
    const auditResponse = await vendor.request.get(base + "/api/finance/reconciliation-audit");
    assert.equal(auditResponse.status(), 200);
    assert.equal((await auditResponse.json()).external_psp_or_bank_verified, false);
    const stranger = await vendor.request.get(
      base + "/api/commercial/vendors/" + (Number(f.vendor) + 1000000) + "/earnings?currency=AED",
    );
    assert.equal(stranger.status(), 404);
    for (const theme of ["light", "dark"])
      for (const width of [390, 768, 1440]) {
        await vendor.setViewportSize({ width, height: 1000 });
        await vendor.locator("#theme-choice").selectOption(theme);
        assert(
          await vendor.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
        await panel.evaluate(el => window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 110)));
        await vendor.screenshot({
          path: `${dir}/screenshots/${theme}-${width}.png`,
        });
      }
    await vendor.locator("#language").click();
    await vendor.waitForFunction(() => document.documentElement.dir === "rtl");
    await collected
      .getByText("مقبوضات الشحن المسجلة", { exact: true })
      .waitFor();
    assert.equal(
      await panel.locator('nav[data-i18n-aria="Private report downloads"]')
        .getAttribute("aria-label"),
      "تنزيلات التقرير الخاصة",
    );
    assert.equal(
      await panel.locator('select[data-i18n-aria="Financial activity"]')
        .getAttribute("aria-label"),
      "النشاط المالي",
    );
    assert.equal(await currentMeter.getAttribute("aria-label"), "الفترة الحالية");
    assert.equal(await currentMeter.getAttribute("value"), originalMeterValue);
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
    assert.equal(await panel.locator("bdi[data-analytics-period][dir=ltr]").count(), 2);
    for (const theme of ["light", "dark"]) {
      await vendor.setViewportSize({ width: 390, height: 1000 });
      await vendor.locator("#theme-choice").selectOption(theme);
      await panel.evaluate(el => window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 110)));
      await vendor.screenshot({
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
    // Admin financial triage is a read-only source union, not a second
    // approval authority. Native Admin tokens remain denied by API middleware.
    assert.equal(
      await customer.getByRole("button", {
        name: "Load pending finance reviews", exact: true,
      }).count(), 0,
      "Customer must not be offered privileged finance approval controls",
    );
    const maker = await login("maker");
    const queueResponse = maker.waitForResponse((response) => {
      const url = new URL(response.url());
      return url.pathname === "/api/finance/approval-queue" &&
        response.request().method() === "GET";
    });
    await maker.getByRole("button", {
      name: "Load pending finance reviews", exact: true,
    }).click();
    const queueHttp = await queueResponse;
    assert.equal(queueHttp.status(), 200);
    const queueBody = await queueHttp.json();
    assert.equal(queueBody.admin_only, true);
    assert.equal(queueBody.read_only, true);
    assert.equal(queueBody.real_money_moved, false);
    assert.equal(queueBody.mutations_performed, false);
    assert.equal(queueBody.not_a_payment_or_payout_authority, true);
    await maker.locator("[data-finance-approval-queue]")
      .getByRole("heading", { name: "Pending financial reviews" }).waitFor();
    assert.equal(
      await maker.locator("[data-finance-approval-queue]")
        .getByRole("button", { name: /Approve|Pay|Refund|Send/ }).count(),
      0,
      "The triage screen must not silently create approval/payment actions",
    );
    const approvalCsv = maker.locator("[data-finance-approval-queue]")
      .getByRole("link", { name: "Download current approval page CSV", exact: true });
    assert.match(await approvalCsv.getAttribute("href"),
      /^\/api\/finance\/approval-queue\/export\?kind=all&per_page=20&page=1$/);
    const approvalDownload = maker.waitForEvent("download");
    await approvalCsv.click();
    const exportFile = await approvalDownload;
    await exportFile.saveAs(dir + "/admin-approval-queue.csv");
    const exportText = fs.readFileSync(dir + "/admin-approval-queue.csv", "utf8");
    assert.match(exportText, /^kind,record_id,vendor_id,invoice_id,currency/);
    assert.doesNotMatch(exportText, /provider_reference|request_key|secret/i);
    const wrongRole = await vendor.request.get(
      base + "/api/finance/approval-queue?kind=refund",
      { headers: { Accept: "application/json" } },
    );
    assert.equal(wrongRole.status(), 403);
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
