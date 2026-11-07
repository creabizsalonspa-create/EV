// Actual loopback Laravel preferences; all locations are synthetic discovery only.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
const { mkdirSync } = require("node:fs");
const { BusinessClient } = require("./helpers/business-client.cjs");
(async () => {
  const customer = await new BusinessClient().register("customer");
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      [
        "tests/Fixtures/station-favorites.php",
        randomBytes(4).toString("hex"),
        String(customer.user.id),
      ],
      { encoding: "utf8" },
    ),
  );
  assert.equal(fixture.fixture_only, true);
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
      [...customer.cookies].map(([name, value]) => ({
        name,
        value,
        url: customer.base,
      })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.goto(customer.base);
    await page.locator("#favorite-catalog:not([hidden])").waitFor();
    await page.locator("#filters [name=q]").fill(fixture.search);
    const filtered = page.waitForResponse(
      (r) => new URL(r.url()).pathname === "/api/favorites/status",
    );
    await page.locator("#filters button").click();
    await filtered;
    const stationId = fixture.station_ids.at(-1);
    const card = page.locator(`[data-station-id="${stationId}"]`);
    await card.locator("[data-favorite-control]").waitFor();
    assert.equal(await card.locator("img").count(), 0);
    assert.match(await card.locator("h3").textContent(), /<img/);
    const rejected = await page.request.put(
      customer.base + "/api/favorites/" + stationId,
      { headers: { Accept: "application/json" } },
    );
    assert.equal(
      rejected.status(),
      419,
      "Cookie writes without CSRF must be rejected.",
    );
    let writes = 0;
    const endpoint = `**/api/favorites/${stationId}`;
    await page.route(endpoint, async (route) => {
      if (route.request().method() !== "PUT") return route.continue();
      writes++;
      await route.fetch();
      await route.abort("failed");
    });
    await card
      .getByRole("button", { name: "Save station", exact: true })
      .click();
    await page
      .locator("#notice")
      .filter({ hasText: "Preference update could not be confirmed" })
      .waitFor();
    assert.equal(writes, 1);
    await page.unroute(endpoint);
    await page.locator("#refresh-catalog").click();
    await card
      .getByRole("button", { name: "Remove saved station", exact: true })
      .waitFor();
    assert.equal(writes, 1, "Unknown outcomes must not replay writes.");
    assert.equal(await page.locator("#notice").textContent(), "");
    const publicData = await customer.request(
      "stations?q=" + encodeURIComponent(fixture.search),
    );
    assert(publicData.data.every((s) => !Object.hasOwn(s, "is_favorite")));
    const other = await new BusinessClient().register("customer");
    assert.equal((await other.request("favorites")).total, 0);
    await page.locator("#favorite-catalog").click();
    assert.equal(
      await page.locator("#catalog-title").textContent(),
      "Saved stations",
    );
    await page
      .locator("#catalog-pagination")
      .getByText("Page 1 / 2", { exact: true })
      .waitFor();
    assert.equal(await page.locator("#filters").isVisible(), false);
    await page
      .locator("#catalog-pagination")
      .getByRole("button", { name: /Next/ })
      .click();
    await page
      .locator("#catalog-pagination")
      .getByText("Page 2 / 2", { exact: true })
      .waitFor();
    await page
      .locator("#stations")
      .getByRole("button", { name: "Remove saved station", exact: true })
      .click();
    await page
      .locator("#catalog-pagination")
      .getByText("Page 1 / 1", { exact: true })
      .waitFor();
    assert.equal(
      await page.locator("#stations form").count(),
      0,
      "Saving discovery data cannot create a reservation control.",
    );
    mkdirSync("artifacts/favorites", { recursive: true });
    for (const width of [390, 768, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      for (const theme of ["light", "dark"]) {
        await page.locator("#theme-choice").selectOption(theme);
        assert(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
          ),
        );
        await page
          .locator("#stations article")
          .first()
          .scrollIntoViewIfNeeded();
        await page.screenshot({
          path: `artifacts/favorites/${theme}-${width}-en.png`,
        });
      }
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator("#language").click();
    await page
      .locator("#favorite-catalog")
      .filter({ hasText: "جميع المحطات" })
      .waitFor();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    assert.equal(
      await page.locator("#catalog-title").textContent(),
      "المحطات المحفوظة",
    );
    await page.locator("#stations article").first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: "artifacts/favorites/dark-390-ar.png" });
    await page.locator("#logout").click();
    await page.locator("#account:not([hidden])").waitFor();
    assert.equal(await page.locator("#favorite-catalog").isVisible(), false);
    assert.equal(await page.locator("[data-favorite-control]").count(), 0);
    assert.deepEqual(errors, []);
    console.log(
      "PASS: real-Laravel favorite scoping, lost-ack non-replay/refresh, public privacy, paginated last-row removal, literal names, discovery controls, Arabic390px and logout cleanup.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
