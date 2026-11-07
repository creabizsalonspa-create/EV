const { chromium } = require("playwright");
const assert = require("node:assert/strict");
(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    headless: true,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext({
      geolocation: { latitude: 25.2, longitude: 55.3 },
      permissions: ["geolocation"],
    });
    const page = await context.newPage();
    let tiles = 0;
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("https://tile.openstreetmap.org/**", (r) => {
      tiles++;
      return r.abort();
    });
    await page.route("**/api/stations*", (r) =>
      r.fulfill({
        json: {
          data: [
            {
              id: 1,
              name: "<img src=x onerror=alert(1)>",
              latitude: 25.2,
              longitude: 55.3,
              connector_type: "CCS2",
              power_kw: 50,
              country: "AE",
              currency: "AED",
              evidence: "DISCOVERY_ONLY",
              reservable: false,
            },
            {
              id: 2,
              name: "Missing coordinates",
              latitude: null,
              longitude: null,
              connector_type: "CCS2",
              power_kw: 50,
              country: "AE",
              currency: "AED",
              evidence: "DISCOVERY_ONLY",
              reservable: false,
            },
          ],
          total: 2,
          current_page: 1,
          last_page: 1,
        },
      }),
    );
    await page.goto(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
    await page.locator("#stations h3").first().waitFor();
    assert.equal(tiles, 0);
    await page
      .getByRole("button", { name: "Use my location once", exact: true })
      .click();
    assert.equal(
      await page.locator("#filters [name=latitude]").inputValue(),
      "",
    );
    await page.locator("#location-consent").check();
    await page
      .getByRole("button", { name: "Use my location once", exact: true })
      .click();
    await page.waitForFunction(
      () =>
        document.querySelector("#filters [name=latitude]").value ===
        "25.200000",
    );
    assert.equal(
      await page.locator("#filters [name=longitude]").inputValue(),
      "55.300000",
    );
    await page
      .getByRole("button", { name: "Enable interactive map", exact: true })
      .click();
    await page.locator(".station-map-pin").waitFor();
    assert.equal(await page.locator(".station-map-pin").count(), 1);
    await page.locator(".station-map-pin").click();
    await page.locator(".leaflet-popup-content").waitFor();
    assert.equal(await page.locator(".leaflet-popup-content img").count(), 0);
    assert.match(
      await page.locator(".leaflet-popup-content").textContent(),
      /Availability unverified/,
    );
    assert.match(
      await page.locator(".leaflet-popup-content a").getAttribute("href"),
      /destination=25.2%2C55.3/,
    );
    await page.locator("#language").click();
    assert.match(
      await page.locator("#discovery-map").textContent(),
      /استخدام موقعي/,
    );
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS: map consent, GPS opt-in, null-coordinate exclusion, literal marker content, external destination, Arabic and mobile layout. Synthetic tiles/location only.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
