// Real Chromium, mocked OCM boundary. No provider, charger, payment or GPS contacted.
const assert = require("node:assert/strict");
const { chromium } = require("playwright");

const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));

const point = {
  id: "ocm:12345",
  provider: "open_charge_map",
  name: "<img src=x onerror=alert(1)>",
  address: "Fixture lane",
  city: "Dubai",
  country: "AE",
  latitude: 25.2,
  longitude: 55.3,
  distance_km: 1.25,
  distance_basis: "great_circle",
  operator_label: "Unverified network",
  source_status_evidence: "OPEN_CHARGE_MAP_DISCOVERY_ONLY",
  reported_operational: true,
  reported_operational_evidence: "OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED",
  reported_access: "Membership Required",
  reported_membership_required: true,
  reported_access_key_required: false,
  reported_access_evidence: "OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED",
  source_clock_evidence: "CROWD_REPORTED_TIME",
  source_age_minutes: 120,
  connections: [{ reported_connector_type: "Type 2", reported_power_kw: 22, availability: "unknown" }],
  data_license: "CC BY-SA 4.0",
  attribution: "Open Charge Map / Open-licensed contributor",
  source_url: "https://openchargemap.org/site/poi/details/12345",
  availability: "unknown",
  remote_control: false,
  reservable: false,
  billing_eligible: false,
};

const reply = {
  provider: "open_charge_map",
  evidence: "OPEN_LICENSED_DISCOVERY_ONLY",
  license_policy: "PER_RECORD_ATTRIBUTION_REQUIRED",
  availability: "unknown",
  remote_control: false,
  reservable: false,
  billing_eligible: false,
  results: [point],
};

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    headless: true,
    args: ["--no-sandbox", "--disable-dev-shm-usage"],
  });
  try {
    for (const mode of ["disabled", "enabled", "unsafe", "late"]) {
      const context = await browser.newContext({ permissions: [] });
      const page = await context.newPage();
      if (mode === "late") await page.addInitScript(() => {
        const original = Response.prototype.json;
        Response.prototype.json = function(...args) {
          if (this.url.includes("/api/discovery/open-charge-map/search")) {
            return new Promise((resolve, reject) => {
              window.__releaseOcmJson = async () => {
                try { resolve(await original.apply(this, args)); }
                catch (error) { reject(error); }
              };
            });
          }
          return original.apply(this, args);
        };
      });
      const pageErrors = [];
      const searchRequests = [];
      page.on("pageerror", (error) => pageErrors.push(error.message));
      await page.route("https://tile.openstreetmap.org/**", (route) => route.abort());
      await page.route("**/api/discovery/open-charge-map/capabilities", (route) =>
        route.fulfill({
          json: {
            provider: "open_charge_map",
            enabled: mode !== "disabled",
            requires_explicit_consent: true,
            availability: "unknown",
            remote_control: false,
            reservable: false,
          },
        }),
      );
      await page.route("**/api/discovery/open-charge-map/search", (route) => {
        searchRequests.push({
          method: route.request().method(),
          body: route.request().postDataJSON(),
        });
        return route.fulfill({
          json: mode === "unsafe" ? {
            ...reply,
            results: [{ ...point, remote_control: true }],
          } : reply,
        });
      });
      await page.goto(base);
      const root = page.locator("#open-charge-map-discovery");
      await root.waitFor({ state: "attached" });
      if (mode === "disabled") {
        assert.equal(await root.isHidden(), true);
        assert.equal(searchRequests.length, 0, "Disabled source must not transmit coordinates");
        assert.equal(await root.locator("article").count(), 0);
        await context.close();
        continue;
      }
      await root.waitFor({ state: "visible" });
      // Wait for the app's asynchronous initial identity resolution. An
      // unauthenticated identity clears maps once; it must not wipe a form
      // the visitor started filling after initialization completed.
      await page.waitForFunction(() => {
        const status = document.querySelector("#catalog-state");
        const stations = document.querySelector("#stations");
        return status && status.textContent.trim() !== "" &&
          stations && !stations.hasAttribute("aria-busy");
      });
      await page.getByRole("button", { name: "Enable interactive map" }).click();
      const layer = page.locator("#show-ocm-map-pins");
      assert.equal(await layer.isHidden(), true, "OCM overlay should not appear before a licensed search");
      await root.locator('[name="ocm_latitude"]').fill("25.2");
      await root.locator('[name="ocm_longitude"]').fill("55.3");
      await root.locator('[name="ocm_radius_km"]').fill("10");
      await root.locator('[name="ocm_country"]').fill("ae");
      assert.equal(await root.locator('[name="ocm_country"]').inputValue(), "AE");
      assert.equal(searchRequests.length, 0, "Filling coordinates must not start a partner request");
      await root.locator('input[type="checkbox"]').check();
      await root.getByRole("button", { name: "Search open-licensed locations" }).click();
      if (mode === "late") {
        await page.waitForFunction(() => typeof window.__releaseOcmJson === "function", { timeout: 6000 });
        await page.evaluate(() => window.dispatchEvent(new CustomEvent("catalog-map-clear")));
        await page.evaluate(() => window.__releaseOcmJson());
        await page.waitForTimeout(200);
        assert.equal(await root.locator("article").count(), 0);
        assert.equal(await page.locator(".ocm-map-pin").count(), 0,
          "Delayed JSON after identity switch leaked stale licensed-source data");
        assert.equal(await root.getByRole("button", { name: "Search open-licensed locations" }).isEnabled(), true,
          "Aborted source query must not permanently disable search");
        assert.deepEqual(pageErrors, []);
        await context.close();
        continue;
      }
      await page.waitForFunction(() => {
        const el = document.querySelector("#open-charge-map-discovery");
        return el && (el.querySelector("article") || el.textContent.includes("Licensed source unavailable"));
      });
      assert.equal(searchRequests.length, 1);
      assert.equal(searchRequests[0].method, "POST");
      assert.deepEqual(searchRequests[0].body, {
        consent: true,
        latitude: 25.2,
        longitude: 55.3,
        radius_km: 10,
        country: "AE",
      });
      assert.equal(await root.locator('input[type="checkbox"]').isChecked(), false);
      if (mode === "enabled") {
        assert.equal(await root.locator("article").count(), 1);
        assert.equal(await root.locator("article img").count(), 0, "Raw contributor title became HTML");
        assert.equal(await root.locator("article").textContent().then(s => s.includes("CC BY-SA 4.0")), true);
        assert.equal(await root.locator("article").textContent().then(s => s.includes("Type 2") && s.includes("22 kW")), true);
        await root.getByText("Crowd-reported operational at last update; unverified and not live.", { exact: true }).waitFor();
        await root.getByText("Crowd-reported access (unverified): Membership Required · confirm with operator, not live.", { exact: true }).waitFor();
        assert.equal(await root.getByRole("button", { name: "Request charge" }).count(), 0);
        const source = root.getByRole("link", { name: "View source and license" });
        assert.equal(await source.getAttribute("href"), "https://openchargemap.org/site/poi/details/12345");
        assert.equal(await source.getAttribute("rel"), "noopener noreferrer");
        await page.locator(".ocm-map-pin").waitFor();
        assert.equal(await page.locator(".ocm-map-pin").count(), 1);
        assert.equal(await page.locator(".ocm-map-pin").getAttribute("title"),
          "Open Charge Map — unverified discovery");
        await page.locator(".ocm-map-pin").click();
        const popup = page.locator(".leaflet-popup-content");
        await popup.getByText(
          "Open Charge Map crowd report (licensed source). Live availability unverified; no booking, charging or payment.",
          { exact: true },
        ).waitFor();
        await popup.getByText(
          "Crowd report: this location was operational at last update; not live.",
          { exact: true },
        ).waitFor();
        await popup.getByText(
          "Crowd-reported access (unverified): Membership Required. Check with operator.",
          { exact: true },
        ).waitFor();
        assert.equal(await popup.getByRole("button", { name: "Add charging stop" }).count(), 0);
        assert.equal(await popup.getByRole("button", { name: "Request charge" }).count(), 0);
        assert.equal(await popup.getByRole("link", { name: "View Open Charge Map source" }).getAttribute("href"),
          "https://openchargemap.org/site/poi/details/12345");
        const noSearchOnToggle = searchRequests.length;
        await layer.uncheck();
        assert.equal(await page.locator(".ocm-map-pin").count(), 0);
        await layer.check();
        assert.equal(await page.locator(".ocm-map-pin").count(), 1);
        assert.equal(searchRequests.length, noSearchOnToggle,
          "OCM source layer toggle must not request new coordinates or external data");
        const requestCountBeforeLocale = searchRequests.length;
        await page.locator("#language").click();
        assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
        await root.getByText("المسافة المباشرة: 1.25 km", { exact: true }).waitFor();
        await root.getByText("عرض المصدر والترخيص", { exact: true }).waitFor();
        assert.equal(searchRequests.length, requestCountBeforeLocale, "Locale switch must not refetch OCM coordinates");
        await page.locator("#language").click();
        await root.getByText("Straight-line distance: 1.25 km", { exact: true }).waitFor();
        await page.evaluate(() => window.dispatchEvent(new CustomEvent("catalog-map-clear")));
        assert.equal(await root.locator("article").count(), 0);
        assert.equal(await page.locator(".ocm-map-pin").count(), 0,
          "Identity clear must remove all stale OCM map markers");
        assert.equal(await layer.isHidden(), true);
        assert.equal(await root.locator('[name="ocm_latitude"]').inputValue(), "");
      } else {
        assert.equal(await root.locator("article").count(), 0);
        assert.equal(await page.locator(".ocm-map-pin").count(), 0,
          "Rejected actionable OCM results must never appear as markers");
        assert.match(await root.textContent(), /Licensed source unavailable or invalid/);
      }
      assert.deepEqual(pageErrors, []);
      await context.close();
    }
    console.log("PASS real Chromium OCM disabled/default privacy, explicit consent, source licenses, no HTML injection, fail-closed capability and identity reset (synthetic data)");
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
