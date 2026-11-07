const assert = require("node:assert/strict");
const { chromium } = require("playwright");
module.exports = async ({ customer, owner, f, run }) => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    const open = async (client) => {
      const c = await browser.newContext({
        viewport: { width: 390, height: 844 },
      });
      await c.addCookies(
        [...client.cookies].map(([name, value]) => ({
          name,
          value,
          url: client.base,
        })),
      );
      const p = await c.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(client.base);
      return p;
    };
    const page = await open(customer),
      root = page.locator("#rider-access"),
      label = "Browser rider " + run;
    await root.getByRole("textbox", { name: "Label", exact: true }).fill(label);
    await root
      .locator("form")
      .first()
      .getByRole("button", { name: "Save", exact: true })
      .click();
    await root
      .getByRole("button", { name: "Show identifier", exact: true })
      .waitFor();
    const secret = root.getByLabel("One-time identifier", { exact: true });
    await secret.waitFor();
    assert.equal(await secret.getAttribute("type"), "password");
    await root
      .getByRole("button", { name: "Show identifier", exact: true })
      .click();
    assert.equal(await secret.getAttribute("type"), "text");
    await root
      .getByRole("button", { name: "Hide identifier", exact: true })
      .click();
    assert.equal(await secret.count(), 0);
    const card = root
      .locator("article")
      .filter({ has: page.getByRole("heading", { name: label, exact: true }) })
      .first();
    await card
      .getByRole("button", { name: "Station approvals", exact: true })
      .click();
    await card
      .getByRole("textbox", { name: "Station name", exact: true })
      .fill(f.name);
    await card
      .getByRole("button", { name: "Search stations", exact: true })
      .click();
    const station = card
      .locator("article")
      .filter({ has: page.getByText(f.name, { exact: true }) })
      .first();
    await station
      .getByRole("button", { name: "Request station approval", exact: true })
      .click();
    await card.getByText(f.name + " · requested", { exact: true }).waitFor();
    const vendor = await open(owner),
      ops = vendor.locator("#vendor-operations");
    await ops
      .locator("article")
      .filter({
        has: vendor.getByRole("heading", { name: f.name, exact: true }),
      })
      .first()
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Charging access requests", exact: true })
      .click();
    const request = ops
      .locator("[data-rider-grant]")
      .filter({
        has: vendor.getByRole("heading", { name: label, exact: true }),
      });
    await request
      .getByRole("textbox", { name: "Approval expiry UTC", exact: true })
      .fill(
        new Date(Date.now() + 3600000).toISOString().replace(/\.\d+Z$/, "Z"),
      );
    await request
      .getByRole("textbox", { name: "Operator recovery note", exact: true })
      .fill("Reviewed synthetic card request");
    await request.getByRole("button", { name: "Save", exact: true }).click();
    await request
      .getByRole("button", {
        name: "Provision test-source authorization",
        exact: true,
      })
      .click();
    await request.getByText("active", { exact: true }).waitFor();
    await page.locator("#language").click();
    await root
      .getByRole("heading", { name: "بطاقات ورموز الشحن", exact: true })
      .waitFor();
    for (const p of [page, vendor])
      assert.equal(
        await p.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
      );
    assert.deepEqual(errors, []);
    console.log(
      "Customer registration/privacy/station request and operator approval/provisioning web workflow passed at 390px; EN/AR customer labels.",
    );
  } finally {
    await browser.close();
  }
};
