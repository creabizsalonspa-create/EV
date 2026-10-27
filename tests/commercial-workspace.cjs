// Real Laravel HTTP/browser workflows. All accounting identities and payments are synthetic.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  execFileSync("./scripts/php", [
    "tests/Fixtures/invoice-history.php",
    run,
    password,
  ]);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  try {
    async function login(role) {
      const context = await browser.newContext();
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      p.on("response", async (response) => {
        if (
          response.url().includes("/commercial/") &&
          response.request().method() !== "GET" &&
          response.status() >= 400
        )
          console.error(
            response.status(),
            response.url(),
            await response.text(),
          );
      });
      p.setDefaultTimeout(15000);
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`invoices-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      await p.locator("button[value=login]").click();
      await p.locator('a[href="#commercial-finance"]').click();
      await p.locator("#commercial-finance h2").waitFor();
      await p
        .locator("#commercial-finance .finance-panel")
        .nth(2)
        .getByRole("heading", { name: "Plans & subscriptions", exact: true })
        .waitFor();
      return p;
    }
    const admin = await login("admin"),
      rootAdmin = admin.locator("#commercial-finance");
    const planForm = rootAdmin.locator("form").filter({
      has: admin.getByRole("button", {
        name: "Publish subscription plan",
        exact: true,
      }),
    });
    await planForm.locator("[name=code]").fill("test-" + run);
    await planForm.locator("[name=name]").fill("Commercial " + run);
    await planForm.locator("[name=audience]").selectOption("customer");
    await planForm
      .getByRole("button", { name: "Publish subscription plan", exact: true })
      .click();
    await rootAdmin
      .getByRole("heading", { name: "Commercial " + run, exact: true })
      .waitFor();
    const customer = await login("customer"),
      root = customer.locator("#commercial-finance");
    const checkoutButtons = root.getByRole("button", {
      name: "Open test checkout",
      exact: true,
    });
    await checkoutButtons.nth(1).click();
    await root
      .getByRole("button", { name: "Authorize test payment", exact: true })
      .waitFor();
    await root
      .getByRole("button", { name: "Authorize test payment", exact: true })
      .click();
    await root
      .getByRole("button", { name: "Capture test payment", exact: true })
      .waitFor();
    await root
      .getByRole("button", { name: "Capture test payment", exact: true })
      .click();
    await root.getByRole("heading", { name: /#\d+ · captured/ }).waitFor();
    const refund = root
      .locator(".finance-row")
      .filter({ has: customer.getByRole("heading", { name: /^SIM-/ }) })
      .nth(1)
      .locator("form")
      .filter({
        has: customer.getByRole("button", {
          name: "Request refund",
          exact: true,
        }),
      });
    await refund.locator("[name=amount]").fill("1.00");
    await refund
      .getByLabel("Reason", { exact: true })
      .fill("Browser refund " + run);
    await refund
      .getByRole("button", { name: "Request refund", exact: true })
      .click();
    await root.getByText("Browser refund " + run, { exact: true }).waitFor();
    const plan = root.locator(".finance-row").filter({
      has: customer.getByRole("heading", {
        name: "Commercial " + run,
        exact: true,
      }),
    });
    await plan.getByRole("button", { name: "Subscribe", exact: true }).click();
    await root
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    const payResponse = customer.waitForResponse(
      (r) =>
        r.url().includes("/mock-payment") && r.request().method() === "POST",
    );
    await root
      .getByRole("button", { name: "Record test payment", exact: true })
      .click();
    assert.equal((await payResponse).status(), 200);
    await root.getByRole("heading", { name: /#\d+ · active/ }).waitFor();
    await root
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    await root
      .getByText("Commercial " + run + " · Active / فعال", { exact: true })
      .waitFor();
    const vendor = await login("vendor"),
      vr = vendor.locator("#commercial-finance");
    const request = vr.locator(".finance-row").filter({
      has: vendor.getByText("Browser refund " + run, { exact: true }),
    });
    await request.locator("[name=note]").fill("Approved in browser");
    await request
      .getByRole("button", { name: "Review refund", exact: true })
      .click();
    await vr.getByText("Browser refund " + run, { exact: true }).waitFor();
    for (const theme of ["light", "dark"]) {
      await customer.locator("#theme-choice").selectOption(theme);
      for (const width of [390, 768, 1440]) {
        await customer.setViewportSize({ width, height: 900 });
        assert(
          await customer.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
      }
    }
    await customer.locator("#language").click();
    await root
      .getByRole("heading", { name: "المالية والأعمال", exact: true })
      .waitFor();
    assert.equal(await customer.locator("html").getAttribute("dir"), "rtl");
    await customer.locator("#logout").click();
    await customer.waitForFunction(
      () => document.querySelector("#commercial-finance").hidden,
    );
    assert.equal(await root.textContent(), "");
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel financial workspace: checkout authorization/capture, refund request/review, plan subscription/payment/entitlement, both themes three widths RTL and logout privacy. MOCK payments only.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
