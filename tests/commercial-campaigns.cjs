// Actual Laravel/browser workflows; prepaid mock package and synthetic signed publisher only.
const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  fs = require("node:fs"),
  assert = require("node:assert/strict");
const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
// Exercise the exact browser panel, independently of the real Laravel fixture
// below. This deliberately models a shrinking eligible inventory and retained
// DOM callbacks rather than replacing the production validation function.
async function sponsorPanelRegression() {
  const source = fs.readFileSync("services/business-api/public/platform.js", "utf8");
  const start = source.indexOf("function sponsorPanel(");
  const end = source.indexOf("\nfunction clearCatalogPromotions()", start);
  assert(start >= 0 && end > start);
  for (const lang of ["en", "ar"]) {
    class Element {
      constructor(tag, text = "") {
        this.tag = tag; this.textContent = text; this.children = [];
        this.dataset = {}; this.isConnected = true; this.hidden = false;
      }
      append(...nodes) { this.children.push(...nodes); }
      replaceChildren(...nodes) { this.children = nodes; }
      contains(node) { return this.children.includes(node); }
    }
    const document = {documentElement: {lang}, createElement: tag => new Element(tag)};
    const client = {sessionEpoch: 1}, request = {signal: {aborted: false}};
    const station = {id: 42, country: "AE"};
    const calls = [], tasks = [];
    let response;
    const row = id => ({campaign_id: id, station_id: 42, sponsored: true,
      availability_verified: false, compatibility_verified: false, capability_override: false,
      title: "Offer " + id, title_ar: "عرض " + id, description: "Sponsor", description_ar: "الراعي"});
    const page = (current_page, last_page, total, placements) =>
      ({current_page, last_page, total, per_page: 1, placements,
        ranking_basis: "SPONSORED_ID_ORDER_NOT_PERFORMANCE", client_telemetry_billable: false});
    response = page(1, 2, 2, [row(1)]);
    const fn = new Function("document", "client", "api", "node", "queueMicrotask",
      source.slice(start, end) + "\nreturn sponsorPanel;")(
        document, client, async url => { calls.push(url); return response; },
        (tag, text) => new Element(tag, text), task => tasks.push(task));
    const panel = fn([station], "discovery_banner", request, 1);
    const content = panel.children[1], tick = async () => {await Promise.resolve();await Promise.resolve();};
    const button = en => content.children.find(e => e.tag === "button" && e.dataset.en === en);
    await tasks.shift()();
    const next = button("Next promotions");
    assert(next);
    response = page(2, 1, 0, []);
    next.onclick(); next.onclick(); await tick();
    assert.equal(calls.length, 2);
    assert.equal(panel.hidden, false);
    const back = button("Previous promotions");
    assert(back);
    next.onclick(); await tick();
    assert.equal(calls.length, 2, "retired page cannot dispatch");
    response = page(1, 1, 0, []);
    back.onclick(); await tick();
    assert.equal(calls.length, 3);
    assert.equal(panel.hidden, true);
    // Fail the entire page if even one placement or source claim is invalid.
    response = page(1, 1, 1, [{...row(7), station_id: 999}]);
    const corrupt = fn([station], "discovery_banner", request, 1);
    await tasks.shift()();
    const children = corrupt.children[1].children;
    assert.equal(children.some(e => e.tag === "h4"), false);
    const retry = children.find(e => e.dataset.en === "Retry promotions");
    assert(retry);
    const count = calls.length;
    client.sessionEpoch++;
    retry.onclick(); await tick();
    assert.equal(calls.length, count, "revoked owner cannot dispatch retry");
  }
  console.log("PASS exact sponsored browser panel: EN/AR withdrawal, invalid evidence, stale page and revoked retry");
}

(async () => {
  await sponsorPanelRegression();
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/campaign-browser.php", "create", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(f.fixture_only);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  const dir = "artifacts/finance-campaigns/screenshots";
  fs.mkdirSync(dir, { recursive: true });
  try {
    async function login(role) {
      const c = await browser.newContext(),
        p = await c.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));

      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`campaign-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      async function attempt() {
        const pending = p.waitForResponse(
          (r) =>
            r.url().endsWith("/api/login") && r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        return pending;
      }
      let response = await attempt();
      if (response.status() === 429) {
        const seconds = Number(response.headers()["retry-after"]);
        assert(Number.isInteger(seconds) && seconds >= 1 && seconds <= 60);
        await p.waitForTimeout((seconds + 1) * 1000);
        response = await attempt();
      }
      assert.equal(response.status(), 200);
      if (role === "customer") return { p };
      await p.locator('a[href="#commercial-finance"]').click();
      const panel = p.locator("[data-commercial-campaigns]");
      await panel
        .getByRole("button", { name: "Open commercial campaigns", exact: true })
        .click();
      return { p, panel };
    }
    const { p: vendor, panel: v } = await login("vendor");
    await v
      .getByRole("button", { name: "Create sponsored campaign", exact: true })
      .click();
    await v.locator("[name=invoice_id]").selectOption(String(f.invoice));
    await v.locator("[name=station_id]").selectOption(String(f.station));
    await v
      .locator("[name=title]")
      .fill("Browser sponsored " + run + " <img src=x>");
    await v.locator("[name=title_ar]").fill("حملة برعاية " + run);
    await v
      .locator("[name=description]")
      .fill("Actual paid package test, not a verified human impression");
    await v
      .locator("[name=description_ar]")
      .fill("اختبار حزمة مدفوعة دون إثبات مشاهدة بشرية");
    await v.locator("[name=placement]").selectOption("station_detail");
    await v.locator("[name=budget_units]").fill("3");
    const attempts = [], receipts = [];
    const createPath = "**/api/commercial/campaigns";
    await vendor.route(createPath, async route => {
      if (route.request().method() !== "POST") return route.continue();
      attempts.push(route.request().postDataJSON());
      const response = await route.fetch();
      assert.equal(response.status(), 201);
      receipts.push(await response.json());
      await route.abort("failed");
    });
    await v.getByRole("button", { name: "Save campaign draft", exact: true }).click();
    const retryCreation = v.getByRole("button", { name: "Retry exact campaign creation", exact: true });
    await retryCreation.waitFor();
    await retryCreation.click();
    await vendor.waitForFunction(() => !document.querySelector("[data-commercial-campaigns]").inert);
    assert.equal(attempts.length, 2);
    assert.deepEqual(attempts[0], attempts[1]);
    assert.equal(receipts[0].campaign.id, receipts[1].campaign.id);
    const body = receipts[0], id = body.campaign.id;
    assert.equal(body.campaign.additional_cash_charge, false);
    assert.equal(await v.locator("[name=title]").count(), 0);
    const saved = await vendor.evaluate(() => Object.entries(sessionStorage)
      .filter(([k]) => k.startsWith("ev.campaign.creation.v1.")));
    assert.equal(saved.length, 1);
    assert.equal(saved[0][1], attempts[0].idempotency_key);
    await vendor.unroute(createPath);
    // Reload loses private payload, not the saved opaque recovery identity.
    await vendor.reload();
    if (!(await v.isVisible())) await vendor.locator('a[href="#commercial-finance"]').click();
    await v.getByRole("button", { name: "Open commercial campaigns", exact: true }).click();
    await v.getByText("Unresolved campaign creation", { exact: true }).waitFor();
    assert.equal(await retryCreation.count(), 0);
    await v.getByRole("button", { name: "Recover or cancel unused creation", exact: true }).click();
    await v.getByRole("button", { name: "Submit for review", exact: true }).waitFor();
    assert.equal(await vendor.evaluate(() => Object.keys(sessionStorage)
      .filter(k => k.startsWith("ev.campaign.creation.v1.")).length), 0);
    const scoped = await vendor.request.get(base + "/api/commercial/campaigns");
    assert.equal((await scoped.json()).data.filter(c => c.id === id).length, 1);
    assert.equal(await v.locator("img").count(), 0);
    async function decision(panel, p, button, reason) {
      const form = panel
        .locator("form")
        .filter({ has: p.getByRole("button", { name: button, exact: true }) });
      await form.locator("[name=reason]").fill(reason);
      await form.getByRole("button", { name: button, exact: true }).click();
    }
    await decision(
      v,
      vendor,
      "Submit for review",
      "Reviewed synthetic vendor request",
    );
    await v.getByText("Awaiting review", { exact: true }).waitFor();
    const { p: admin, panel: a } = await login("admin");
    // Verify the real server-filtered review page, and reject a forged scope.
    const reviewFilters = a.locator("[data-campaign-filters]");
    await reviewFilters.locator("[name=status]").selectOption("submitted");
    assert.equal(await a.locator("[data-campaign-record]").count(), 0);
    const reviewFiltered = admin.waitForResponse(response =>
      response.url().includes("/api/commercial/campaigns?") &&
      new URL(response.url()).searchParams.get("status") === "submitted");
    await reviewFilters.getByRole("button", { name: "Apply campaign filters", exact: true }).click();
    const reviewFilteredResponse = await reviewFiltered;
    assert.equal(reviewFilteredResponse.status(), 200);
    const reviewFilteredData = await reviewFilteredResponse.json();
    assert.equal(reviewFilteredData.scope.status, "submitted");
    assert.ok(reviewFilteredData.data.every(c => c.status === "submitted"));
    await a.locator(`[data-campaign-record="${id}"]`).waitFor();
    const reviewBadScopePattern = /\/api\/commercial\/campaigns\?page=1&status=approved$/;
    await admin.route(reviewBadScopePattern, async route => {
      const response = await route.fetch();
      const data = await response.json();
      data.scope.status = "submitted";
      await route.fulfill({ response, json: data });
    });
    await reviewFilters.locator("[name=status]").selectOption("approved");
    await reviewFilters.getByRole("button", { name: "Apply campaign filters", exact: true }).click();
    await a.getByText("Campaign filter source cannot be verified.", { exact: true }).waitFor();
    assert.equal(await a.locator("[data-campaign-record]").count(), 0);
    await admin.unroute(reviewBadScopePattern);
    await reviewFilters.getByRole("button", { name: "Clear campaign filters", exact: true }).click();
    await a.locator(`[data-campaign-record="${id}"]`).waitFor();
    // A stale/forged private detail must not expose an approval form.
    const detailPattern = new RegExp("/api/commercial/campaigns/" + id + "\\?page=1$");
    await admin.route(detailPattern, async route => {
      const response = await route.fetch();
      const data = await response.json();
      data.campaign.control_revision = "999999";
      await route.fulfill({ response, json: data });
    });
    await a.locator(`[data-campaign-record="${id}"]`)
      .getByRole("button", { name: "Review campaign", exact: true }).click();
    await a.getByText("Campaign revision does not match history.", { exact: true }).waitFor();
    assert.equal(await a.getByRole("button", { name: "Approve sponsored campaign", exact: true }).count(), 0);
    await admin.unroute(detailPattern);
    await a.getByRole("button", { name: "Campaign list", exact: true }).click();
    await a
      .locator(`[data-campaign-record="${id}"]`)
      .getByRole("button", { name: "Review campaign", exact: true })
      .click();
    await decision(
      a,
      admin,
      "Approve sponsored campaign",
      "Approved synthetic sponsored creative",
    );
    await a.getByText("Approved", { exact: true }).waitFor();
    const signed = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/campaign-browser.php", "delivery", run, String(id)],
        { encoding: "utf8" },
      ),
    );
    const delivered = await fetch(
      base + "/api/internal/commercial/campaign-deliveries",
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Campaign-Timestamp": signed.timestamp,
          "X-Campaign-Signature": signed.signature,
        },
        body: signed.raw,
      },
    );
    assert.equal(delivered.status, 200);
    assert.equal((await delivered.json()).duplicate, false);
    const forged = await vendor.request.post(
      base + "/api/internal/commercial/campaign-deliveries",
      { data: JSON.parse(signed.raw) },
    );
    assert.equal(forged.status(), 401);
    // Reload and repeated recovery consume the ordinary protected-read budget.
    // Schedule a new phase without resetting limits or replaying any write.
    await waitForFreshWindow({ wait: ms => vendor.waitForTimeout(ms) });
    // Verify the exact response used by the UI and the rendered counters
    // separately. An API discrepancy and a rendering failure must not be
    // collapsed into a generic text-locator timeout.
    const refreshed = vendor.waitForResponse(r =>
      new URL(r.url()).pathname === "/api/commercial/campaigns" &&
      r.request().method() === "GET" &&
      new URL(r.url()).searchParams.get("page") === "1");
    await v.getByRole("button", { name: "Campaign list", exact: true }).click();
    const listResponse = await refreshed;
    assert.equal(listResponse.status(), 200, "Campaign list refresh must succeed");
    const listed = (await listResponse.json()).data.find(c => c.id === id);
    assert(listed, "Refreshed campaign list must include the exact campaign");
    assert.equal(listed.delivered_units, 1, "Signed delivery must appear in canonical list count");
    assert.equal(listed.budget_units, 3);
    assert.equal(listed.verified_server_clicks, 0);
    await vendor.waitForFunction(() => !document.querySelector("[data-commercial-campaigns]").inert);
    const record = v.locator(`[data-campaign-record="${id}"]`);
    assert.equal(await record.count(), 1, "Verified list must render the exact campaign");
    const deliveryCount = record.locator("[data-campaign-delivery-count]");
    assert.equal(await deliveryCount.count(), 1);
    assert(await deliveryCount.isVisible());
    assert.equal(await deliveryCount.textContent(), "1/3");
    const clickCount = record.locator("[data-campaign-click-count]");
    assert(await clickCount.isVisible());
    assert.equal(await clickCount.textContent(), "0");
    const badDeliveryPattern = new RegExp("/api/commercial/campaigns/" + id + "\\?page=1$");
    await vendor.route(badDeliveryPattern, async route => {
      const response = await route.fetch();
      const data = await response.json();
      assert.equal(data.deliveries.data.length, 1);
      data.deliveries.data[0].parent_delivery_id = data.deliveries.data[0].id;
      await route.fulfill({ response, json: data });
    });
    await record.getByRole("button", { name: "Review campaign", exact: true }).click();
    await v.getByText("Invalid campaign delivery history.", { exact: true }).waitFor();
    assert.equal(await v.getByText("Eligible for a sponsored placement", { exact: true }).count(), 0);
    await vendor.unroute(badDeliveryPattern);
    await v.getByRole("button", { name: "Campaign list", exact: true }).click();
    await v.locator(`[data-campaign-record="${id}"]`)
      .getByRole("button", { name: "Review campaign", exact: true }).click();
    await v
      .getByText("Eligible for a sponsored placement", { exact: true })
      .waitFor();
    const feed = await vendor.request.get(
      base +
        "/api/commercial/campaign-placements?country=AE&placement=station_detail",
    );
    const placed = (await feed.json()).placements.find(
      (r) => r.campaign_id === id,
    );
    assert(placed);
    assert.equal(placed.sponsored, true);
    assert.equal(placed.availability_verified, false);
    assert.equal(placed.capability_override, false);
    const { p: customer } = await login("customer");
    await customer.locator('a[href="#discover"]').click();
    const station = customer.locator(`[data-station-id="${f.station}"]`);
    const banner = customer.locator('[data-catalog-promotions="discovery_banner"]');
    await banner.getByText("Browser discovery banner " + run, { exact: true }).waitFor();
    const catalogOrder = await customer.locator("#stations [data-station-id]")
      .evaluateAll(cards => cards.map(card => Number(card.dataset.stationId)));
    assert(catalogOrder.indexOf(f.unrelated_station) < catalogOrder.indexOf(f.station));
    assert(catalogOrder.includes(f.unrelated_station));
    const promotions = station.locator("details").filter({ hasText: "Sponsored promotions" });
    await promotions.locator("summary").click();
    await promotions.getByText("Browser sponsored " + run + " <img src=x>", { exact: true }).waitFor();
    assert.equal(await promotions.locator("img").count(), 0);
    await customer.locator("#language").click();
    await banner.getByText("عرض اكتشاف " + run, { exact: true }).waitFor();
    await customer.setViewportSize({ width: 390, height: 844 });
    assert(await customer.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await station.getByText("حملة برعاية " + run, { exact: true }).waitFor();
    await station.getByText("العروض المموّلة", { exact: true }).click();
    // Customer catalog translation/refresh and moderation form a new phase.
    await waitForFreshWindow({ wait: ms => customer.waitForTimeout(ms) });
    await decision(
      a,
      admin,
      "Suspend sponsored campaign",
      "Moderator withdraws sponsored placement",
    );
    await a.locator('[data-campaign-state="suspended"]').waitFor();
    await station.getByText("العروض المموّلة", { exact: true }).click();
    await station.getByText("لا توجد عروض في هذه الصفحة.", { exact: true }).waitFor();
    assert.equal(await station.getByText("حملة برعاية " + run, { exact: true }).count(), 0);
    const filtered = customer.waitForResponse(response =>
      response.url().includes("/commercial/campaign-placements?") &&
      new URL(response.url()).searchParams.get("station_ids[]") === String(f.unrelated_station),
    );
    await customer.locator('#filters [name="q"]').fill(f.unrelated_station_name);
    await customer.locator("#filters").evaluate(form => form.requestSubmit());
    assert.equal((await filtered).status(), 200);
    await customer.locator(`[data-station-id="${f.unrelated_station}"]`).waitFor();
    await station.waitFor({ state: "detached" });
    await banner.waitFor({ state: "hidden" });
    await v.getByRole("button", { name: "Campaign list", exact: true }).click();
    await v
      .locator(`[data-campaign-record="${id}"]`)
      .getByRole("button", { name: "Review campaign", exact: true })
      .click();
    assert.equal(
      await v
        .getByRole("button", { name: "Resume campaign", exact: true })
        .count(),
      0,
    );
    for (const theme of ["light", "dark"])
      for (const width of [390, 768, 1440]) {
        await vendor.setViewportSize({ width, height: 1000 });
        await vendor.locator("#theme-choice").selectOption(theme);
        await v.screenshot({ path: `${dir}/${theme}-${width}.png` });
        assert(
          await vendor.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
      }
    await vendor.locator("#language").click();
    await vendor.waitForFunction(() => document.documentElement.dir === "rtl");
    for (const theme of ["light", "dark"]) {
      await vendor.locator("#theme-choice").selectOption(theme);
      await vendor.setViewportSize({ width: 390, height: 1000 });
      await v.screenshot({ path: `${dir}/ar-${theme}-390.png` });
      assert(
        await vendor.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
    }
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel campaign UI: paid-package and owned-station choices, immutable draft, vendor submission/admin approval, signed server delivery and forged-client denial, labeled sponsored feed without capability claims, moderation suspension, filtered customer station promotions and discovery banners, EN/AR RTL Light/Dark three widths. Synthetic package and publisher evidence only; no human impression or production ad delivery verification.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
