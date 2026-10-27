// Explicit UI resources only: fixture names/messages must never be translated.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs"),
  vm = require("node:vm");
(async () => {
  const scope = { window: {} };
  vm.runInNewContext(
    fs.readFileSync("services/business-api/public/locale-data.js", "utf8"),
    scope,
  );
  for (const [key, value] of Object.entries(scope.window.EV_MESSAGES)) {
    assert(value.en && value.ar, key);
    const variables = (s) =>
      [...s.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]).sort();
    assert.deepEqual(variables(value.en), variables(value.ar), key);
  }
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext(),
      page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
    await page.route("**/api/stations*", (route) =>
      route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: [
            {
              id: 1,
              name: "Create fleet",
              connector_type: "CCS2",
              power_kw: 50,
              country: "AE",
              currency: "AED",
              latitude: 25,
              longitude: 55,
              source: "discovery",
              evidence: "DISCOVERY_ONLY",
              availability: "unknown",
              reservable: false,
            },
          ],
          current_page: 1,
          last_page: 1,
          total: 1,
          next_page_url: null,
          prev_page_url: null,
        }),
      }),
    );
    await page.goto(base);
    await page.locator("#stations h3").waitFor();
    await page.locator("#account input[name=name]").fill("Unchanged draft");
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.lang === "ar");
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    assert.equal(
      await page.locator("#account input[name=name]").inputValue(),
      "Unchanged draft",
    );
    assert.equal(await page.locator("#account input").count(), 3);
    assert.equal(
      await page.locator("#stations h3").textContent(),
      "Create fleet",
    );
    assert.match(
      await page.locator("#stations .badge").textContent(),
      /اكتشاف فقط/,
    );
    assert.equal(
      await page.locator("#fleet-create button").textContent(),
      "إنشاء أسطول",
    );
    assert.equal(await page.locator("#fleets").isVisible(), false);
    await page.reload();
    await page.locator("#stations h3").waitFor();
    assert.equal(await page.locator("html").getAttribute("lang"), "ar");
    await page.setViewportSize({ width: 390, height: 844 });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await page.goto(
      base +
        "/reset-password/synthetic-invalid-token?email=synthetic%40example.test",
    );
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    await page
      .getByRole("heading", { name: "إعادة تعيين كلمة المرور", exact: true })
      .waitFor();
    assert.equal(
      await page.locator("input[name=token]").inputValue(),
      "synthetic-invalid-token",
    );
    await page.locator("input[name=password]").fill("synthetic-test-password");
    await page
      .locator("input[name=password_confirmation]")
      .fill("synthetic-test-password");
    await page.locator("#reset-password button").click();
    await page.waitForFunction(() =>
      document
        .querySelector("#status")
        .textContent.includes("تحقق من البيانات"),
    );
    await page.goto(base);
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.lang === "en");
    assert.equal(await page.locator("html").getAttribute("dir"), "ltr");
    const denied = await browser.newContext();
    await denied.addInitScript(() =>
      Object.defineProperty(window, "localStorage", {
        get() {
          throw new DOMException("Disabled", "SecurityError");
        },
      }),
    );
    const restricted = await denied.newPage();
    restricted.on("pageerror", (e) => errors.push(e.message));
    await restricted.goto(base);
    await restricted.locator("#language").click();
    assert.equal(await restricted.locator("html").getAttribute("lang"), "ar");
    assert.deepEqual(errors, []);
    console.log(
      "PASS: web resource/interpolation parity; persistent EN/AR and RTL, preserved form fields and user-content text, translated dynamic provenance, mobile width, localized recovery validation, storage-denied fallback; zero page errors.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
