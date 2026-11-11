// Actual local Laravel and synthetic CPO: no payment, refund or physical operation.
const withUnpaid = require("./helpers/ocpi-unpaid-credit-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { randomUUID } = require("node:crypto");
const assert = require("node:assert/strict");
withUnpaid(async (f) => {
  const owner = new BusinessClient({ base: f.base });
  owner.csrf = (await owner.request("csrf")).token;
  await owner.request("login", "POST", {
    email: f.owner,
    password: f.password,
  });
  owner.csrf = (await owner.request("csrf")).token;
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
    await context.addCookies(
      [...owner.cookies].map(([name, value]) => ({ name, value, url: f.base })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    let attempts = 0,
      note,
      body;
    await page.route(
      `**/api/finance/invoices/${f.invoice.id}/unpaid-credit`,
      async (route) => {
        if (route.request().method() !== "POST") return route.continue();
        attempts++;
        body = route.request().postDataJSON();
        const response = await route.fetch();
        assert.equal(response.status(), 200);
        note = (await response.json()).credit_note;
        await route.abort("failed");
      },
    );
    await page.goto(f.base + "#invoice-history");
    const root = page.locator("#invoice-history");
    const open = () =>
      root
        .locator("article")
        .filter({
          has: page.getByRole("heading", {
            name: f.invoice.number,
            exact: true,
          }),
        })
        .getByRole("button", { name: "View invoice", exact: true })
        .click();
    await open();
    const detail = root.locator("[data-invoice-detail]");
    await detail
      .getByRole("button", { name: "Load full credit references", exact: true })
      .click();
    await detail
      .getByRole("button", { name: "Select full source credit", exact: true })
      .click();
    await detail
      .locator("textarea")
      .fill("Full partner reversal without collection");
    await detail.locator("input[type=checkbox]").check();
    await detail
      .getByRole("button", {
        name: "Issue full noncash TEST credit",
        exact: true,
      })
      .click();
    await detail
      .getByRole("button", {
        name: "Retry the saved credit request",
        exact: true,
      })
      .waitFor();
    assert.equal(attempts, 1);
    assert.equal(body.credit_record_id, f.credit.id);
    const stored = await page.evaluate(() =>
      Object.keys(sessionStorage)
        .filter((k) => k.startsWith("ev-unpaid-credit:"))
        .map((k) => sessionStorage.getItem(k)),
    );
    assert.equal(stored.length, 1);
    assert.match(stored[0], /^[a-f0-9-]{36}$/);
    await page.reload();
    await open();
    await detail
      .getByRole("button", {
        name: "Resolve result or cancel the unissued request",
        exact: true,
      })
      .click();
    await detail.getByText(note.credit_note_number, { exact: true }).waitFor();
    assert.equal(attempts, 1);
    const summary = await f.driverClient.request(
      `finance/invoices/${f.invoice.id}`,
    );
    assert.equal(summary.state, "credited");
    assert.equal(summary.amount_due_minor, 0);
    assert.equal(summary.paid_minor, 0);
    assert.equal(summary.refunded_minor, 0);
    assert.equal(summary.payments.length, 0);
    assert.equal(summary.refunds.length, 0);
    assert.equal(summary.unpaid_credit_notes.length, 1);
    assert.equal(summary.ledger_balanced, true);
    await assert.rejects(
      () =>
        f.driverClient.request(
          `finance/invoices/${f.invoice.id}/simulate-payment`,
          "POST",
          { amount_minor: 25, idempotency_key: randomUUID() },
        ),
      /409/,
    );
    await page.locator("#language").click();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth + 1,
      ),
      false,
    );
    await page.locator("#logout").click();
    await page.waitForFunction(
      () => document.querySelector("#invoice-history").hidden,
    );
    assert.equal(await root.textContent(), "");
    assert.deepEqual(errors, []);
    await context.close();
    console.log(
      "PASS real local unpaid CPO invoice -> explicit vendor noncash correction -> committed lost-response/reload resolution -> one balanced credit and zero payments/refunds; later collection denied, Arabic phone/logout privacy",
    );
  } finally {
    await browser.close();
  }
}).catch((e) => {
  console.error(
    ["AssertionError", "TimeoutError"].includes(e.name)
      ? e.message
      : "Unpaid source credit proof failed; private fixture details withheld.",
  );
  process.exitCode = 1;
});
