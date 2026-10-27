// Isolated frontend contract fixtures; never a production station-data source.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
    let fail = false;
    await page.route("**/api/stations*", async (route) => {
      const url = new URL(route.request().url());
      if (fail)
        return route.fulfill({
          status: 503,
          contentType: "application/json",
          body: JSON.stringify({
            message: "Catalog temporarily unavailable. Try again.",
          }),
        });
      const second = url.searchParams.get("page") === "2";
      const search = new URLSearchParams(url.search);
      search.set("page", second ? "1" : "2");
      const link = base + "/api/stations?" + search;
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: [
            {
              id: second ? 2 : 1,
              name: second
                ? "Contract station page two"
                : "<img src=x onerror=alert(1)>",
              source: "discovery",
              evidence: "DISCOVERY_ONLY",
              availability: "unknown",
              reservable: false,
              connector_type: "CCS2",
              power_kw: 50,
              country: "AE",
              currency: "AED",
              latitude: "25.2",
              longitude: "55.3",
            },
          ],
          current_page: second ? 2 : 1,
          last_page: 2,
          total: 26,
          next_page_url: second ? null : link,
          prev_page_url: second ? link : null,
        }),
      });
    });
    await page.goto(base);
    await page.locator("#stations h3").waitFor();
    assert.equal(await page.locator("#stations img").count(), 0);
    assert.match(await page.locator("#stations h3").textContent(), /<img/);
    await page.locator('#filters [name="country"]').fill("AE");
    await page.locator('#filters [name="connector_type"]').selectOption("CCS2");
    const filtered = page.waitForResponse(
      (r) =>
        r.url().includes("/api/stations?") &&
        new URL(r.url()).searchParams.get("country") === "AE",
    );
    await page.locator("#filters button").click();
    await filtered;
    const next = page.waitForResponse((r) => r.url().includes("page=2"));
    await page
      .locator("#catalog-pagination")
      .getByRole("button", { name: /Next/ })
      .click();
    const response = await next;
    assert.equal(new URL(response.url()).searchParams.get("country"), "AE");
    assert.equal(
      new URL(response.url()).searchParams.get("connector_type"),
      "CCS2",
    );
    await page
      .getByRole("heading", { name: "Contract station page two" })
      .waitFor();
    assert.equal(
      await page
        .locator("#catalog-pagination")
        .getByRole("button", { name: /Next/ })
        .isDisabled(),
      true,
    );
    assert.equal(await page.locator("[data-access=admin]").isVisible(), false);
    assert.equal(await page.locator("[data-access=vendor]").isVisible(), false);
    fail = true;
    await page.locator("#refresh-catalog").click();
    await page.waitForFunction(() =>
      document
        .querySelector("#catalog-state")
        .textContent.includes("Catalog temporarily unavailable"),
    );
    assert.equal(
      await page.locator("#stations").getAttribute("aria-busy"),
      null,
    );
    await context.setOffline(true);
    await page.locator("#offline-banner").waitFor({ state: "visible" });
    await context.setOffline(false);
    await page.locator("#offline-banner").waitFor({ state: "hidden" });
    fail = false;
    await page.locator("#refresh-catalog").click();
    await page.waitForFunction(() =>
      document
        .querySelector("#catalog-state")
        .textContent.includes("26 stations"),
    );
    for (const width of [390, 768, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      assert(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth,
        ),
      );
    }
    await page.locator("#language").click();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS: isolated frontend contract fixtures; paginated catalog preserves filters, safe text rendering, role navigation, 503 recovery, offline/online state, 390/768/1440px layouts, RTL; zero page errors. Fixtures are not live data.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
