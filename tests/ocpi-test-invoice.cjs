const withSource = require("./helpers/ocpi-test-invoice-local.cjs");
const assert = require("node:assert/strict");
withSource(async (f) => {
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
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    page.on("dialog", (d) => d.accept());
    let original = null,
      second = null,
      attempts = 0;
    await page.route(
      `**/api/roaming/sandbox-orders/${f.order_id}/test-invoice`,
      async (route) => {
        attempts++;
        if (attempts === 1) {
          original = route.request().postDataJSON();
          await route.abort("failed");
        } else if (attempts === 2) {
          second = route.request().postDataJSON();
          const reply = await route.fetch();
          assert.equal(reply.status(), 200);
          await route.abort("failed");
        } else await route.continue();
      },
    );
    await page.goto(f.base);
    const discover = page.locator("#roaming-discovery");
    const history = () =>
      discover
        .getByRole("button", { name: "Review sandbox history", exact: true })
        .first()
        .click();
    await history();
    let card = discover.locator(`[data-sandbox-order="${f.order_id}"]`);
    await card
      .getByRole("button", {
        name: "Review simulated roaming invoice",
        exact: true,
      })
      .click();
    let form = card.locator("[data-ocpi-invoice-consent]");
    await form.waitFor();
    await page.locator("#language").click();
    await form
      .getByRole("button", { name: "إنشاء فاتورة تجريبية", exact: true })
      .waitFor();
    assert.equal(await form.locator("input[type=checkbox]").count(), 1);
    await page.locator("#language").click();
    await form.getByRole("checkbox").check();
    await form
      .getByRole("button", { name: "Create simulated invoice", exact: true })
      .click();
    const resolve = () =>
      card.getByRole("button", {
        name: "Resolve saved invoice request",
        exact: true,
      });
    await resolve().waitFor();
    assert.equal(f.inspect(f.order_id).financial_invoices, 0);
    await resolve().click();
    await card
      .getByText(
        "Unissued saved request cancelled. No invoice or payment was changed.",
        { exact: true },
      )
      .waitFor();
    await assert.rejects(
      f.driverClient.request(
        `roaming/sandbox-orders/${f.order_id}/test-invoice`,
        "POST",
        original,
      ),
      /failed \(409\)/,
    );
    await card
      .getByRole("button", { name: "Refresh invoice preview", exact: true })
      .click();
    form = card.locator("[data-ocpi-invoice-consent]");
    await form.waitFor();
    await form.getByRole("checkbox").check();
    await form
      .getByRole("button", { name: "Create simulated invoice", exact: true })
      .click();
    await resolve().waitFor();
    assert.notEqual(original.request_key, second.request_key);
    const counters = f.inspect(f.order_id);
    assert.equal(counters.financial_invoices, 1);
    assert.equal(counters.roaming_charging_sessions, 1);
    assert.equal(counters.charging_sessions, 0);
    const identities = await page.evaluate(() =>
      Object.entries(sessionStorage).filter(([k]) =>
        k.startsWith("ev.ocpi.test-invoice.identity.v1."),
      ),
    );
    assert.equal(identities.length, 1);
    assert.match(identities[0][1], /^[a-f0-9-]{36}$/);
    assert.equal(identities[0][1], second.request_key);
    await page.reload();
    await history();
    card = discover.locator(`[data-sandbox-order="${f.order_id}"]`);
    await card
      .getByRole("button", {
        name: "Review simulated roaming invoice",
        exact: true,
      })
      .click();
    await resolve().waitFor();
    assert.equal(
      await card
        .getByRole("button", {
          name: "Retry same simulated invoice request",
          exact: true,
        })
        .count(),
      0,
    );
    await resolve().click();
    const open = card.getByRole("button", {
      name: "View simulated invoice",
      exact: true,
    });
    await open.waitFor();
    await open.click();
    const invoice = page.locator("#invoice-history");
    await invoice
      .getByRole("heading", { name: "Invoice details", exact: true })
      .waitFor();
    await invoice
      .getByRole("button", { name: "Charging source evidence", exact: true })
      .click();
    await invoice
      .getByText("Source receipts match this simulated invoice", {
        exact: true,
      })
      .waitFor();
    await invoice.getByText("OCPI 2.2.1", { exact: true }).waitFor();
    const proof = await f.driverClient.request(
      `finance/invoices/${(await f.driverClient.request(`roaming/sandbox-orders/${f.order_id}`)).test_invoice_id}/source-evidence`,
    );
    assert.equal(proof.physical_charging_verified, false);
    assert.equal(proof.source_verified, true);
    assert.equal(proof.kind, "OCPI_SANDBOX");
    const order = await f.driverClient.request(
      `roaming/sandbox-orders/${f.order_id}`,
    );
    assert.equal(order.local_invoice_created, true);
    assert.equal(order.test_invoice_evidence, "SIMULATED_CANONICAL_LEDGER");
    const summary = await f.driverClient.request(
      `finance/invoices/${order.test_invoice_id}`,
    );
    assert.equal(summary.invoice.total_minor, 25);
    assert.equal(summary.invoice.currency, "EUR");
    assert.equal(summary.ledger_balanced, true);
    assert.equal(summary.real_payment_processed, false);
    assert.equal(summary.payments.length, 0);
    const amended = {
      ...f.sourceSession,
      last_updated: new Date(Date.now() + 1000).toISOString(),
      kwh: "2.0",
    };
    await f.peer("sessions/NL/ABC/" + amended.id, "PUT", amended, 409);
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
    assert.equal(await invoice.textContent(), "");
    assert.deepEqual(errors, []);
    console.log(
      "PASS authenticated CPO source -> separate exact TEST invoice consent -> cancelled unissued identity/late-post rejection -> committed unknown response/reload resolution -> single canonical balanced invoice without local reservations, payment or physical acceptance; web Arabic/privacy pass.",
    );
  } finally {
    await browser.close();
  }
}).catch((e) => {
  console.error(
    e.name === "AssertionError" || e.name === "TimeoutError"
      ? e.message
      : "OCPI test invoice proof failed; private fixture details withheld.",
  );
  process.exitCode = 1;
});
