const withSource = require("./helpers/ocpi-unpaid-credit-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
withSource(async (f) => {
  const owner = new BusinessClient({ base: f.base });
  owner.csrf = (await owner.request("csrf")).token;
  await owner.request("login", "POST", {
    email: f.owner,
    password: f.password,
  });
  owner.csrf = (await owner.request("csrf")).token;
  const path = `ocpi/providers/${f.provider_id}/billing-reconciliation`;
  let report = await owner.request(path);
  assert.equal(report.read_only, true);
  assert.equal(report.payment_execution, false);
  assert.equal(report.data.length, 2);
  assert.equal(report.data[0].billing.state, "unpaid");
  const denied = await fetch(f.base + "/api/" + path, {
    headers: {
      Cookie: [...f.driverClient.cookies]
        .map(([k, v]) => `${k}=${v}`)
        .join("; "),
      Accept: "application/json",
    },
  });
  assert.equal(denied.status, 404);
  await owner.request(
    `finance/invoices/${f.invoice.id}/unpaid-credit`,
    "POST",
    {
      idempotency_key: randomUUID(),
      credit_record_id: f.credit.id,
      reason: "Full partner correction for source reconciliation",
      consent: true,
      expected_actor_id: (await owner.request("me")).id,
      expected_invoice_number: f.invoice.number,
      expected_total_minor: f.invoice.total_minor,
      expected_currency: f.invoice.currency,
      expected_minor_units: f.invoice.minor_units,
    },
  );
  report = await owner.request(path + "?kind=credits");
  assert.equal(report.data.length, 1);
  assert.equal(report.data[0].billing.state, "credited");
  assert.equal(report.data[0].billing.paid_minor, 0);
  assert.equal(report.data[0].billing.refunded_minor, 0);
  assert.equal(report.data[0].billing.due_minor, 0);
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
      [...owner.cookies].map(([name, value]) => ({ name, value, url: f.base })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(f.base + "#roaming-operations");
    const ops = page.locator("#roaming-operations");
    await ops
      .getByRole("button", {
        name: "Partner billing reconciliation",
        exact: true,
      })
      .click();
    let panel = ops.locator("[data-partner-billing]");
    await panel.getByText(f.invoice.number, { exact: true }).first().waitFor();
    assert(
      (await panel.textContent()).includes("Credited without cash collection"),
    );
    await panel.locator("select[name=kind]").selectOption("credits");
    await panel
      .getByRole("button", { name: "Apply filters", exact: true })
      .click();
    await panel.getByText(f.credit.external_id, { exact: false }).waitFor();
    assert.equal(await panel.locator("article").count(), 1);
    assert(
      (await panel.textContent()).includes("Refunded in TEST ledger: EUR 0.00"),
    );
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth + 1,
      ),
      false,
    );
    await page.locator("#language").click();
    await ops
      .getByRole("button", { name: "مطابقة فوترة الشريك", exact: true })
      .click();
    panel = ops.locator("[data-partner-billing]");
    await panel
      .getByText("تمت التسوية دون تحصيل نقدي", { exact: true })
      .first()
      .waitFor();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    await page.evaluate(() => {
      Object.defineProperty(document, "hidden", {
        value: true,
        configurable: true,
      });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    assert.equal(await panel.textContent(), "");
    await page.evaluate(() => {
      Object.defineProperty(document, "hidden", {
        value: false,
        configurable: true,
      });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await panel.getByRole("button", { name: "تحديث", exact: true }).click();
    await panel.getByText(f.invoice.number, { exact: true }).first().waitFor();
    await page.locator("#logout").click();
    await page.waitForFunction(
      () => document.querySelector("#roaming-operations").hidden,
    );
    assert.equal(await ops.textContent(), "");
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log(
    "PASS actual synthetic CPO -> canonical unpaid invoice/noncash credit -> scoped vendor billing workbench, no collection/refund, EN/AR phone/logout privacy",
  );
}).catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
