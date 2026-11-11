// Actual isolated Laravel customer UI; no erasure, export or financial mutation.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const { withPrivacyFixture } = require("./helpers/privacy-local.cjs");
withPrivacyFixture(async (fixture) => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const page = await browser.newPage({
      viewport: { width: 390, height: 844 },
    });
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.name));
    await page.goto(fixture.base);
    await page
      .locator("#account [name=email]")
      .fill(fixture.accounts.customer.email);
    await page
      .locator("#account [name=password]")
      .fill(fixture.accounts.customer.password);
    const login = page.waitForResponse(
      (r) =>
        new URL(r.url()).pathname === "/api/login" &&
        r.request().method() === "POST",
    );
    await page.locator("button[value=login]").click();
    assert.equal((await login).status(), 200);
    const root = page.locator("#account-privacy");
    const form = root.getByRole("form", {
      name: "Personal privacy rights request",
    });
    await form.waitFor();
    await form.getByLabel("Request kind").selectOption("erasure");
    await form
      .getByLabel("Private request details")
      .fill("Review my retained <b>personal</b> data.");
    let attempts = 0,
      firstId;
    const identities = [];
    await page.route("**/api/account/privacy-requests", async (route) => {
      if (route.request().method() !== "POST") return route.continue();
      attempts++;
      identities.push(route.request().postDataJSON().idempotency_key);
      if (attempts === 1) {
        const reply = await route.fetch();
        assert.equal(reply.status(), 201);
        firstId = (await reply.json()).id;
        return route.abort("failed");
      }
      return route.continue();
    });
    await form.locator("button[type=submit]").click();
    await root
      .getByText(
        "Request could not be confirmed. Check history or retry the unchanged request safely.",
        { exact: true },
      )
      .waitFor();
    await form.locator("button[type=submit]").click();
    await root
      .getByText(
        "Request recorded for manual review. No data has been automatically deleted.",
        { exact: true },
      )
      .waitFor();
    assert.equal(attempts, 2);
    assert.equal(identities[0], identities[1]);
    assert.match(identities[0], /^[0-9a-f-]{36}$/);
    const card = root
      .locator("article")
      .filter({ hasText: `Request #${firstId} ·` });
    assert.equal(await root.locator("article").count(), 1);
    assert.equal(await card.locator("b").count(), 0);
    await card
      .getByRole("button", {
        name: "Withdraw request / سحب الطلب",
        exact: true,
      })
      .click();
    await card.getByText(new RegExp("withdrawn")).waitFor();
    assert.equal(
      await root
        .getByRole("button", {
          name: "Withdraw request / سحب الطلب",
          exact: true,
        })
        .count(),
      0,
    );
    const me = await page.request.get(fixture.base + "/api/me");
    assert.equal(me.status(), 200);
    await page.locator("#language").click();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    await page.locator("#logout").click();
    await page.locator("#account").waitFor({ state: "visible" });
    assert.equal(await root.innerText(), "");
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual canonical customer privacy UI: committed lost response, same-key retry, owner history, withdrawal, account retention and logout/RTL privacy. No erasure/payment.",
    );
  } finally {
    await browser.close();
  }
}).catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
