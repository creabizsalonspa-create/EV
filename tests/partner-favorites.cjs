// Private customer preferences over public partner records. No charging authority.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const { BusinessClient } = require("./helpers/business-client.cjs");
const assert = require("node:assert/strict");
async function main() {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = (action) =>
    execFileSync(
      "./scripts/php",
      [
        "tests/Fixtures/roaming-workspace.php",
        action,
        run,
        ...(action === "seed" ? [password] : []),
      ],
      { stdio: "pipe" },
    );
  fixture("seed");
  const customer = await new BusinessClient().register("customer");
  const admin = new BusinessClient();
  admin.csrf = (await admin.request("csrf")).token;
  await admin.request("login", "POST", {
    email: "roaming-admin-" + run + "@example.test",
    password,
  });
  admin.csrf = (await admin.request("csrf")).token;
  const provider = (await admin.request("ocpi/providers")).data.find(
    (p) => p.name === "Synthetic owner " + run,
  );
  assert(provider);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext();
    await context.addCookies(
      [...customer.cookies].map(([name, value]) => ({
        name,
        value,
        url: customer.base,
      })),
    );
    const page = await context.newPage();
    await page.goto(customer.base);
    const pub = page.locator("#roaming-discovery");
    await pub.locator("[name=partner_q]").fill(run);
    await pub.locator("[name=partner_country]").fill("NLD");
    await pub
      .locator("[name=partner_connector]")
      .selectOption("IEC_62196_T2_COMBO");
    await pub
      .getByRole("button", { name: "Search partner locations", exact: true })
      .click();
    await pub
      .getByText("No matching partner locations", { exact: true })
      .waitFor();
    await pub.locator("[name=partner_country]").fill("ARE");
    await pub
      .getByRole("button", { name: "Search partner locations", exact: true })
      .click();
    await pub.getByText("Page 1 of 2 · 26 records", { exact: true }).waitFor();

    await pub
      .getByRole("button", { name: "Save partner location", exact: true })
      .first()
      .click();
    await pub
      .getByRole("button", {
        name: "Remove saved partner location",
        exact: true,
      })
      .waitFor();
    await pub
      .getByRole("button", { name: "Saved partner locations", exact: true })
      .click();
    await pub.getByText("Page 1 of 1 · 1 records", { exact: true }).waitFor();
    assert.equal(await pub.locator("article").count(), 1);
    const publicData = await customer.request("roaming/locations");
    assert(
      publicData.data.every(
        (row) =>
          !Object.hasOwn(row, "is_favorite") &&
          !Object.hasOwn(row, "favorite_since"),
      ),
    );
    await admin.request(
      "ocpi/providers/" + provider.id + "/authorization",
      "PATCH",
      { authorized: false },
    );
    await pub.getByRole("button", { name: "Refresh", exact: true }).click();
    await pub
      .getByText("Saved partner location is unavailable", { exact: true })
      .waitFor();
    assert(!(await pub.textContent()).includes("Synthetic address"));
    await pub
      .getByRole("button", {
        name: "Remove saved partner location",
        exact: true,
      })
      .click();
    await pub
      .getByText("No saved partner locations", { exact: true })
      .waitFor();
    await page.locator("#logout").click();
    await page.waitForFunction(
      () =>
        document
          .querySelector("#roaming-discovery")
          .querySelectorAll("[data-private-roaming]").length === 0,
    );
    assert.equal(
      await pub
        .getByRole("button", { name: "Saved partner locations", exact: true })
        .count(),
      0,
    );
    console.log(
      "PASS real Laravel partner favorites: private save/list/remove, public response isolation, revoked-source redaction and logout cleanup.",
    );
  } finally {
    await browser.close();
    fixture("clean");
  }
}
main().catch(() => {
  console.error(
    "Private partner favorite workflow failed; customer and source details withheld.",
  );
  process.exitCode = 1;
});
