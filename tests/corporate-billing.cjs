// Real Laravel synthetic corporate allocation/collection browser contract. No real money.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
const fs = require("node:fs");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/corporate-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [],
    contexts = [];
  const screenshots = "artifacts/finance-corporate/screenshots";
  fs.mkdirSync(screenshots, { recursive: true });
  try {
    async function login(role) {
      const context = await browser.newContext();
      contexts.push(context);
      const p = await context.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`corporate-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      async function submitLogin() {
        const response = p.waitForResponse(
          (r) =>
            new URL(r.url()).pathname === "/api/login" &&
            r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        return response;
      }
      let loginResponse = await submitLogin();
      if (loginResponse.status() === 429) {
        // Retry only an explicitly rate-limited login, once, after the server's
        // bounded instruction. Unknown outcomes never trigger mutation retries.
        const delay = loginResponse.headers()["retry-after"];
        assert(
          /^\d+$/.test(delay || ""),
          "Numeric login Retry-After is required",
        );
        const seconds = Number(delay);
        assert(
          seconds >= 1 && seconds <= 60,
          "Login retry must be bounded to1..60 seconds",
        );
        await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
        loginResponse = await submitLogin();
      }
      assert(
        loginResponse.ok(),
        `Corporate login failed: ${loginResponse.status()}`,
      );
      await p.locator('a[href="#commercial-finance"]').click();
      const root = p.locator('[data-corporate-billing="true"]');
      await root
        .getByRole("button", { name: "Open corporate billing", exact: true })
        .click();
      const open = root.locator(
        'form[data-corporate-form="Open existing company"]',
      );
      await open.locator("[name=fleet_id]").fill(String(fixture.fleet_id));
      await open
        .getByRole("button", { name: "Open existing company", exact: true })
        .click();
      await root
        .getByRole("heading", { name: "Company agreements", exact: true })
        .waitFor();
      return { p, root };
    }
    const admin = await login("admin");
    const publish = admin.root.locator(
      'form[data-corporate-form="Publish corporate agreement"]',
    );
    await publish.locator("[name=legal_name]").fill("Synthetic company " + run);
    await publish
      .locator("[name=terms]")
      .fill("Synthetic company terms " + run);
    await publish.locator("[name=credit_limit_minor]").fill("100000");
    // Explicit UTC dates are entered by the administrator; current day is effective.
    const start = new Date(Date.now() - 86400000),
      end = new Date(Date.now() + 365 * 86400000);
    await publish
      .locator("[name=effective_from]")
      .fill(start.toISOString().slice(0, 16));
    await publish
      .locator("[name=expires_at]")
      .fill(end.toISOString().slice(0, 16));
    await publish
      .getByRole("button", { name: "Publish corporate agreement", exact: true })
      .click();
    await admin.root
      .getByRole("heading", { name: "Synthetic company " + run, exact: true })
      .waitFor();
    const owner = await login("owner");
    const consent = owner.root.locator(
      'form[data-corporate-form="Accept company agreement"]',
    );
    await consent.locator("[name=consent]").check();
    await consent
      .getByRole("button", { name: "Accept company agreement", exact: true })
      .click();
    await owner.root
      .getByText("Accepted company terms", { exact: true })
      .waitFor();
    const driver = await login("driver");
    await driver.root
      .getByRole("button", {
        name: "Allocate my charging invoice",
        exact: true,
      })
      .click();
    const claim = driver.root.locator(
      'form[data-corporate-form="Submit corporate claim"]',
    );
    await claim.locator("[name=invoice_id]").fill(String(fixture.invoice_id));
    await claim
      .locator("[name=cost_center_id]")
      .selectOption(String(fixture.cost_center_id));
    await claim
      .getByRole("button", { name: "Submit corporate claim", exact: true })
      .click();
    await driver.root
      .getByText("Awaiting company approval", { exact: true })
      .waitFor();
    await owner.root
      .getByRole("button", { name: "View corporate claims", exact: true })
      .click();
    const review = owner.root.locator(
      'form[data-corporate-form="Review corporate claim"]',
    );
    await review.locator("[name=decision]").selectOption("approved");
    await review
      .locator("[name=reason]")
      .fill("Authorized synthetic business charge");
    await review
      .getByRole("button", { name: "Review corporate claim", exact: true })
      .click();
    await owner.root.getByText("Claim approved", { exact: true }).waitFor();
    const issue = owner.root.locator(
      'form[data-corporate-form="Issue company statement"]',
    );
    await issue.locator("input[type=checkbox]").check();
    await issue
      .getByRole("button", { name: "Issue company statement", exact: true })
      .click();
    await owner.root
      .getByRole("button", {
        name: "View statement reconciliation",
        exact: true,
      })
      .click();
    await owner.root
      .getByText(fixture.invoice_number, { exact: true })
      .waitFor();
    await owner.root
      .getByRole("button", {
        name: "Record simulated company payment",
        exact: true,
      })
      .click();
    await owner.root
      .getByText("Simulated payment recorded", { exact: true })
      .waitFor();
    const downloadPromise = owner.p.waitForEvent("download");
    await owner.root
      .getByRole("link", {
        name: "Download company statement CSV",
        exact: true,
      })
      .click();
    const download = await downloadPromise;
    const stream = await download.createReadStream();
    let csv = "";
    for await (const chunk of stream) csv += chunk.toString();
    assert(csv.includes("ENGINEERING"));
    assert(csv.includes(fixture.invoice_number));
    assert(csv.includes("1050"));
    const downloadHref = await owner.root
      .getByRole("link", {
        name: "Download company statement CSV",
        exact: true,
      })
      .getAttribute("href");
    const statementId = downloadHref.match(/statements\/(\d+)/)[1];
    const response = await driver.p.request.get(
      base + "/api/commercial/corporate/statements/" + statementId,
      { headers: { Accept: "application/json" } },
    );
    assert.equal(response.status(), 404);
    for (const theme of ["light", "dark"])
      for (const width of [390, 768, 1440]) {
        await owner.p.setViewportSize({ width, height: 1000 });
        await owner.p.locator("#theme-choice").selectOption(theme);
        await owner.root.scrollIntoViewIfNeeded();
        await owner.p.screenshot({
          path: `${screenshots}/${theme}-${width}.png`,
          fullPage: true,
        });
        await owner.root.screenshot({
          path: `${screenshots}/${theme}-${width}-panel.png`,
        });
        assert.equal(
          await owner.p.evaluate(
            () => document.documentElement.scrollWidth > innerWidth,
          ),
          false,
        );
      }
    await owner.p.setViewportSize({ width: 390, height: 1000 });
    await owner.p.locator("#language").click();
    await owner.p.waitForFunction(() => document.documentElement.dir === "rtl");
    for (const theme of ["light", "dark"]) {
      await owner.p.locator("#theme-choice").selectOption(theme);
      await owner.p.screenshot({
        path: `${screenshots}/${theme}-ar-390.png`,
        fullPage: true,
      });
      await owner.root.screenshot({
        path: `${screenshots}/${theme}-ar-390-panel.png`,
      });
      assert.equal(
        await owner.p.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
      );
    }
    assert.equal(errors.length, 0, errors.join("\n"));
    console.log(
      "PASS corporate browser: admin terms → owner consent → driver own-invoice allocation → approval → immutable consolidation → canonical mock receipt → private CSV; foreign-driver company access denied; EN/AR RTL Light/Dark390/768/1440.",
    );
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
