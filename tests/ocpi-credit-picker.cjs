// Real isolated Laravel + synthetic CPO. Mock funds only; no hardware or merchant acceptance.
const withSource = require("./helpers/ocpi-credit-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const assert = require("node:assert/strict");
let stage = "fixture-bootstrap";
withSource(async (f) => {
  stage = "customer-browser";
  const { invoice, credit, sourceCreditsPath: optionsPath } = f;
  const external = credit.external_id;
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
      [...f.driverClient.cookies].map(([name, value]) => ({
        name,
        value,
        url: f.base,
      })),
    );
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    let firstBody, secondBody, created;
    let attempts = 0;
    await page.route(
      `**/api/finance/invoices/${invoice.id}/refund-requests`,
      async (route) => {
        if (route.request().method() !== "POST") return route.continue();
        attempts++;
        if (attempts === 1) {
          firstBody = route.request().postDataJSON();
          const reply = await route.fetch();
          assert.equal(reply.status(), 201);
          created = await reply.json();
          await route.abort("failed");
        } else {
          secondBody = route.request().postDataJSON();
          await route.continue();
        }
      },
    );
    await page.goto(f.base + "#commercial-finance");
    const root = page.locator("#commercial-finance");
    const row = root.locator(".finance-row").filter({
      has: page.getByRole("heading", { name: invoice.number, exact: true }),
    });
    await row
      .getByRole("button", {
        name: "Choose optional partner credit",
        exact: true,
      })
      .click();
    await row.getByText(external, { exact: true }).waitFor();
    await row
      .getByRole("button", { name: "Select credit reference", exact: true })
      .click();
    const form = row.locator("form").filter({
      has: page.getByRole("button", { name: "Request refund", exact: true }),
    });
    assert.equal(
      await form.locator("[name=ocpi_credit_record_id]").inputValue(),
      String(credit.id),
    );
    assert.equal(
      await form.locator("[name=ocpi_credit_record_id]").isEditable(),
      false,
    );
    await form.locator("[name=amount]").fill("0.25");
    await form.locator("[name=reason]").fill("Synthetic partner credit review");
    await form.locator("[type=submit]").click();
    await page.waitForFunction(
      () =>
        [...document.querySelectorAll("form")].some(
          (f) =>
            f.dataset.refundFrozen === "true" &&
            f.querySelector("[name=amount]")?.disabled,
        ) && !document.querySelector("#commercial-finance").inert,
    );
    assert.equal(await form.locator("[name=reason]").isDisabled(), true);
    await row
      .getByRole("button", {
        name: "Continue without source credit",
        exact: true,
      })
      .click();
    assert.equal(
      await form.locator("[name=ocpi_credit_record_id]").inputValue(),
      String(credit.id),
    );
    await form.locator("[type=submit]").click();
    await page.waitForFunction(
      () => !document.querySelector("#commercial-finance").inert,
    );
    assert.deepEqual(firstBody, secondBody);
    assert.equal(firstBody.ocpi_credit_record_id, credit.id);
    const linked = await f.driverClient.request(optionsPath);
    assert.equal(linked.data[0].available_for_review, false);
    assert.equal(linked.data[0].existing_refund_request_id, created.id);
    await page.locator("#language").click();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth + 1,
      ),
      false,
    );
    await page.locator("#logout").click();
    await page.waitForFunction(
      () => document.querySelector("#commercial-finance").hidden,
    );
    assert.equal(await root.textContent(), "");
    await context.close();
    const owner = new BusinessClient({ base: f.base });
    owner.csrf = (await owner.request("csrf")).token;
    await owner.request("login", "POST", {
      email: f.owner,
      password: f.password,
    });
    owner.csrf = (await owner.request("csrf")).token;
    const vendorContext = await browser.newContext();
    await vendorContext.addCookies(
      [...owner.cookies].map(([name, value]) => ({ name, value, url: f.base })),
    );
    const vendor = await vendorContext.newPage();
    vendor.on("pageerror", (e) => errors.push(e.message));
    await vendor.goto(f.base + "#commercial-finance");
    const review = vendor
      .locator("#commercial-finance .finance-row")
      .filter({ hasText: "Synthetic partner credit review" });
    await review.locator("select[name=decision]").selectOption("approve");
    await review
      .locator("[name=note]")
      .fill("Reviewed synthetic captured funds and scoped CPO reference");
    await review
      .getByRole("button", { name: "Review refund", exact: true })
      .click();
    await vendor.waitForFunction(
      () => !document.querySelector("#commercial-finance").inert,
    );
    const final = await f.driverClient.request(
      `finance/invoices/${invoice.id}`,
    );
    assert.equal(final.refunds.length, 1);
    assert.equal(final.refunded_minor, 25);
    assert.equal(final.ledger_balanced, true);
    assert.equal(final.real_payment_processed, false);
    const requests = await owner.request("finance/refund-requests");
    assert.equal(
      requests.data.filter((r) => r.invoice_id === invoice.id).length,
      1,
    );
    assert.deepEqual(errors, []);
    await vendorContext.close();
    console.log(
      "PASS real isolated Laravel: customer scoped credit selection -> committed lost-response/same-body retry -> vendor web review -> one canonical mock refund; Arabic phone/logout privacy; no physical or merchant acceptance",
    );
  } finally {
    await browser.close();
  }
}).catch((e) => {
  console.error(
    ["AssertionError", "TimeoutError"].includes(e.name)
      ? e.message
      : `Isolated source credit picker proof failed at ${stage} (${e.name}); private fixture details withheld.`,
  );
  process.exitCode = 1;
});
