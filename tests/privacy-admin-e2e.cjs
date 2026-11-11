// Actual local web Admin review against the canonical case service.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { withPrivacyFixture } = require("./helpers/privacy-local.cjs");
withPrivacyFixture(async (fixture) => {
  const customer = new BusinessClient({ base: fixture.base });
  customer.csrf = (await customer.request("csrf")).token;
  await customer.request("login", "POST", fixture.accounts.customer);
  customer.csrf = (await customer.request("csrf")).token;
  const row = await customer.request("account/privacy-requests", "POST", {
    kind: "correction",
    details: "Please review a correction to my retained contact information.",
    idempotency_key: "privacy-admin-" + fixture.run,
  });
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const page = await browser.newPage({
      viewport: { width: 390, height: 844 },
    });
    await page.goto(fixture.base);
    await page
      .locator("#account [name=email]")
      .fill(fixture.accounts.admin.email);
    await page
      .locator("#account [name=password]")
      .fill(fixture.accounts.admin.password);
    await page.locator("button[value=login]").click();
    const root = page.locator("#admin-privacy");
    const decision = () =>
      root.getByRole("form", { name: "Privacy review decision " + row.id });
    await decision().waitFor();
    await decision().locator("select").selectOption("reviewing");
    await decision()
      .locator("textarea")
      .fill("Independent review of the account correction request.");
    let reply = page.waitForResponse(
      (r) =>
        r.url().endsWith("/" + row.id + "/review") &&
        r.request().method() === "PATCH",
    );
    await decision().locator("button").click();
    assert.equal((await reply).status(), 200);
    const filters = root.getByRole("form", {
      name: "Admin privacy request filters",
    });
    await filters.locator("select").first().selectOption("reviewing");
    await filters.locator("button").click();
    await decision().waitFor();
    await decision().locator("select").selectOption("closed_review");
    const privateNote =
      "PRIVATE INTERNAL: documented review does not mean external legal fulfillment.";
    await decision().locator("textarea").fill(privateNote);
    reply = page.waitForResponse(
      (r) =>
        r.url().endsWith("/" + row.id + "/review") &&
        r.request().method() === "PATCH",
    );
    await decision().locator("button").click();
    assert.equal((await reply).status(), 200);
    const own = await customer.request("account/privacy-requests");
    assert.equal(own.data[0].status, "closed_review");
    assert(!JSON.stringify(own).includes(privateNote));
    await filters.locator("select").first().selectOption("closed_review");
    await filters.locator("button").click();
    const card = root
      .locator("article")
      .filter({
        has: page.getByRole("heading", {
          name: `Privacy request #${row.id} · correction`,
          exact: true,
        }),
      });
    await card
      .getByRole("button", {
        name: "Private review history / سجل المراجعة",
        exact: true,
      })
      .click();
    await card.getByText(new RegExp("PRIVATE INTERNAL:")).waitFor();
    await page.locator("#logout").click();
    await page.locator("#account").waitFor({ state: "visible" });
    assert.equal(await root.innerText(), "");
    console.log(
      "PASS actual canonical Admin privacy review: versioned transitions, encrypted private history, customer note isolation and logout. No deletion/export/financial mutation.",
    );
  } finally {
    await browser.close();
  }
}).catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
