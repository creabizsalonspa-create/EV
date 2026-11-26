// Provider/tiles/GPS/navigation fixtures exercise UI contracts, not live acceptance.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
    });
    const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
    const cap = await context.request.get(base + "/api/discovery/capabilities");
    assert.equal(cap.status(), 200);
    assert.equal((await cap.json()).geocoding, false);
    const raw = await context.request.post(base + "/api/discovery/addresses", {
      data: { consent: true, query: "Dubai", language: "en" },
    });
    assert.equal(raw.status(), 419);
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.addInitScript(() => {
      localStorage.setItem("creabiz.theme", "dark");
      Object.defineProperty(navigator, "geolocation", {
        value: {
          getCurrentPosition(success) {
            window.pendingGps = success;
          },
        },
      });
      window.open = (url, target, features) => {
        window.navigationFixture = { url, target, features };
        return null;
      };
    });
    let enabled = false,
      addressCalls = 0,
      routeBody = null,
      routeCalls = 0,
      tiles = 0,
      holdNextCapabilities = false,
      signalHeldCapabilities = null,
      releaseHeldCapabilities = null;
    await page.route("https://tile.openstreetmap.org/**", (r) => {
      tiles++;
      return r.abort();
    });
    const stations = [1, 2, 3, 4].map((id) => ({
      id,
      name: "Synthetic stop " + id,
      latitude: 25.2 + id / 100,
      longitude: 55.3 + id / 100,
      connector_type: "CCS2",
      power_kw: 50,
      country: "AE",
      currency: "AED",
      evidence: "DISCOVERY_ONLY",
      availability: "unknown",
      remote_control: false,
      reservable: false,
    }));
    stations[3].connector_type = "Type2";
    // Unrelated distant results must not make a short selected journey
    // disappear at world zoom. This is a controlled catalog fixture.
    stations.push({...stations[0], id: 5, name: "Distant catalog fixture",
      latitude: 52.37, longitude: 4.9});
    await page.route("**/api/stations*", (r) =>
      r.fulfill({
        json: { data: stations, total: stations.length, current_page: 1, last_page: 1 },
      }),
    );
    await page.route("**/api/discovery/capabilities", async (r) => {
      if (holdNextCapabilities) {
        holdNextCapabilities = false;
        signalHeldCapabilities?.();
        await new Promise((resolve) => { releaseHeldCapabilities = resolve; });
      }
      return r.fulfill({
        json: {
          provider: "geoapify",
          geocoding: enabled,
          routing: enabled,
          max_charging_stops: 3,
        },
      });
    });
    await page.route("**/api/discovery/addresses", async (r) => {
      addressCalls++;
      const body = r.request().postDataJSON();
      assert.equal(body.consent, true);
      assert.equal(new URL(r.request().url()).search, "");
      await new Promise((resolve) =>
        setTimeout(resolve, body.query === "Dubai" ? 900 : 20),
      );
      await r.fulfill({
        json: {
          provider: "geoapify",
          evidence: "GEOCODING_RESULT",
          places: [
            {
              id: "geoapify:fixture",
              label:
                body.query === "Dubai"
                  ? "Old Dubai"
                  : "<img src=x onerror=alert(1)> Sharjah result",
              latitude: 25.2,
              longitude: 55.3,
            },
          ],
        },
      });
    });
    await page.route("**/api/discovery/route", (r) => {
      routeCalls++;
      routeBody = r.request().postDataJSON();
      assert.equal(routeBody.consent, true);
      assert.equal(routeBody.points.length, 5);
      return r.fulfill({
        json: {
          provider: "geoapify",
          evidence: "PROVIDER_ROUTE_ESTIMATE",
          distance_meters: 1250,
          duration_seconds: 220,
          traffic_verified: false,
          charging_time_included: false,
          segments: routeBody.points
            .slice(1)
            .map((p, i) => [routeBody.points[i], p]),
        },
      });
    });
    await page.goto(base);
    await page.locator("#stations h3").first().waitFor();
    const address = page.locator(".discovery-tools>details").first();
    await address.locator("summary").click();
    await page
      .getByText(
        "Address provider is not configured. Use GPS or manual coordinates.",
        { exact: true },
      )
      .waitFor();
    assert.equal(addressCalls, 0);
    enabled = true;
    await address.locator("summary").click();
    await address.locator("summary").click();
    const field = address.locator("fieldset");
    await field.waitFor();
    assert(await field.locator("input[type=search]").isDisabled());
    await field.locator("input[type=checkbox]").check();
    await field.locator("input[type=search]").fill("Du");
    await page.waitForTimeout(650);
    assert.equal(addressCalls, 0);
    await field.locator("input[type=search]").fill("Dubai");
    await page.waitForFunction(() => true);
    await page.waitForTimeout(650);
    assert.equal(addressCalls, 1);
    await field.locator("input[type=search]").fill("Sharjah");
    await field.getByRole("option").waitFor();
    await page.waitForTimeout(400);
    assert.equal(
      await field
        .getByRole("option", { name: "Old Dubai", exact: true })
        .count(),
      0,
    );
    assert.equal(await field.locator("img").count(), 0);
    await field.getByRole("option").click();
    assert.equal(
      await page.locator("#filters [name=latitude]").inputValue(),
      "25.200000",
    );
    assert.equal(tiles, 0);
    const journey = page.locator("#charging-route-planner");
    await journey.locator("summary").first().click();
    await journey.locator("[name=journey_origin_latitude]").fill("٢٥٫٢");
    await journey.locator("[name=journey_origin_longitude]").fill("٥٥٫٣");
    await journey.locator("[name=journey_destination_latitude]").fill("25.3");
    await journey.locator("[name=journey_destination_longitude]").fill("55.4");
    await journey
      .getByText("Choose stops from current results", { exact: true })
      .click();
    for (let i = 0; i < 3; i++)
      await journey
        .getByRole("button", { name: "Add charging stop", exact: true })
        .nth(i)
        .click();
    assert.equal(await journey.locator("ol li").count(), 3);
    assert(
      await journey
        .getByRole("button", { name: "Add charging stop", exact: true })
        .nth(3)
        .isDisabled(),
    );
    await journey
      .locator("ol li")
      .nth(2)
      .getByRole("button", { name: "Move earlier", exact: true })
      .click();
    assert(
      await journey
        .getByRole("button", { name: "Preview provider route", exact: true })
        .isDisabled(),
    );
    await journey.locator("form>label input[type=checkbox]").check();
    await journey
      .getByRole("button", { name: "Preview provider route", exact: true })
      .click();
    await journey.getByText("1.3 km · 4 min", { exact: true }).waitFor();
    const energy = journey.locator("#journey-energy-review");
    await energy.locator("summary").click();
    for (const [key,value] of Object.entries({capacity:"٦٠",soc:"80",reserve:"20",consumption:"20"})) {
      await energy.locator('[name=energy_'+key+']').fill(value);
    }
    await energy.getByText("Estimated energy above reserve: 36.0 kWh · assumed range: 180.0 km", {exact:true}).waitFor();
    await energy.getByText("Whole-route assumed energy: 0.3 kWh", {exact:true}).waitFor();
    const candidates = energy.locator("#energy-first-stop-candidates li");
    assert.equal(await candidates.count(),4);
    assert.match(await candidates.first().textContent(),/Synthetic stop 1/);
    assert.match(await candidates.last().textContent(),/Synthetic stop 4/);
    assert.equal(await energy.getByText(/Distant catalog fixture/).count(),0);
    assert.equal(await journey.locator("ol li").count(),3,"Candidates never select or replace itinerary stops");
    assert.equal(routeCalls,1,"Battery assumptions are local only");
    assert.equal(tiles,0);
    assert.deepEqual(Object.keys(routeBody).sort(),["consent","language","points"]);
    await energy.locator("[name=energy_reserve]").fill("90");
    await energy.getByText(/Enter capacity 1/).waitFor();
    assert.equal(await energy.getByText(/Whole-route assumed energy:/).count(),0);
    await energy.locator("[name=energy_reserve]").fill("20");
    await energy.getByText("Whole-route assumed energy: 0.3 kWh", {exact:true}).waitFor();
    await energy.locator("summary").click();
    assert.equal(routeBody.points[0].latitude, 25.2);
    assert.equal(routeBody.points[2].latitude, 25.23);
    assert.equal(tiles, 0);
    // A consented request only authorizes the reviewed origin/destination
    // and stop list, not future edits to that same in-memory form.
    await journey.locator("[name=journey_destination_latitude]").fill("25.31");
    assert.equal(
      await journey.locator("form>label input[type=checkbox]").isChecked(),
      false,
      "Editing a route must withdraw provider consent",
    );
    assert(await journey.getByRole("button", {
      name: "Preview provider route", exact: true
    }).isDisabled());
    assert.equal(routeCalls, 1, "Editing never sends the new coordinates");
    await energy.locator("summary").click();
    await energy.getByText("Preview a provider route for a whole-route energy estimate.",{exact:true}).waitFor();
    assert.equal(await energy.getByText(/Whole-route assumed energy:/).count(),0);
    await energy.locator("summary").click();
    await journey.locator("form>label input[type=checkbox]").check();
    await journey.getByRole("button", {
      name: "Preview provider route", exact: true
    }).click();
    await journey.getByText("1.3 km · 4 min", { exact: true }).waitFor();
    assert.equal(routeCalls, 2);
    assert.equal(routeBody.points.at(-1).latitude, 25.31);
    page.once("dialog", (dialog) => dialog.accept());
    await journey
      .getByRole("button", {
        name: "Open navigation with selected stops",
        exact: true,
      })
      .click();
    const navigation = await page.evaluate(() => window.navigationFixture);
    const url = new URL(navigation.url);
    assert.equal(url.origin, "https://www.google.com");
    assert.equal(url.searchParams.get("waypoints").split("|").length, 3);
    assert.equal(navigation.features, "noopener,noreferrer");
    await page
      .getByRole("button", { name: "Enable interactive map", exact: true })
      .click();
    await page.locator(".leaflet-overlay-pane path").first().waitFor();
    const routeBounds = await page.locator(".leaflet-overlay-pane path").first().boundingBox();
    assert(routeBounds && routeBounds.width >= 2 && routeBounds.height >= 2,
      "The selected journey must remain visible despite distant catalog results");
    assert.equal(await page.locator("#stations form").count(), 0);
    await page.locator("#language").click();
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
      JSON.stringify(
        await page.evaluate(() => ({
          width: innerWidth,
          scroll: document.documentElement.scrollWidth,
          offenders: [...document.querySelectorAll("body *")]
            .map((n) => ({
              tag: n.tagName,
              id: n.id,
              cls: typeof n.className === "string" ? n.className : "",
              left: n.getBoundingClientRect().left,
              right: n.getBoundingClientRect().right,
            }))
            .filter((n) => n.left < -1 || n.right > innerWidth + 1)
            .slice(0, 60),
        })),
      ),
    );
    assert.match(
      await journey.locator("summary").first().textContent(),
      /تخطيط المسار/,
    );
    fs.mkdirSync("artifacts/discovery-routing", { recursive: true });
    await journey.scrollIntoViewIfNeeded();
    await page.screenshot({
      path: "artifacts/discovery-routing/arabic-dark390.png",
    });
    // Changing connector type is a local filter only. Already-selected stops
    // stay visible and become a blocking conflict until explicitly removed.
    const connectorFilter = journey.locator('[name="route_connector"]');
    await connectorFilter.selectOption("Type2");
    assert.equal(await connectorFilter.inputValue(), "Type2");
    await journey.getByText("بعض التوقفات المحددة تستخدم موصلاً معلناً مختلفاً.", { exact: false }).waitFor();
    assert(await journey.getByRole("button", { name: "معاينة مسار المزود", exact: true }).isDisabled());
    assert(await journey.getByRole("button", { name: "فتح الملاحة بالتوقفات المحددة", exact: true }).isDisabled());
    assert.equal(await journey.locator("ol li").count(), 3, "Filter must not silently remove stops");
    assert.equal(routeCalls, 2, "Connector filter must not send route/provider requests");
    for(let i=0;i<3;i++) {
      await journey.locator("ol li").first().getByRole("button", { name: "إزالة التوقف", exact: true }).click();
    }
    assert.equal(await journey.locator("ol li").count(), 0);
    assert(await journey.getByRole("button", { name: "فتح الملاحة بالتوقفات المحددة", exact: true }).isEnabled());
    const addType2 = journey.getByRole("button", { name: "إضافة توقف شحن", exact: true }).nth(3);
    assert(await addType2.isEnabled());
    await addType2.click();
    assert.equal(await journey.locator("ol li").count(),1);
    assert.equal(routeCalls,2);
    await page.locator("#location-consent").check();
    await page
      .getByRole("button", { name: "استخدام موقعي مرة واحدة", exact: true })
      .click();
    // Keep an old-account capability response deliberately in flight.
    // Logging out must invalidate it even if it later says routing=true.
    const awaitingCapability = new Promise((resolve) => {
      signalHeldCapabilities = resolve;
    });
    holdNextCapabilities = true;
    await journey.locator("summary").first().click();
    await journey.locator("summary").first().click();
    let capabilityTimeout;
    try {
      await Promise.race([
        awaitingCapability,
        new Promise((_, reject) => {
          capabilityTimeout = setTimeout(
            () => reject(Error("Pending capability fixture never started")), 10000);
        }),
      ]);
    } finally {
      clearTimeout(capabilityTimeout);
    }
    const staleResponse = page.waitForResponse(
      (response) => response.url().includes("/api/discovery/capabilities"));
    await page.evaluate(() =>
      window.dispatchEvent(new CustomEvent("catalog-map-clear")),
    );
    releaseHeldCapabilities();
    await staleResponse;
    await page.waitForTimeout(0);
    await page.evaluate(() =>
      window.pendingGps({ coords: { latitude: 12, longitude: 34 } }),
    );
    assert.equal(
      await journey.locator("[name=journey_origin_latitude]").inputValue(),
      "",
    );
    assert.equal(await journey.locator("ol li").count(), 0);
    assert.equal(await connectorFilter.inputValue(), "", "Session clear must also clear route connector");
    assert.equal(await journey.locator("form>label input[type=checkbox]").isChecked(), false);
    assert(await journey.getByRole("button", { name: "معاينة مسار المزود", exact: true }).isDisabled());
    assert.equal(await journey.locator("form>label").filter({ hasText: "Geoapify" }).isHidden(), true,
      "Signed-out discovery must not retain provider capability or privacy consent");
    assert.equal(await field.locator("input[type=search]").inputValue(), "");
    assert.equal(
      await page.locator("#filters [name=latitude]").inputValue(),
      "",
    );
    assert.equal(await page.locator("#location-consent").isChecked(), false);
    // A background browser tab must also discard the previous itinerary,
    // even if there is no explicit account logout or new catalog fetch.
    await journey.locator("[name=journey_origin_latitude]").fill("24.25");
    await journey.locator("[name=journey_destination_longitude]").fill("56.75");
    const beforeVisibility = routeCalls;
    await page.evaluate(() => {
      Object.defineProperty(document, "hidden", {
        configurable: true, value: true,
      });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    assert.equal(
      await journey.locator("[name=journey_origin_latitude]").inputValue(),
      "",
      "Backgrounded route coordinates must be removed from the DOM",
    );
    assert.equal(
      await journey.locator("[name=journey_destination_longitude]").inputValue(),
      "",
    );
    assert.equal(await journey.locator("ol li").count(), 0);
    assert.equal(
      await journey.locator("form>label input[type=checkbox]").isChecked(),
      false,
    );
    await page.evaluate(() => {
      Object.defineProperty(document, "hidden", {
        configurable: true, value: false,
      });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    assert.equal(routeCalls, beforeVisibility,
      "Browser foregrounding must not contact a route provider or replay consent");
    assert.deepEqual(errors, []);
    console.log(
      "PASS: actual unconfigured/csrf endpoints; consent/debounce/stale-safe address UI; Arabic coordinates; bounded/reordered charging stops; sourced route preview; fixed-origin consented navigation; no charging authority; RTL390 and identity-clear GPS/itinerary privacy. Provider/navigation/GPS/tiles are synthetic.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
