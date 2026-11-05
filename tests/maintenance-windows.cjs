const assert = require("node:assert/strict");
const { randomBytes } = require("node:crypto");
const { execFileSync } = require("node:child_process");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");
(async () => {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/maintenance-windows.php", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(f.fixture_only);
  const start = new Date();
  start.setUTCMinutes(0, 0, 0);
  start.setUTCHours(start.getUTCHours() + 2);
  const end = new Date(start.getTime() + 3600000),
    iso = (d) => d.toISOString().replace(".000Z", "Z");
  const owner = new BusinessClient();
  owner.csrf = (await owner.request("csrf")).token;
  await owner.request("login", "POST", {
    email: `canonical-owner-${run}@example.test`,
    password,
  });
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
      [...owner.cookies].map(([name, value]) => ({
        name,
        value,
        url: owner.base,
      })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(owner.base);
    const ops = page.locator("#vendor-operations");
    await ops
      .locator("article")
      .filter({ has: page.getByRole("heading", { name: f.name, exact: true }) })
      .first()
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Open connectors", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Scheduled maintenance", exact: true })
      .first()
      .click();
    const pane = ops.locator("[data-maintenance-windows]");
    await pane.locator("[name=starts_at]").fill(iso(start));
    await pane.locator("[name=ends_at]").fill(iso(end));
    await pane
      .locator("[name=reason]")
      .fill("Planned connector inspection <literal>");
    await pane.getByRole("button", { name: "Save", exact: true }).click();
    await pane
      .locator("[data-maintenance-window]")
      .getByText("Planned connector inspection <literal>", { exact: true })
      .waitFor();
    assert.equal(await pane.locator("literal").count(), 0);
    const customer = new BusinessClient();
    customer.csrf = (await customer.request("csrf")).token;
    await customer.request("login", "POST", {
      email: `canonical-customer-${run}@example.test`,
      password,
    });
    customer.csrf = (await customer.request("csrf")).token;
    const booking = {
      station_id: f.station_id,
      connector_mapping_id: f.maps[0],
      starts_at: iso(start),
      idempotency_key: "maintenance-" + run,
    };
    await assert.rejects(
      () => customer.request("reservations", "POST", booking),
      /409/,
    );
    await page.locator("#language").click();
    await pane
      .getByRole("heading", { name: "الصيانة المجدولة", exact: true })
      .waitFor();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    await page.locator("#language").click();
    page.once("dialog", (d) => d.accept());
    await pane
      .getByRole("button", { name: "Cancel maintenance", exact: true })
      .click();
    await pane.getByText("Maintenance cancelled", { exact: true }).waitFor();
    await customer.request("reservations", "POST", booking);
    assert.deepEqual(errors, []);
    console.log(
      "PASS connected scheduled maintenance: operator create/cancel, customer admission blocked/released, literal notes, EN/AR390px; no hardware command.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
