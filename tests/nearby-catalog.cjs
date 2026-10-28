// Synthetic discovery catalog contract; never a hardware/navigation proof.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/api/stations*", (route) => {
      const query = new URL(route.request().url()).searchParams;
      return route.fulfill({
        json: {
          data: [
            {
              id: 1,
              name: "Synthetic nearby station",
              latitude: 25.2,
              longitude: 55.3,
              country: "AE",
              currency: "AED",
              connector_type: "CCS2",
              power_kw: 50,
              evidence: "DISCOVERY_ONLY",
              availability: "unknown",
              reservable: false,
              ...(query.has("latitude") ? { distance_km: 1.112 } : {}),
            },
          ],
          total: 1,
          current_page: 1,
          last_page: 1,
          next_page_url: null,
          prev_page_url: null,
        },
      });
    });
    await page.goto(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
    await page.locator("#stations h3").waitFor();
    assert(
      !(await page
        .locator("#stations")
        .textContent()
        .then((t) => t.includes("straight-line distance"))),
    );
    await page.locator("#filters summary").click();
    await page.locator("[name=latitude]").first().fill("25.2");
    assert.equal(
      await page.locator("#filters").evaluate((form) => form.checkValidity()),
      false,
    );
    await page.locator("[name=longitude]").first().fill("55.3");
    await page.locator("[name=radius_km]").fill("10");
    const request = page.waitForRequest(
      (r) =>
        new URL(r.url()).pathname === "/api/stations" &&
        new URL(r.url()).searchParams.has("radius_km"),
    );
    await page.locator("#filters button").click();
    const uri = new URL((await request).url());
    assert.equal(uri.searchParams.get("latitude"), "25.2");
    assert.equal(uri.searchParams.get("longitude"), "55.3");
    assert.equal(uri.searchParams.get("radius_km"), "10");
    await page
      .getByText("1.11 km straight-line distance", { exact: true })
      .waitFor();
    await page.locator("#language").click();
    await page.getByText("1.11 كم بخط مستقيم", { exact: true }).waitFor();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    assert.equal(await page.locator("#stations button").count(), 0);
    assert.deepEqual(errors, []);
    console.log(
      "Nearby catalog: coordinate query, truthful distance, Arabic RTL and no discovery reservation pass.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
