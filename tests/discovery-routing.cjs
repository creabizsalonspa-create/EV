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
      tiles = 0;
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
    await page.route("**/api/stations*", (r) =>
      r.fulfill({
        json: { data: stations, total: 4, current_page: 1, last_page: 1 },
      }),
    );
    await page.route("**/api/discovery/capabilities", (r) =>
      r.fulfill({
        json: {
          provider: "geoapify",
          geocoding: enabled,
          routing: enabled,
          max_charging_stops: 3,
        },
      }),
    );
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
    assert.equal(routeBody.points[0].latitude, 25.2);
    assert.equal(routeBody.points[2].latitude, 25.23);
    assert.equal(tiles, 0);
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
    await page.locator("#location-consent").check();
    await page
      .getByRole("button", { name: "استخدام موقعي مرة واحدة", exact: true })
      .click();
    await page.evaluate(() =>
      window.dispatchEvent(new CustomEvent("catalog-map-clear")),
    );
    await page.evaluate(() =>
      window.pendingGps({ coords: { latitude: 12, longitude: 34 } }),
    );
    assert.equal(
      await journey.locator("[name=journey_origin_latitude]").inputValue(),
      "",
    );
    assert.equal(await journey.locator("ol li").count(), 0);
    assert.equal(await field.locator("input[type=search]").inputValue(), "");
    assert.equal(
      await page.locator("#filters [name=latitude]").inputValue(),
      "",
    );
    assert.equal(await page.locator("#location-consent").isChecked(), false);
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
