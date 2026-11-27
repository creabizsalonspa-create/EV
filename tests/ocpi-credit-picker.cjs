// Real isolated Laravel + synthetic CPO. Mock funds only; no hardware or merchant acceptance.
const withSource = require("./helpers/ocpi-credit-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const assert = require("node:assert/strict");
let stage = "fixture-bootstrap";
// Safe bounded diagnostics: only fixed synthetic phase labels, no cookies,
// invoice data, credential values, or URLs. Retained even on suite timeout.
function checkpoint(next) {
  stage = next;
  console.log("Synthetic source credit picker phase: " + next);
}
checkpoint(stage);
withSource(async (f) => {
  checkpoint("customer-browser");
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
    page.setDefaultTimeout(45000);
    page.setDefaultNavigationTimeout(45000);
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
    checkpoint("customer-navigation");
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
    checkpoint("customer-first-refund");
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
    checkpoint("customer-same-key-retry");
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
    // The UI erases private views immediately, before the logout request
    // completes. Closing the context at that point can cancel server logout.
    // Wait for the exact same-origin POST acknowledgement, not just hiding.
    checkpoint("logout-session-revocation");
    const logoutUrl = new URL("/api/logout", f.base).href;
    const [logout] = await Promise.all([
      page.waitForResponse(
        (response) =>
          response.url() === logoutUrl &&
          response.request().method() === "POST",
        { timeout: 45000 },
      ),
      page.locator("#logout").click({ timeout: 45000 }),
    ]);
    assert.equal(logout.status(), 204, "Browser logout must be acknowledged");
    checkpoint("logout-acknowledged");
    const logoutHeaders = await logout.request().allHeaders();
    const revokedCookie = logoutHeaders.cookie;
    assert(Boolean(revokedCookie), "Logout must carry the browser session");
    checkpoint("logout-ui-confirmation");
    await page.waitForFunction(
      () =>
        document.querySelector("#commercial-finance").hidden &&
        document.querySelector("#logout-retry").hidden &&
        sessionStorage.getItem("creabiz.logout-pending") !== "1",
      undefined,
      { timeout: 45000 },
    );
    assert.equal(await root.textContent(), "");
    // Independently replay the exact cookie that was sent on logout.
    // Assert only the status; never print cookies or private invoice content.
    checkpoint("revoked-cookie-probe");
    const revokedRead = await fetch(
      new URL(`/api/finance/invoices/${invoice.id}`, f.base),
      {
        headers: { Accept: "application/json", Cookie: revokedCookie },
        redirect: "error",
        signal: AbortSignal.timeout(30000),
      },
    );
    assert.equal(revokedRead.status, 401, "Logged-out browser cookie must be denied");
    checkpoint("customer-context-close");
    await context.close();
    checkpoint("vendor-review");
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
    vendor.setDefaultTimeout(45000);
    vendor.setDefaultNavigationTimeout(45000);
    vendor.on("pageerror", (e) => errors.push(e.message));
    await vendor.goto(f.base + "#commercial-finance");
    const review = vendor
      .locator("#commercial-finance .finance-row")
      .filter({ hasText: "Synthetic partner credit review" });
    await review.locator("select[name=decision]").selectOption("approve");
    await review
      .locator("[name=note]")
      .fill("Reviewed synthetic captured funds and scoped CPO reference");
    checkpoint("vendor-review-submit");
    const reviewResponse = vendor.waitForResponse(
      (response) =>
        new URL(response.url()).pathname ===
          `/api/finance/refund-requests/${created.id}/review` &&
        response.request().method() === "POST",
    );
    await review
      .getByRole("button", { name: "Review refund", exact: true })
      .click();
    assert.equal((await reviewResponse).ok(), true, "Vendor review must succeed");
    await vendor.waitForFunction(
      () => !document.querySelector("#commercial-finance").inert,
    );
    // Read the final canonical receipt through a fresh, normally
    // authenticated customer session, never the revoked cookie.
    checkpoint("fresh-customer-receipt");
    const customer = new BusinessClient({ base: f.base });
    customer.csrf = (await customer.request("csrf")).token;
    await customer.request("login", "POST", {
      email: f.driver,
      password: f.password,
    });
    customer.csrf = (await customer.request("csrf")).token;
    const final = await customer.request(
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
    checkpoint("vendor-context-close");
    await vendorContext.close();
    console.log(
      "PASS real isolated Laravel: customer scoped credit selection -> committed lost-response/same-body retry -> vendor web review -> one canonical mock refund; Arabic phone/logout privacy; no physical or merchant acceptance",
    );
  } finally {
    console.log("Synthetic source credit picker phase: browser-close");
    await browser.close();
    console.log("Synthetic source credit picker phase: fixture-cleanup");
  }
}).catch((e) => {
  console.error(
    ["AssertionError", "TimeoutError"].includes(e.name)
      ? `Source credit picker assertion at ${stage}: ${e.message}`
      : `Isolated source credit picker proof failed at ${stage} (${e.name}); private fixture details withheld.`,
  );
  process.exitCode = 1;
});
