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
    let showPartner = true;
    let unsafePartner = false;
    const partnerQueries = [];
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
    await page.route("**/api/roaming/locations*", (r) => {
      partnerQueries.push(new URL(r.request().url()));
      return r.fulfill({
        json: {
          data: showPartner
            ? [{
                id: 19, provider_id: 9,
                evidence: "PARTNER_REPORTED_UNVERIFIED",
                availability: unsafePartner ? "available" : "unknown", remote_control: false, reservable: false,
                source_timestamp_evidence: "PARTNER_REPORTED_ONLY",
                reported_source_age_minutes: 120,
                received_at: "2026-11-07T12:00:00Z",
                location: {
                  id: "partner-mock",
                  name: "<img src=x onerror=alert(2)>",
                  address: "Fixture partner address",
                  city: "Fixture city", country: "ARE",
                  latitude: "25.21", longitude: "55.31",
                  last_updated: "2026-11-07T10:00:00Z",
                  availability: "unknown", remote_control: false, reservable: false,
                  evses: [],
                },
              }]
            : [],
          total: showPartner ? 1 : 0,
          current_page: 1,
          last_page: 1,
          next_page_url: null,
        },
      });
    });
    await page.goto(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
    await page.locator("#stations h3").first().waitFor();
    await page.locator("#roaming-discovery article").first().waitFor();
    assert.equal(tiles, 0);
    assert.equal(await page.locator(".partner-map-pin").count(), 0);
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
    // A GPS selection alone must not send coordinates to the partner API.
    const priorPartnerRequests = partnerQueries.length;
    await page.waitForTimeout(100);
    assert.equal(partnerQueries.length, priorPartnerRequests);
    const partnerRequest = page.waitForRequest((r) => {
      const url = new URL(r.url());
      return url.pathname === "/api/roaming/locations" && url.searchParams.has("latitude");
    });
    await page.getByRole("button", { name: "Find partners near selected point" }).click();
    const nearbyUri = new URL((await partnerRequest).url());
    assert.equal(nearbyUri.searchParams.get("latitude"), "25.200000");
    assert.equal(nearbyUri.searchParams.get("longitude"), "55.300000");
    assert.equal(nearbyUri.searchParams.get("radius_km"), "25");
    await page.locator("#roaming-discovery [name=latitude]").waitFor();
    assert.equal(await page.locator("#roaming-discovery [name=latitude]").inputValue(), "25.200000");
    await page
      .getByRole("button", { name: "Enable interactive map", exact: true })
      .click();
    await page.locator(".station-map-pin:not(.partner-map-pin)").waitFor();
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1);
    assert.equal(await page.locator(".partner-map-pin").count(), 1);
    // Refit requires explicit interaction; never creates new inventory.
    await page.getByRole("button", { name: "Show loaded locations on map" }).click();
    await page.getByText("Map fitted to loaded results only. Other nearby sites may not be listed.", { exact: true }).waitFor();
    const stationPinColor = await page.locator(".station-map-pin:not(.partner-map-pin)").evaluate(node => getComputedStyle(node).backgroundColor);
    const partnerPinColor = await page.locator(".partner-map-pin").evaluate(node => getComputedStyle(node).backgroundColor);
    assert.notEqual(partnerPinColor, stationPinColor, "Unverified partner pins must look distinct");
    assert.equal(await page.locator(".partner-map-pin").getAttribute("title"), "Unverified partner locations");
    await page.locator(".station-map-pin:not(.partner-map-pin)").click();
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
    await page.locator(".leaflet-popup-close-button").click();
    await page.locator(".partner-map-pin").click();
    const partnerPopup = page.locator(".leaflet-popup-content");
    await partnerPopup.getByText(/Partner reported \(unverified\)/).waitFor();
    assert.equal(await partnerPopup.locator("img").count(), 0);
    assert.equal(await partnerPopup.getByRole("button", { name: "Add charging stop" }).count(), 0);
    assert.match(await partnerPopup.locator("a").getAttribute("href"), /destination=25.21%2C55.31/);
    await partnerPopup.getByRole("button", { name: "View partner location in list" }).click();
    assert.equal(await page.locator("[data-partner-location-id='19']").count(), 1);
    // Identity/session reset removes every old map marker immediately.
    await page.evaluate(() => window.dispatchEvent(new CustomEvent("catalog-map-clear")));
    assert.equal(await page.locator(".partner-map-pin").count(), 0);
    assert.equal(await page.locator(".station-map-pin").count(), 0);
    await page.locator("#roaming-discovery").getByRole("button", { name: "Refresh", exact: true }).click();
    await page.locator(".partner-map-pin").waitFor();
    showPartner = false;
    await page.locator("#roaming-discovery").getByRole("button", { name: "Refresh", exact: true }).click();
    await page.getByText("No matching partner locations", { exact: true }).waitFor();
    assert.equal(await page.locator(".partner-map-pin").count(), 0);
    // A future API advertising unsupported live charging capabilities is rejected
    // before any partner marker can be displayed, even after a valid prior page.
    showPartner = true;
    unsafePartner = true;
    await page.locator("#roaming-discovery").getByRole("button", { name: "Refresh", exact: true }).click();
    await page.locator("#roaming-discovery").getByRole("button", { name: "Retry", exact: true }).waitFor();
    assert.equal(await page.locator(".partner-map-pin").count(), 0);
    await page.locator("#language").click();
    assert.match(
      await page.locator("#discovery-map").textContent(),
      /استخدام موقعي/,
    );
    await page.getByRole("button", { name: "إيقاف الخريطة التفاعلية" }).click();
    assert.equal(await page.locator("#station-map").isHidden(), true);
    assert.equal(await page.locator(".station-map-pin").count(), 0);
    assert.equal(await page.locator("#location-consent").isChecked(), false);
    assert.equal(await page.getByRole("button", { name: "البحث عن شركاء قرب النقطة المحددة" }).isHidden(), true);
    assert.equal(await page.getByRole("button", { name: "إظهار المواقع المحملة على الخريطة" }).isHidden(), true);
    assert.equal(await page.getByRole("button", { name: "تفعيل الخريطة التفاعلية" }).isVisible(), true);
    // A second consent must create a fresh Leaflet instance cleanly.
    await page.getByRole("button", { name: "تفعيل الخريطة التفاعلية" }).click();
    assert.equal(await page.locator("#station-map").isVisible(), true);
    await page.getByRole("button", { name: "إظهار المواقع المحملة على الخريطة" }).click();
    await page.getByText("لا توجد إحداثيات صالحة للمواقع المحملة على الخريطة.", { exact: true }).waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS: consented mixed map, disable/re-enable privacy and map refit, unverified partner capability checks, revocation, GPS opt-in, safe popups, Arabic and mobile layout. Synthetic only.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
