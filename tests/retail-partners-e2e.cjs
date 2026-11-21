const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
const fs = require("node:fs");
(async () => {
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
    await a.route("**/api/admin/retail-partners", async (route) => {
      if (route.request().method() === "POST" && loseReply) {
        loseReply = false;
        const response = await route.fetch();
        assert.equal(response.status(), 201);
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
    assert.equal(await b.getByRole("button", { name: "Approve", exact: true }).count(), 0);
    await b.locator("#detail summary").filter({ hasText: "Reject draft" }).click();
    await b.locator("#detail").getByText("Original terms require revision").waitFor();
    checks++;

    await a.reload();
    await a.getByRole("button", { name: "Review partner", exact: true }).first().click();
    await a.getByRole("button", { name: "Revise terms", exact: true }).click();
    await a.locator('#partner-form [name="terms"]').fill("Revised synthetic terms after independent rejection.");
    await a.locator('#partner-form [name="reason"]').fill("Address independent review");
    const revised = a.waitForResponse(
      (response) => response.url().endsWith("/revise") && response.status() === 200,
    );
    await a.getByRole("button", { name: "Save immutable draft", exact: true }).click();
    await revised;
    await b.locator("#detail").getByRole("button", { name: "Refresh records", exact: true }).click();
    await b.getByRole("button", { name: "Approve", exact: true }).waitFor();
    checks++;
    await b.locator("#detail textarea").fill("Independent synthetic review");
    await b.getByRole("button", { name: "Approve", exact: true }).click();
    await b
      .locator("#detail")
      .getByText("Currently effective", { exact: true })
      .waitFor();
    checks++;
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
    const customer = await login("customer");
    assert.equal(await customer.page.locator("#partner-form").count(), 0);
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
