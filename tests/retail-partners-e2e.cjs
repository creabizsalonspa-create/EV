const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
const fs = require("node:fs");
function verifyRewardHistoryParser() {
  const source = fs.readFileSync("services/business-api/public/retail-partners.js", "utf8");
  const start = source.indexOf("    function validateRewardHistory(");
  const end = source.indexOf("    async function loadRewardReviews(", start);
  assert(start >= 0 && end > start);
  const validate = new Function(source.slice(start, end) + "\nreturn validateRewardHistory;")();
  const row = {id: 3, partner_id: 2, reward_id: 8, partner_version_id: 4,
    partner_revision: 2, proposed_by: 10, revision: 2, decision: "approve",
    decision_actor_id: 11, reason: "Independent review", fulfillment_enabled: false};
  const at = "2026-11-27T00:00:00Z";
  const claim = {id: 5, review_id: 3, redemption_id: 6, approval_event_id: 7,
    created_at: at, status: "pending", points_cost: 0, source: "RETAIL_PARTNER_REWARD",
    evidence: "SANDBOX_MANUAL_NONCASH", cash_value: false, external_fulfillment_verified: false};
  const page = data => ({data, current_page: 1, last_page: 1, per_page: 25, total: data.length});
  const good = {review: {...row}, events: page([
    {revision: 2, decision: "approve", reason: row.reason, actor_id: 11, created_at: at},
    {revision: 1, decision: "propose", reason: "Original proposal", actor_id: 10, created_at: at},
  ]), claims: page([claim])};
  const run = mutate => {
    const data = JSON.parse(JSON.stringify(good));
    mutate(data);
    return validate(data, row, {id: 2}, 1, 1);
  };
  assert.equal(run(() => {}).claims.total, 1);
  assert.equal(run(d => {d.claims = page([]);}).claims.total, 0);
  assert.equal(run(d => {
    d.review = {...d.review, revision: 3, decision: "revoke", reason: "Revoke approval"};
    d.events = page([{revision: 3, decision: "revoke", reason: "Revoke approval",
      actor_id: 11, created_at: at}, ...d.events.data]);
  }).review.revision, 3);
  const bad = [
    d => {d.events.data[0].decision = "forged";},
    d => {d.events.data[0].actor_id = 10;},
    d => {d.events.data.reverse();},
    d => {d.events = page([]);},
    d => {d.claims = page([{...claim, id: 6}, claim]);},
    d => {d.claims.last_page = 999;},
    d => {d.claims.data[0].user_id = 123;},
    d => {d.claims.data[0].external_fulfillment_verified = true;},
    d => {d.claims = page([{...claim, id: 6, redemption_id: 9, approval_event_id: 99}, claim]);},
    d => {d.review.reward_id = 100;},
    d => {d.review.fulfillment_enabled = true;},
    d => {d.review.revision = 1;},
    d => {d.claims.data[0].created_at = "invalid";},
    d => {d.claims.total = 2;},
  ];
  for (const mutate of bad) assert.throws(() => run(mutate), /Invalid partner claim evidence/);
  console.log("PASS reward history: 3 valid and " + bad.length + " rejected cases");
}

function verifyCustomerRewardPageParser() {
  const source = fs.readFileSync("services/business-api/public/loyalty.js", "utf8");
  const start = source.indexOf("function validLoyaltyClock(");
  const end = source.indexOf("window.mountLoyalty", start);
  assert(start >= 0 && end > start);
  const window = {};
  new Function("window", fs.readFileSync("services/business-api/public/station-time.js","utf8"))(window);
  const verify = new Function("window", source.slice(start, end) + "\nreturn verifyPartnerRewardPage;")(window);
  const evidence = {source:"RETAIL_PARTNER_REWARD", evidence:"SANDBOX_MANUAL_NONCASH",
    external_fulfillment_verified:false, cash_value:false, points_cost:0};
  const offer = {...evidence, review_id:9, name:"Offer", name_ar:"Offer", partner_name:"Partner",
    partner_name_ar:"Partner", description:"Terms", description_ar:"Terms", ends_at:"2026-12-01T04:00:00+04:00"};
  const claim = {...evidence, review_id:9, id:14, redemption_id:18, status:"pending", created_at:"2026-11-27 09:00:00"};
  const page = data => ({data, total:data.length, per_page:25, current_page:1, last_page:1});
  assert.equal(verify(page([offer]), 1, "offers").total, 1);
  assert.equal(verify(page([claim]), 1, "claims").total, 1);
  assert.equal(verify({...page([]),current_page:2,total:1}, 2, "claims").data.length, 0);
  const bad = [
    r=>r.current_page=2,r=>r.per_page=26,r=>r.total=2,r=>r.last_page=99,
    r=>r.data[0].id=0,r=>r.data[0].review_id=0,r=>r.data[0].redemption_id=0,
    r=>r.data[0].status="paid",r=>r.data[0].cash_value=true,
    r=>{r.data.push({...claim});r.total=2;},
    r=>{r.data.unshift({...claim,id:15});r.total=2;},
    r=>{r.data.push({...claim,id:15,redemption_id:19});r.total=2;},
  ];
  for (const change of bad) {
    const data=page([{...claim}]); change(data);
    assert.throws(()=>verify(data,1,"claims"),/Invalid partner reward page/);
  }
  assert.throws(()=>verify(page([{...offer,ends_at:null}]),1,"offers"),/Invalid partner reward page/);
  assert.throws(()=>verify(page([offer,offer]),1,"offers"),/Invalid partner reward page/);
  const invalidClocks = ["2026-02-30T09:00:00Z", "2026-11-27", "2026-11-27T09:00:00", "2026-11-27T09:00:00+14:01", " 2026-11-27T09:00:00Z"];
  for (const invalid of invalidClocks) {
    assert.throws(()=>verify(page([{...offer,ends_at:invalid}]),1,"offers"),/Invalid partner reward page/);
    assert.throws(()=>verify(page([{...claim,created_at:invalid}]),1,"claims"),/Invalid partner reward page/);
  }
  console.log("PASS customer reward page: 3 valid and "+(bad.length+2+2*invalidClocks.length)+" rejected cases");
}

(async () => {
  verifyRewardHistoryParser();
  verifyCustomerRewardPageParser();
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/retail-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(fixture.fixture_only);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  const errors = [];
  let checks = 0;
  try {
    async function login(role) {
      const context = await browser.newContext();
      const page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(base);
      const token = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute("content");
      const response = await context.request.post(base + "/api/login", {
        data: { email: `retail-${role}-${run}@example.test`, password },
        headers: { "X-CSRF-TOKEN": token, Accept: "application/json" },
      });
      assert.equal(response.status(), 200);
      checks++;
      await page.goto(base + "/admin/retail-partners");
      return { page, context };
    }
    const author = await login("author");
    const a = author.page;
    await a.locator("#workspace").waitFor({ state: "visible" });
    await a.locator("#editor summary").click();
    const f = a.locator("#partner-form");
    const values = {
      reference: "RETAIL-" + run.toUpperCase(),
      legal_name: "Synthetic retail entity",
      display_name: "<img src=x onerror=alert(1)>",
      display_name_ar: "متجر تجريبي",
      country: "AE",
      website: "https://example.test",
      effective_from: new Date(Date.now() - 86400000)
        .toISOString()
        .slice(0, 16),
      expires_at: new Date(Date.now() + 86400000 * 30)
        .toISOString()
        .slice(0, 16),
      terms: "Synthetic reviewed terms with no money movement.",
      reason: "Initial synthetic registration",
    };
    for (const [k, v] of Object.entries(values))
      await f.locator(`[name="${k}"]`).fill(v);
    // The real backend commits, but the first response is lost. The editor
    // must retain its request identity so an explicit retry cannot duplicate it.
    let loseReply = true;
    let createdPartnerId = null;
    await a.route("**/api/admin/retail-partners", async (route) => {
      if (route.request().method() === "POST" && loseReply) {
        loseReply = false;
        const response = await route.fetch();
        assert.equal(response.status(), 201);
        createdPartnerId = (await response.json()).partner.id;
        assert(Number.isSafeInteger(createdPartnerId) && createdPartnerId > 0);
        checks++;
        await route.abort("failed");
      } else await route.continue();
    });
    await f
      .getByRole("button", { name: "Save immutable draft", exact: true })
      .click();
    await a.locator("#status").filter({ hasText: "Request failed." }).waitFor();
    await f
      .getByRole("button", { name: "Save immutable draft", exact: true })
      .click();

    await a
      .getByRole("button", { name: "Review partner", exact: true })
      .first()
      .waitFor();
    checks++;
    await a
      .getByRole("button", { name: "Review partner", exact: true })
      .first()
      .click();
    await a.locator("#detail").waitFor({ state: "visible" });
    assert.equal(await a.locator("#detail img").count(), 0);
    checks++;
    await a
      .locator("#detail textarea")
      .fill("Author cannot independently approve");
    await a.getByRole("button", { name: "Approve", exact: true }).click();
    await a
      .locator("#status")
      .filter({ hasText: "Record or request changed" })
      .waitFor();
    checks++;
    const reviewer = await login("reviewer");
    const b = reviewer.page;
    await b
      .getByRole("button", { name: "Review partner", exact: true })
      .first()
      .click();
    await b.locator("#detail textarea").fill("Original terms require revision");
    await b.getByRole("button", { name: "Reject draft", exact: true }).click();
    await b
      .locator("#detail")
      .getByText(/^Rejected ·/)
      .waitFor();
    await b
      .locator("#retail-partner-status-" + createdPartnerId)
      .filter({ hasText: /^Rejected$/ })
      .waitFor();
    assert.equal(
      await b.getByRole("button", { name: "Approve", exact: true }).count(),
      0,
    );
    await b
      .locator("#detail summary")
      .filter({ hasText: "Reject draft" })
      .click();
    await b
      .locator("#detail")
      .getByText("Original terms require revision")
      .waitFor();
    checks++;

    await a.reload();
    await a
      .getByRole("button", { name: "Review partner", exact: true })
      .first()
      .click();
    await a.getByRole("button", { name: "Revise terms", exact: true }).click();
    await a
      .locator('#partner-form [name="terms"]')
      .fill("Revised synthetic terms after independent rejection.");
    await a
      .locator('#partner-form [name="reason"]')
      .fill("Address independent review");
    const revised = a.waitForResponse(
      (response) =>
        response.url().endsWith("/revise") && response.status() === 200,
    );
    await a
      .getByRole("button", { name: "Save immutable draft", exact: true })
      .click();
    await revised;
    await b
      .locator("#detail")
      .getByRole("button", { name: "Refresh records", exact: true })
      .click();
    await b.getByRole("button", { name: "Approve", exact: true }).waitFor();
    checks++;
    await b.locator("#detail textarea").fill("Independent synthetic review");
    await b.getByRole("button", { name: "Approve", exact: true }).click();
    await b
      .locator("#detail")
      .getByText("Currently effective", { exact: true })
      .waitFor();
    await b
      .locator("#retail-partner-status-" + createdPartnerId)
      .filter({ hasText: /^Approved$/ })
      .waitFor();
    checks++;
    const rewards = (page) => page.locator("[data-reward-reviews]");
    const openRewards = (page) => page.getByRole("button", { name: "Partner reward reviews", exact: true }).click();
    await openRewards(b);
    await rewards(b).getByLabel("Existing reward ID").fill(String(fixture.reward_id));
    await rewards(b).locator("textarea").fill("Review a synthetic reward");
    await rewards(b).getByRole("button", { name: "Propose reward", exact: true }).click();
    await rewards(b).locator("[data-reward-review]").waitFor();
    await a.locator("#partners article").filter({
      has: a.locator("#retail-partner-status-" + createdPartnerId),
    }).getByRole("button", { name: "Review partner", exact: true }).click();
    await openRewards(a);
    const reviewCard = rewards(a).locator("[data-reward-review]");
    await reviewCard.locator("textarea").fill("Independent reward review");
    await reviewCard.getByRole("button", { name: "Approve reward proposal", exact: true }).click();
    await reviewCard.getByRole("button", { name: "Revoke reward approval", exact: true }).waitFor();
    await reviewCard.getByRole("button", { name: "Reward decision history", exact: true }).click();
    await reviewCard.getByText(/^2 · .* · Independent reward review$/).waitFor();
    await rewards(a).getByText(/Partner fulfillment is disabled/).waitFor();
    checks++;
    const customer = await login("customer");
    const c = customer.page;
    assert.equal(await c.locator("#partner-form").count(), 0);
    await c.goto(base);
    await c.locator('a[href="#commercial-finance"]').click();
    const customerRewards = c.locator("[data-partner-reward-panel]");
    await customerRewards.getByRole("button", { name: "Browse partner rewards", exact: true }).click();
    const offer = customerRewards.locator("article").filter({ hasText: "Synthetic partner reward" });
    let receipt;
    const claimPath = "**/api/commercial/partner-rewards/offers/*/claim";
    await c.route(claimPath, async (route) => {
      const response = await route.fetch();
      assert.equal(response.status(), 201, await response.text());
      receipt = (await response.json()).claim;
      await route.abort("failed");
    }, { times: 1 });
    await offer.getByRole("button", { name: "Claim partner reward", exact: true }).click();
    await customerRewards.getByText(/Claim could not be confirmed/).waitFor();
    const reloadedAt = Date.now();
    await c.reload();
    await c.locator('a[href="#commercial-finance"]').click();
    await c.waitForTimeout(Math.max(0, 61000 - (Date.now() - reloadedAt)));
    // The first claim actually committed. Resolve it via the new server
    // cancellation control instead of discarding the saved key locally.
    await customerRewards.getByRole("button", { name: "Check partner reward receipt", exact: true }).waitFor();
    c.once("dialog", (dialog) => dialog.accept());
    const cancelled = c.waitForResponse((response) =>
      response.url().endsWith("/commercial/partner-rewards/claim-intents/cancel") &&
      response.request().method() === "POST");
    await customerRewards.getByRole("button", { name: "Cancel saved partner reward claim", exact: true }).click();
    const resolution = await cancelled;
    assert.equal(resolution.status(), 200, await resolution.text());
    const resolved = await resolution.json();
    assert.equal(resolved.state, "accepted");
    assert.equal(resolved.claim.id, receipt.id);
    await customerRewards.getByText("#" + receipt.id, { exact: true }).waitFor();
    await customerRewards.getByRole("button", { name: "Retry partner reward claim", exact: true }).waitFor({ state: "hidden" });
    await customerRewards.getByRole("button", { name: "Cancel saved partner reward claim", exact: true }).waitFor({ state: "hidden" });
    await reviewCard.getByRole("button", { name: "Reward decision history", exact: true }).click();
    const claimHistory = reviewCard.locator("[data-partner-claim-history]");
    await claimHistory.getByText(`Claim #${receipt.id} · Awaiting manual review`, { exact: true }).waitFor();
    await claimHistory.getByText(/external fulfillment is not verified/).waitFor();
    await claimHistory.getByText(new RegExp(`^Redemption #${receipt.redemption_id} · Approval event #[0-9]+$`)).waitFor();
    await c.locator("#language").click();
    await customerRewards.getByText("بانتظار التسليم اليدوي", { exact: true }).waitFor();
    checks++;
    await b.locator("#detail").getByRole("button", { name: "Refresh records", exact: true }).click();
    await b.getByRole("button", { name: "Suspend", exact: true }).waitFor();
    await b.locator("#detail textarea").fill("Suspend synthetic partner");
    await b.getByRole("button", { name: "Suspend", exact: true }).click();
    await b
      .locator("#detail")
      .getByText(/^Suspended ·/)
      .waitFor();
    checks++;
    await b.getByRole("button", { name: "Revise terms", exact: true }).click();
    await b
      .locator('#partner-form [name="terms"]')
      .fill("Second immutable synthetic terms; no monetary authority.");
    await b
      .locator('#partner-form [name="reason"]')
      .fill("Material revision to terms");
    await b
      .getByRole("button", { name: "Save immutable draft", exact: true })
      .click();
    await b
      .getByRole("button", { name: "Review partner", exact: true })
      .first()
      .waitFor();
    checks++;
    await b.locator("#language").selectOption("ar");
    await b
      .getByRole("button", { name: "مراجعة الشريك", exact: true })
      .first()
      .waitFor();
    assert.equal(await b.locator("html").getAttribute("dir"), "rtl");
    checks++;
    await b.locator("#theme-choice").selectOption("dark");
    assert.equal(await b.locator("html").getAttribute("data-theme"), "dark");
    checks++;
    await b.reload();
    await b
      .getByRole("button", { name: "مراجعة الشريك", exact: true })
      .first()
      .waitFor();
    assert.equal(await b.locator("html").getAttribute("dir"), "rtl");
    checks++;
    await b
      .getByRole("button", { name: "مراجعة الشريك", exact: true })
      .first()
      .click();
    await b
      .locator("#detail")
      .getByText("Second immutable synthetic terms; no monetary authority.", {
        exact: true,
      })
      .first()
      .waitFor();
    checks++;
    await b.setViewportSize({ width: 390, height: 844 });
    assert(
      await b.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    checks++;
    fs.mkdirSync("artifacts/operational-browser/retail-partners", {
      recursive: true,
    });
    await b.screenshot({
      path: "artifacts/operational-browser/retail-partners/ar-dark-mobile.png",
      fullPage: true,
    });
    // Hold an actual Laravel private response across backgrounding. It must
    // never restore records after the lifecycle generation has been cleared.
    let held, release;
    const received = new Promise((resolve) => {
      held = resolve;
    });
    const barrier = new Promise((resolve) => {
      release = resolve;
    });
    let holdOne = true;
    await b.route("**/api/admin/retail-partners?**", async (route) => {
      if (!holdOne) return route.continue();
      holdOne = false;
      const response = await route.fetch();
      assert.equal(response.status(), 200);
      held();
      await barrier;
      await route.fulfill({ response });
    });
    await b.locator("#search-form button").click();
    await received;
    await b.evaluate(() => {
      Object.defineProperty(document, "hidden", {
        configurable: true,
        value: true,
      });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    assert.equal(await b.locator("#workspace").isVisible(), false);
    assert.equal(await b.locator("#partners article").count(), 0);
    checks++;
    const late = b.waitForResponse((r) =>
      r.url().includes("/api/admin/retail-partners?"),
    );
    release();
    await late;
    await b.evaluate(
      () =>
        new Promise((resolve) =>
          requestAnimationFrame(() => requestAnimationFrame(resolve)),
        ),
    );
    assert.equal(await b.locator("#workspace").isVisible(), false);
    checks++;
    await b.evaluate(() => {
      Object.defineProperty(document, "hidden", {
        configurable: true,
        value: false,
      });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await b
      .getByRole("button", { name: "مراجعة الشريك", exact: true })
      .first()
      .waitFor();
    checks++;
    const csrf = await b
      .locator('meta[name="csrf-token"]')
      .getAttribute("content");
    const logout = await reviewer.context.request.post(base + "/api/logout", {
      headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
    });
    assert.equal(logout.status(), 204);
    await b.locator("#search-form button").click();
    await b.locator("#workspace").waitFor({ state: "hidden" });
    assert.equal(await b.locator("#partners article").count(), 0);
    checks++;
    checks++;
    assert.deepEqual(errors, []);
    console.log(
      `Retail actual-Laravel browser: ${checks} checks passed (EN/AR, independent rejection and approval, suspend/revise, immutable terms, XSS, themes, responsive, role denial).`,
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
