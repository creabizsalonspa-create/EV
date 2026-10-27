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
        .textContent.includes("The service is temporarily unavailable"),
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
    let fixtureRole = "admin",
      commandFailure = 0,
      commandReads = 0;
    const admin = await context.newPage();
    admin.on("pageerror", (error) => errors.push(error.message));
    await admin.route("**/api/**", async (route) => {
      const path = new URL(route.request().url()).pathname;
      let body = { data: [], current_page: 1, last_page: 1, total: 0 };
      if (path === "/api/me")
        body = {
          id: 99,
          name: "Admin fixture",
          role: fixtureRole,
          email_verified_at: null,
        };
      if (path === "/api/regions") body = [];
      if (path === "/api/charger-commands") {
        assert.equal(route.request().method(), "GET");
        commandReads++;
        if (commandFailure)
          return route.fulfill({
            status: commandFailure,
            contentType: "application/json",
            body: JSON.stringify({ message: "fixture failure" }),
          });
        const second =
          new URL(route.request().url()).searchParams.get("page") === "2";
        body = {
          data: [
            {
              id: second ? 26 : 1,
              station_id: 7,
              action: "start",
              status: second ? "outcome_unknown" : "pending",
            },
          ],
          current_page: second ? 2 : 1,
          last_page: 2,
          total: 26,
          next_page_url: "https://invalid.example/never-follow",
        };
      }

      if (path === "/api/admin/overview")
        body = {
          users: 3,
          stations: 2,
          reservations: 1,
          simulations: 0,
          audit_events: [
            {
              action: "support.replied",
              actor_id: 42,
              subject: "=SUM(1,1)",
              created_at: "2026-10-27 00:00:00",
            },
            {
              action: "station.created",
              actor_id: 11,
              subject: "other fixture",
              created_at: "2026-10-26 00:00:00",
            },
          ],
        };
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify(body),
      });
    });
    await admin.goto(base);
    await admin.locator("#audit-search").waitFor();
    await admin.locator("#audit-search").fill("42");
    assert.equal(await admin.locator("#overview tbody tr").count(), 1);
    const downloadReady = admin.waitForEvent("download");
    await admin.locator("#audit-export").click();
    const download = await downloadReady;
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) chunks.push(chunk);
    const csv = Buffer.concat(chunks).toString("utf8");
    assert(csv.includes('"\'=SUM(1,1)"'));
    assert(!csv.includes("station.created"));
    await admin.locator("#audit-search").fill("no matching event");
    assert.equal(await admin.locator("#audit-export").isDisabled(), true);

    await admin.locator("#command-list h3").waitFor();
    await admin.evaluate(() => EvLocale.set("en"));
    assert.match(
      await admin.locator("#command-list").textContent(),
      /Command #1/,
    );
    await admin
      .locator("#command-pagination")
      .getByRole("button", { name: "Next", exact: true })
      .click();
    await admin
      .locator("#command-list h3")
      .filter({ hasText: "Command #26" })
      .waitFor();
    await admin.locator("#language").click();
    assert.equal(await admin.locator("html").getAttribute("dir"), "rtl");
    assert.match(
      await admin.locator("#command-list").textContent(),
      /الأمر #26/,
    );
    commandFailure = 503;
    await admin.locator("#commands-refresh").click();
    await admin.waitForFunction(() =>
      document.getElementById("commands-state").textContent.includes("الخدمة"),
    );
    assert.match(
      await admin.locator("#command-list").textContent(),
      /الأمر #26/,
    );
    commandFailure = 0;
    await admin.locator("#commands-refresh").click();
    await admin.waitForFunction(() =>
      document.getElementById("commands-state").textContent.includes("26 أمر"),
    );
    commandFailure = 401;
    await admin.locator("#commands-refresh").click();
    await admin.locator("#command-history").waitFor({ state: "hidden" });
    assert.equal(await admin.locator("#command-list").textContent(), "");
    commandFailure = 0;
    fixtureRole = "vendor";
    await admin.reload();
    await admin.locator("#command-list h3").waitFor();
    assert.equal(await admin.locator("#command-history").isVisible(), true);
    commandFailure = 419;
    await admin.locator("#commands-refresh").click();
    await admin.locator("#command-history").waitFor({ state: "hidden" });
    assert.equal(await admin.locator("#command-list").textContent(), "");
    commandFailure = 0;
    fixtureRole = "customer";
    const reads = commandReads;
    await admin.reload();
    await admin.locator("#signed").waitFor();
    assert.equal(await admin.locator("#command-history").isVisible(), false);
    assert.equal(commandReads, reads);
    assert.deepEqual(errors, []);
    console.log(
      "PASS: isolated frontend contract fixtures; paginated catalog preserves filters, safe text rendering, role navigation, read-only admin/vendor command history with pagination/Arabic/retry/auth cleanup, 503 recovery, offline/online state, 390/768/1440px layouts, RTL; zero page errors. Fixtures are not live data.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
