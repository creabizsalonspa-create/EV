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
    let partnerAgeMinutes = 120;
    let partnerClockAhead = false;
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
                provider_label: "Unverified Network_50%!",
                provider_label_evidence: "PROVIDER_CONFIGURED_UNVERIFIED",
                evidence: "PARTNER_REPORTED_UNVERIFIED",
                availability: unsafePartner ? "available" : "unknown", remote_control: false, reservable: false,
                source_timestamp_evidence: "PARTNER_REPORTED_ONLY",
                reported_source_age_minutes: partnerClockAhead ? null : partnerAgeMinutes,
                source_clock_evidence: partnerClockAhead ? "PARTNER_CLOCK_AHEAD" : "PARTNER_REPORTED_TIME",
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
    const viewportRequests = [];
    await page.route("**/api/discovery/search*", route => {
      viewportRequests.push(new URL(route.request().url()));
      return route.fulfill({
        json: { data: [], total: 0, current_page: 1, last_page: 1 },
      });
    });
    await page.goto(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
    // Capture the active tile layer for a deterministic delayed failure.
    // CI may deliver tileerror after the polar GPS callback.
    await page.evaluate(() => {
      const originalTileLayer = window.L.tileLayer;
      window.L.tileLayer = function (...args) {
        const layer = originalTileLayer.apply(this, args);
        window.__testMapTileLayer = layer;
        return layer;
      };
    });
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
    const platformLayerToggle = page.locator("#show-platform-map-pins");
    const layerToggle = page.locator("#show-partner-map-pins");
    assert.equal(await platformLayerToggle.isChecked(), true);
    assert.equal(await layerToggle.isChecked(), true);
    const mapRequestsBeforeToggle = partnerQueries.length;
    await layerToggle.uncheck();
    assert.equal(await page.locator(".partner-map-pin").count(), 0);
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1);
    await layerToggle.check();
    assert.equal(await page.locator(".partner-map-pin").count(), 1);
    assert.equal(partnerQueries.length, mapRequestsBeforeToggle, "Layer toggle must remain local and never refetch partner data");
    await platformLayerToggle.uncheck();
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 0);
    assert.equal(await page.locator(".partner-map-pin").count(), 1);
    await platformLayerToggle.check();
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1);
    assert.equal(partnerQueries.length, mapRequestsBeforeToggle, "Switching map sources must never issue API requests");
    // Refit requires explicit interaction; never creates new inventory.
    await page.getByRole("button", { name: "Show loaded locations on map" }).click();
    await page.getByText("Map fitted to loaded results only. Other nearby sites may not be listed.", { exact: true }).waitFor();
    assert.equal(viewportRequests.length, 0, "Map initialization and refit must not search the unified API");
    const unifiedViewportRequest = page.waitForRequest(request =>
      new URL(request.url()).pathname === "/api/discovery/search" &&
      new URL(request.url()).searchParams.has("south"));
    await page.getByRole("button", { name: "Search platform and partner locations in this map view" }).click();
    const viewUrl = new URL((await unifiedViewportRequest).url());
    for (const key of ["south", "north", "west", "east"]) {
      assert(Number.isFinite(Number(viewUrl.searchParams.get(key))),
        "Viewport search must send finite numeric " + key);
    }
    assert(Number(viewUrl.searchParams.get("south")) < Number(viewUrl.searchParams.get("north")));
    assert.equal(viewportRequests.length, 1, "One explicit click makes one unified catalog lookup");
    assert.match(await page.locator("#discovery-map").textContent(),
      /Results are paginated; availability is unverified/);
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
    await partnerPopup.getByText("Partner source age: 120 min; live status unverified.", { exact: true }).waitFor();
    await partnerPopup.getByText("Configured provider (unverified): Unverified Network_50%!", { exact: true }).waitFor();
    assert.equal(await partnerPopup.locator("img").count(), 0);
    assert.equal(await partnerPopup.getByRole("button", { name: "Add charging stop" }).count(), 0);
    assert.match(await partnerPopup.locator("a").getAttribute("href"), /destination=25.21%2C55.31/);
    await partnerPopup.getByRole("button", { name: "View partner location in list" }).click();
    assert.equal(await page.locator("[data-partner-location-id='19']").count(), 1);
    // Repeated partner reports older than 24 hours receive an explicit
    // non-live stale-record warning, without creating charging authority.
    partnerAgeMinutes = 2000;
    await page.locator("#roaming-discovery").getByRole("button", { name: "Refresh", exact: true }).click();
    await page.locator(".partner-map-pin.partner-pin-stale").waitFor();
    assert.equal(await page.locator(".partner-map-pin").getAttribute("title"), "Unverified partner — old reported data");
    await page.locator("#roaming-discovery").getByText("Warning: partner record is more than 24 hours old; it may be outdated.", { exact: true }).waitFor();
    await page.locator(".partner-map-pin").click();
    await page.locator(".leaflet-popup-content")
      .getByText("Partner source age: 2000 min (older than 24 hours); live status unverified.", { exact: true }).waitFor();
    partnerClockAhead = true;
    await page.locator("#roaming-discovery").getByRole("button", { name: "Refresh", exact: true }).click();
    await page.locator(".partner-map-pin.partner-pin-uncertain").waitFor();
    assert.equal(await page.locator(".partner-map-pin").getAttribute("title"), "Unverified partner — source time uncertain");
    await page.locator("#roaming-discovery").getByText("Warning: partner's reported timestamp is in the future; freshness cannot be confirmed.", { exact: true }).waitFor();
    await page.locator(".partner-map-pin").click();
    await page.locator(".leaflet-popup-content").getByText("Partner's reported timestamp is in the future; freshness unknown.", { exact: true }).waitFor();
    partnerClockAhead = false;
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
    assert.equal(await page.getByRole("button", { name: "البحث في تقارير الشركاء حول مركز الخريطة" }).isHidden(), true);
    assert.equal(await page.getByRole("button", { name: "إظهار المواقع المحملة على الخريطة" }).isHidden(), true);
    assert.equal(await layerToggle.isHidden(), true);
    assert.equal(await platformLayerToggle.isHidden(), true);
    assert.equal(await page.getByRole("button", { name: "تفعيل الخريطة التفاعلية" }).isVisible(), true);
    // A second consent must create a fresh Leaflet instance cleanly.
    await page.getByRole("button", { name: "تفعيل الخريطة التفاعلية" }).click();
    assert.equal(await page.locator("#station-map").isVisible(), true);
    await page.getByRole("button", { name: "إظهار المواقع المحملة على الخريطة" }).click();
    await page.getByText("لا توجد إحداثيات صالحة للمواقع المحملة على الخريطة.", { exact: true }).waitFor();
    const requestsBeforeMapClick = partnerQueries.length;
    await page.locator("#station-map").click({ position: { x: 120, y: 95 } });
    await page.getByRole("button", { name: "البحث عن شركاء قرب النقطة المحددة" }).waitFor();
    assert.equal(partnerQueries.length, requestsBeforeMapClick, "Map click must not silently disclose coordinates to partner API");
    const clickedMapQuery = page.waitForRequest((r) => {
      const url = new URL(r.url());
      return url.pathname === "/api/roaming/locations" && url.searchParams.has("latitude");
    });
    await page.getByRole("button", { name: "البحث عن شركاء قرب النقطة المحددة" }).click();
    const mapSelectionUri = new URL((await clickedMapQuery).url());
    assert.notEqual(mapSelectionUri.searchParams.get("latitude"), "25.200000");
    assert.equal(mapSelectionUri.searchParams.get("radius_km"), "25");
    const beforeViewSearch = partnerQueries.length;
    await page.locator("#station-map").hover();
    await page.mouse.wheel(0, 240);
    await page.waitForTimeout(150);
    assert.equal(partnerQueries.length, beforeViewSearch, "Map wheel/zoom must not transmit partner location");
    const viewRequest = page.waitForRequest(req => {
      const url = new URL(req.url());
      return url.pathname === "/api/roaming/locations" && url.searchParams.has("latitude");
    });
    await page.getByRole("button", { name: "البحث في تقارير الشركاء حول مركز الخريطة" }).click();
    const mapUri = new URL((await viewRequest).url());
    assert(Number(mapUri.searchParams.get("radius_km")) >= 1);
    assert.equal(mapUri.searchParams.get("radius_km"), "200", "Wide viewport must explicitly cap partner-radius to 200 km");
    await page.getByText("مساحة الخريطة أوسع من 200 كم؛ البحث في تقارير الشركاء ضمن 200 كم فقط من المركز. ليس توافراً مباشراً.", { exact: true }).waitFor();
    // Late background failures must preserve the exact capped-search disclosure.
    await page.evaluate(() => window.__testMapTileLayer.fire("tileerror"));
    await page.getByText("مساحة الخريطة أوسع من 200 كم؛ البحث في تقارير الشركاء ضمن 200 كم فقط من المركز. ليس توافراً مباشراً.", { exact: true }).waitFor();
    assert.equal(await page.locator("#map-tile-status").isVisible(), true);
    assert(Math.abs(Number(mapUri.searchParams.get("latitude"))) <= 90);
    assert(Math.abs(Number(mapUri.searchParams.get("longitude"))) <= 180);
    // Web Mercator stops at +/-85.0511. Valid polar GPS coordinates must
    // remain usable for list search without corrupting the Leaflet map.
    const queriesBeforePolarGps = partnerQueries.length;
    await context.setGeolocation({ latitude: 89.99, longitude: 10 });
    await page.locator("#location-consent").check();
    await page.getByRole("button", { name: "استخدام موقعي مرة واحدة" }).click();
    await page.waitForFunction(() =>
      document.querySelector("#filters [name=latitude]").value === "89.990000");
    await page.getByText("خط العرض المحدد خارج نطاق عرض الخريطة. يبقى البحث القريب في القائمة متاحاً.", { exact: true }).waitFor();
    // An unrelated slow tile failure must not erase the polar-map limit.
    await page.evaluate(() => window.__testMapTileLayer.fire("tileerror"));
    await page.getByText("خط العرض المحدد خارج نطاق عرض الخريطة. يبقى البحث القريب في القائمة متاحاً.", { exact: true }).waitFor();
    assert.equal(partnerQueries.length, queriesBeforePolarGps, "Polar GPS must not auto-disclose coordinates to partner API");
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    // A malformed mixed-source UI event is not a source authorization. The
    // map must drop null station DTOs, forged live evidence, false discovery
    // booking capabilities and partner claims without a page exception.
    const requestCountBeforeMalformedMap = viewportRequests.length;
    await page.evaluate(() => window.dispatchEvent(new CustomEvent(
      "unified-map-data",
      { detail: [
        { key: "catalog:3", kind: "catalog", station: null },
        {
          key: "catalog:4", kind: "catalog",
          station: { id: 4, name: "Forged live status", evidence: "LIVE_VERIFIED",
            latitude: 25.2, longitude: 55.3, reservable: true },
        },
        {
          key: "catalog:5", kind: "catalog",
          station: { id: 5, name: "Forged booking source", evidence: "DISCOVERY_ONLY",
            latitude: 25.2, longitude: 55.3, reservable: true },
        },
        {
          key: "catalog:8", kind: "catalog",
          station: { id: 8, name: "Missing connector metadata", evidence: "SIMULATED",
            latitude: 25.2, longitude: 55.3, power_kw: 50, reservable: false },
        },
        {
          key: "catalog:9", kind: "catalog",
          station: { id: 9, name: "Malformed advertised power", evidence: "SIMULATED",
            latitude: 25.2, longitude: 55.3, connector_type: "CCS2",
            power_kw: "50", reservable: false },
        },
        {
          key: "partner:6", kind: "partner", record_id: 6,
          evidence: "PARTNER_REPORTED_UNVERIFIED",
          availability: "available", reservable: true, remote_control: false,
          location: { name: "Forged partner", latitude: 25.2, longitude: 55.3 },
        },
      ] },
    )));
    assert.equal(await page.locator(".station-map-pin").count(), 0);
    assert.equal(viewportRequests.length, requestCountBeforeMalformedMap,
      "Map source validation must never request a new partner or catalog lookup");
    await page.evaluate(() => window.dispatchEvent(new CustomEvent(
      "unified-map-data",
      { detail: [{
        key: "catalog:7", kind: "catalog",
        station: { id: 7, name: "Source-validated platform station",
          evidence: "SIMULATED", latitude: 25.2, longitude: 55.3,
          connector_type: "CCS2", power_kw: 50, reservable: false },
      }] },
    )));
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1,
      "Valid published source remains visible after a rejected map payload");
    assert.equal(viewportRequests.length, requestCountBeforeMalformedMap);
    await page.evaluate(() => window.dispatchEvent(new CustomEvent("unified-map-clear")));
    assert.equal(await page.locator(".station-map-pin").count(), 0,
      "Revoking a unified map removes its prior account pins");
    await page.evaluate(() => window.dispatchEvent(new CustomEvent("unified-map-clear")));
    assert.equal(await page.locator(".station-map-pin").count(), 0);
    assert.equal(await page.locator("#station-map").isHidden(), true,
      "Old-account map revocation must release tile consent and hide viewport");
    await page.getByRole("button", {name: "تفعيل الخريطة التفاعلية"}).click();
    assert.equal(await page.locator("#station-map").isVisible(), true,
      "A new explicit map enable is required after account revocation");
    await page.evaluate(() => window.dispatchEvent(new CustomEvent(
      "catalog-map-data", { detail: [{
        id: 1, name: "Independent source", latitude: 25.2, longitude: 55.3,
        evidence: "SIMULATED", reservable: false,
      }] },
    )));
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1);
    await page.evaluate(() => window.dispatchEvent(new CustomEvent("unified-map-clear")));
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1,
      "Stale unified clear cannot erase another source's map markers");
    // A separate roaming page may supersede the *partner* source but not the
    // unified catalog source. Revoking the old actor removes only its pins.
    await page.evaluate(() => window.dispatchEvent(new CustomEvent(
      "unified-map-data", { detail: [{
        key: "catalog:7", kind: "catalog",
        station: { id: 7, name: "Former account station", evidence: "SIMULATED",
          latitude: 25.2, longitude: 55.3, connector_type: "CCS2", power_kw: 50 },
      }] },
    )));
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1);
    await page.evaluate(() => window.dispatchEvent(new CustomEvent(
      "roaming-map-data", { detail: [{
        id: 19, evidence: "PARTNER_REPORTED_UNVERIFIED", availability: "unknown",
        remote_control: false, reservable: false,
        location: { name: "Independent partner", latitude: 25.21, longitude: 55.31,
          availability: "unknown", reservable: false, remote_control: false },
        reported_source_age_minutes: 10, source_clock_evidence: "PARTNER_REPORTED_TIME",
      }] },
    )));
    assert.equal(await page.locator(".partner-map-pin").count(), 1);
    await page.evaluate(() => window.dispatchEvent(new CustomEvent("unified-map-clear")));
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 0);
    assert.equal(await page.locator(".partner-map-pin").count(), 1,
      "Old unified catalog pin revoked while newer independent partner survives");
    assert.equal(await page.locator("#station-map").isVisible(), true);
    await page.evaluate(() => window.dispatchEvent(new CustomEvent("catalog-map-clear")));
    // A retained old UI instance may emit a late clear after a different
    // mounted instance has published its own results. The publication
    // identity, not just the source label, must fence this revocation.
    await page.evaluate(() => {
      const owner = {};
      window.__ownedUnifiedPublication = owner;
      const published = new CustomEvent("unified-map-data", { detail: [{
        key: "catalog:7", kind: "catalog",
        station: { id: 7, name: "Current customer station",
          latitude: 25.2, longitude: 55.3, evidence: "SIMULATED",
          connector_type: "CCS2", power_kw: 50 },
      }] });
      published.evUnifiedPublication = owner;
      window.dispatchEvent(published);
      window.dispatchEvent(new CustomEvent("unified-map-clear", {
        detail: { stale: "retired previous customer" },
      }));
    });
    assert.equal(await page.locator(".station-map-pin:not(.partner-map-pin)").count(), 1,
      "Previous UI instance cannot revoke current actor's map publication");
    await page.evaluate(() => window.dispatchEvent(new CustomEvent(
      "unified-map-clear", {detail:window.__ownedUnifiedPublication},
    )));
    assert.equal(await page.locator(".station-map-pin").count(), 0);
    assert.equal(await page.locator("#station-map").isHidden(), true);
    assert.equal(viewportRequests.length, requestCountBeforeMalformedMap);
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
