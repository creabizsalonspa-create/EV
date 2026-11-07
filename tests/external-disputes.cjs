// Actual Laravel/browser history; provider ingestion fixture uses CLI-only HTTP fakes.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const fs = require("node:fs");
const assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/external-dispute-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(
    fixture.fixture_only && fixture.provider_evidence === "HTTP_FAKE_ONLY",
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [],
    directory = "artifacts/external-disputes/screenshots";
  fs.mkdirSync(directory, { recursive: true });
  async function submitLogin(page) {
    for (let attempt = 0; attempt < 2; attempt++) {
      const waiting = page.waitForResponse(
        (response) =>
          new URL(response.url()).pathname === "/api/login" &&
          response.request().method() === "POST",
      );
      await page.locator("button[value=login]").click();
      const response = await waiting;
      if (response.status() === 429 && attempt === 0) {
        const raw = response.headers()["retry-after"];
        assert(
          /^\d+$/.test(raw || ""),
          "Login429 must provide numeric Retry-After",
        );
        const seconds = Number(raw);
        assert(seconds >= 1 && seconds <= 60);
        console.log("Respecting explicit authentication429 Retry-After.");
        await page.waitForTimeout(seconds * 1000 + 250);
        continue;
      }
      assert.equal(
        response.status(),
        200,
        "Login failed; no retries except explicit429",
      );
      return;
    }
  }
  try {
    async function login(role) {
      const context = await browser.newContext(),
        page = await context.newPage();
      page.on("pageerror", (error) => errors.push(error.message));
      await page.goto(base);
      await page
        .locator("#account [name=email]")
        .fill(`invoices-${role}-${run}@example.test`);
      await page.locator("#account [name=password]").fill(password);
      await submitLogin(page);
      await page.locator('a[href="#commercial-finance"]').click();
      const root = page.locator('[data-external-disputes="true"]');
      await root
        .getByRole("button", { name: "Load provider disputes", exact: true })
        .click();
      await root
        .locator(`[data-dispute-id="${fixture.ids.needs_response}"]`)
        .waitFor();
      return { page, root, context };
    }
    const customer = await login("customer");
    await customer.root
      .locator(`[data-dispute-id="${fixture.ids.needs_response}"]`)
      .getByRole("button", { name: "Review dispute", exact: true })
      .click();
    await customer.root
      .getByRole("heading", { name: "Immutable provider history", exact: true })
      .waitFor();
    assert.equal(
      await customer.root
        .getByRole("button", { name: "Inspect provider outcome", exact: true })
        .count(),
      0,
    );
    assert.equal(await customer.root.locator("form").count(), 0);
    const api = await customer.page.evaluate(async () => {
      const response = await fetch("/api/commercial/external-disputes");
      return { status: response.status, data: await response.json() };
    });
    assert.equal(api.status, 200);
    assert(
      api.data.data.every(
        (row) =>
          !Object.hasOwn(row, "provider_reference") &&
          !Object.hasOwn(row, "charge_reference"),
      ),
    );
    const vendor = await login("vendor");
    assert.equal(await vendor.root.locator("[data-dispute-id]").count(), 3);
    const admin = await login("admin");
    await admin.root
      .locator(`[data-dispute-id="${fixture.ids.needs_response}"]`)
      .getByRole("button", { name: "Review dispute", exact: true })
      .click();
    await admin.root
      .getByRole("button", { name: "Inspect provider outcome", exact: true })
      .waitFor();
    await admin.root.locator("[data-dispute-evidence]").waitFor();
    // Do not execute provider evidence/reconciliation without a configured merchant.
    for (const width of [390, 768, 1440])
      for (const theme of ["light", "dark"]) {
        await admin.page.setViewportSize({ width, height: 1000 });
        await admin.page.locator("#theme-choice").selectOption(theme);
        await admin.root.scrollIntoViewIfNeeded();
        const bounds = await admin.root.boundingBox();
        assert(bounds.width <= width, "Dispute workspace exceeds viewport");
        await admin.root.screenshot({
          path: `${directory}/${theme}-${width}.png`,
        });
      }
    await admin.page.setViewportSize({ width: 390, height: 1000 });
    await admin.page.locator("#language").click();
    assert.equal(await admin.page.locator("html").getAttribute("dir"), "rtl");
    for (const theme of ["light", "dark"]) {
      await admin.page.locator("#theme-choice").selectOption(theme);
      await admin.root.screenshot({ path: `${directory}/${theme}-ar-390.png` });
    }
    await customer.page.locator("#logout").click();
    assert.equal(
      await customer.page.locator('[data-external-disputes="true"]').count(),
      0,
    );
    const otherContext = await browser.newContext(),
      other = await otherContext.newPage();
    await other.goto(base);
    await other
      .locator("#account [name=email]")
      .fill(`invoices-other-${run}@example.test`);
    await other.locator("#account [name=password]").fill(password);
    await submitLogin(other);
    await other.locator('a[href="#commercial-finance"]').click();
    const isolated = await other.evaluate(async (id) => {
      const response = await fetch(`/api/commercial/external-disputes/${id}`);
      return response.status;
    }, fixture.ids.needs_response);
    assert.equal(isolated, 404);
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel provider dispute history: customer/vendor/admin authorization, foreign404, no provider references, admin-only evidence controls, EN/AR RTL, light/dark390/768/1440, logout cleanup. Ingestion uses CLI HTTP fakes; no merchant or card-network verification.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
