// A delayed independent support read must not block the company workspace.
// All API responses are controlled fixtures; no account or charger is modified.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  let release, seen;
  const support = new Promise((r) => (release = r));
  const supportSeen = new Promise((r) => (seen = r));
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const paged = (data) => ({
      data,
      current_page: 1,
      last_page: 1,
      total: data.length,
    });
    await page.route("**/platform.js*", (route) =>
      route.fulfill({
        contentType: "text/javascript",
        body: fs.readFileSync(
          "services/business-api/public/platform.js",
          "utf8",
        ),
      }),
    );
    await page.route("**/api/**", async (route) => {
      const path = new URL(route.request().url()).pathname;
      const json = (data) =>
        route.fulfill({
          contentType: "application/json",
          body: JSON.stringify(data),
        });
      if (path === "/api/csrf") return json({ token: "synthetic-only" });
      if (path === "/api/me")
        return json({
          id: 1,
          name: "Bootstrap vendor",
          role: "vendor",
          email_verified_at: "2026-11-14T00:00:00Z",
        });
      if (path === "/api/support") {
        seen();
        await support;
        return json(paged([]));
      }
      if (path === "/api/regions")
        return json([
          {
            country: "AE",
            currency: "AED",
            minor_units: 2,
            timezone: "Asia/Dubai",
            tax_bps: 500,
          },
        ]);
      if (path === "/api/vendor-ops/companies")
        return json(
          paged([
            {
              id: 1,
              legal_name: "Independent company fixture",
              status: "approved",
            },
          ]),
        );
      if (path === "/api/favorites/status")
        return json({ favorite_station_ids: [] });
      return json(paged([]));
    });
    await page.goto(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
    let timer;
    try {
      await Promise.race([
        supportSeen,
        new Promise(
          (_, reject) =>
            (timer = setTimeout(
              () => reject(Error("Support bootstrap was not reached")),
              10000,
            )),
        ),
      ]);
    } finally {
      clearTimeout(timer);
    }
    assert.equal(
      await page
        .locator("#vendor-operations")
        .getByRole("heading", {
          name: "Independent company fixture",
          exact: true,
        })
        .count(),
      1,
      "An independent unresolved support read must not block company initialization",
    );
    release();
    console.log(
      "PASS: company workspace initializes before unresolved independent support read; controlled browser fixture only.",
    );
  } finally {
    release();
    await browser.close();
  }
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
