// Actual Laravel read-only finance history. Synthetic immutable accounting remains as evidence.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const invoice = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/invoice-history.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  try {
    async function login(role) {
      const context = await browser.newContext();
      context.setDefaultTimeout(15000);
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`invoices-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      for (let attempt = 0; attempt < 2; attempt++) {
        const ready = p.waitForResponse(
          (r) =>
            new URL(r.url()).pathname === "/api/login" &&
            r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        const response = await ready;
        if (response.status() === 429 && attempt === 0) {
          const header = response.headers()["retry-after"];
          assert(/^\d+$/.test(header || ""));
          const seconds = Number(header);
          assert(seconds <= 60);
          await p.waitForTimeout((seconds + 1) * 1000);
          continue;
        }
        assert.equal(response.status(), 200);
        break;
      }
      await p.locator("#invoice-history [data-invoice-filters]").waitFor();
      return p;
    }
    const customer = await login("customer"),
      root = customer.locator("#invoice-history");
    await root.getByText("Page 1 of 2 · 26 records", { exact: true }).waitFor();
    await root.getByRole("button", { name: "Next", exact: true }).click();
    await root.getByText("Page 2 of 2 · 26 records", { exact: true }).waitFor();
    await root.locator("[name=q]").fill(invoice.number);
    await root.getByRole("button", { name: "Search", exact: true }).click();
    await root.getByText("Page 1 of 1 · 1 records", { exact: true }).waitFor();
    await root
      .getByRole("button", { name: "View invoice", exact: true })
      .click();
    const detail = root.locator("[data-invoice-detail]");
    await detail
      .getByRole("heading", { name: invoice.number, exact: true })
      .waitFor();
    await detail
      .getByText("Literal <img src=x onerror=alert(1)>", { exact: true })
      .waitFor();
    assert.equal(await detail.locator("img").count(), 0);
    assert.equal(
      await detail.getByRole("button", { name: /pay|refund|capture/i }).count(),
      0,
    );
    assert.equal(
      await detail.getByText(/^Simulated commission allocation:/).count(),
      0,
    );
    await customer.locator("#language").click();
    await customer.setViewportSize({ width: 390, height: 844 });
    await root
      .getByRole("heading", { name: "سجل الفواتير", exact: true })
      .waitFor();
    assert(
      await customer.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    );
    await customer.locator("#logout").click();
    await customer.waitForFunction(
      () => document.querySelector("#invoice-history").hidden,
    );
    assert.equal(await root.textContent(), "");
    const vendor = await login("vendor"),
      vroot = vendor.locator("#invoice-history");
    await vroot.locator("[name=q]").fill(invoice.number);
    await vroot.getByRole("button", { name: "Search", exact: true }).click();
    await vroot.getByText("Page 1 of 1 · 1 records", { exact: true }).waitFor();
    await vroot
      .getByRole("button", { name: "View invoice", exact: true })
      .click();
    await vroot.getByText(/^Simulated commission allocation:/).waitFor();
    const other = await login("other");
    await other
      .locator("#invoice-history")
      .getByText("No invoices match these filters.", { exact: true })
      .waitFor();
    const response = await other.request.get(
      base + "/api/finance/invoices/" + invoice.id,
    );
    assert.equal(response.status(), 404);
    assert.deepEqual(errors, []);
    console.log(
      "PASS: actual Laravel invoice history pagination/filters, customer/vendor scope and allocations, read-only receipts/refunds, private detail isolation, Arabic phone layout and logout cleanup.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
