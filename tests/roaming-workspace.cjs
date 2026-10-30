// Real Laravel synthetic OCPI records. No external operator requests are made.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = (action) =>
    execFileSync("./scripts/php", [
      "tests/Fixtures/roaming-workspace.php",
      action,
      run,
      ...(action === "seed" ? [password] : []),
    ]);
  fixture("seed");
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  try {
    const context = await browser.newContext();
    context.setDefaultTimeout(15000);
    const page = await context.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    let observedLogin429 = false;
    async function login() {
      for (let attempt = 0; attempt < 2; attempt++) {
        const ready = page.waitForResponse(
          (response) =>
            new URL(response.url()).pathname === "/api/login" &&
            response.request().method() === "POST",
        );
        await page.locator("button[value=login]").click();
        const response = await ready;
        if (response.status() === 429 && attempt === 0) {
          observedLogin429 = true;
          const header = response.headers()["retry-after"];
          assert(
            /^\d+$/.test(header || ""),
            "Login throttle must supply numeric Retry-After.",
          );
          const seconds = Number(header);
          assert(
            seconds >= 0 && seconds <= 60,
            "Login retry must remain bounded.",
          );
          console.log(
            "Roaming QA: explicit login 429; waiting for the existing authentication budget.",
          );
          await page.waitForTimeout((seconds + 1) * 1000);
          continue;
        }
        assert.equal(
          response.status(),
          200,
          "Login failed; no transport/503 or unbounded retry is permitted.",
        );
        return;
      }
    }

    await page.goto(base);
    const pub = page.locator("#roaming-discovery"),
      ops = page.locator("#roaming-operations");
    await pub
      .getByRole("heading", { name: "Partner locations", exact: true })
      .waitFor();
    await pub
      .getByText("Live availability is unknown.", { exact: true })
      .first()
      .waitFor();
    assert.equal(await pub.locator("img").count(), 0);
    assert.equal(
      await pub.getByRole("button", { name: /reserve|start|pay/i }).count(),
      0,
    );
    await pub.getByRole("button", { name: "Next", exact: true }).click();
    await pub.getByText("Page 2 of 2 · 26 records", { exact: true }).waitFor();
    if (process.env.EV_ROAMING_VERIFY_RATE_LIMIT === "1") {
      const token = await page
        .locator("meta[name=csrf-token]")
        .getAttribute("content");
      for (let i = 0; i < 10; i++) {
        const response = await context.request.post(base + "/api/login", {
          headers: { Accept: "application/json", "X-CSRF-TOKEN": token },
          data: {
            email: `synthetic-throttle-${run}@example.test`,
            password: "synthetic-invalid-password",
          },
        });
        assert(
          [422, 429].includes(response.status()),
          "Only rejected synthetic logins may consume the test budget.",
        );
        if (response.status() === 429) break;
      }
    }
    await page
      .locator("#account [name=email]")
      .fill(`roaming-owner-${run}@example.test`);
    await page.locator("#account [name=password]").fill(password);
    await login();
    if (process.env.EV_ROAMING_VERIFY_RATE_LIMIT === "1")
      assert(
        observedLogin429,
        "The real authentication limiter must reject excess requests.",
      );
    await ops
      .getByRole("heading", { name: `Synthetic owner ${run}`, exact: true })
      .waitFor();
    assert.equal(
      await ops.getByText(`Synthetic other ${run}`, { exact: true }).count(),
      0,
    );
    assert.equal(
      await ops
        .getByRole("button", { name: "Revoke authorization", exact: true })
        .count(),
      0,
    );
    let failRecordLoad = true;
    await page.route(
      "**/api/ocpi/providers/*/records/locations?*",
      async (route) => {
        if (failRecordLoad) {
          failRecordLoad = false;
          await route.fulfill({
            status: 503,
            contentType: "application/json",
            body: '{"message":"Synthetic temporary outage"}',
          });
        } else await route.continue();
      },
    );
    await ops.getByRole("button", { name: "Locations", exact: true }).click();
    const detail = ops.locator("[data-roaming-detail]");
    await detail
      .getByText("Unable to load partner data. Please retry.", { exact: true })
      .waitFor();
    await detail.getByRole("button", { name: "Retry", exact: true }).click();

    await detail
      .getByText("Page 1 of 2 · 26 records", { exact: true })
      .waitFor();
    await detail.getByRole("button", { name: "Next", exact: true }).click();
    await detail
      .getByText("Page 2 of 2 · 26 records", { exact: true })
      .waitFor();
    await page.locator("#language").click();
    await pub
      .getByRole("heading", { name: "مواقع شبكات التجوال", exact: true })
      .waitFor();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    assert.equal(
      await ops.getByText(`Synthetic owner ${run}`, { exact: true }).count(),
      1,
    );
    await page.setViewportSize({ width: 390, height: 844 });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "phone horizontal overflow",
    );
    await page.locator("#logout").click();
    await page.waitForFunction(
      () => document.querySelector("#roaming-operations").hidden,
    );
    assert.equal(await ops.textContent(), "");
    await page.locator("#language").click();
    await page
      .locator("#account [name=email]")
      .fill(`roaming-admin-${run}@example.test`);
    await page.locator("#account [name=password]").fill(password);
    await login();
    await ops.getByText(`Synthetic owner ${run}`, { exact: true }).waitFor();
    const card = ops
      .locator("article")
      .filter({
        has: page.getByText(`Synthetic owner ${run}`, { exact: true }),
      })
      .first();
    page.on("dialog", (dialog) => dialog.accept());
    await card
      .getByRole("button", { name: "Revoke authorization", exact: true })
      .click();
    await card
      .getByText("Connection authorization revoked.", { exact: true })
      .waitFor();
    await pub
      .getByText("No published partner locations.", { exact: true })
      .waitFor();
    assert.deepEqual(errors, []);
    console.log(
      "PASS: real Laravel roaming pagination, failed-load recovery, owner isolation, revocation, safe text, Arabic RTL, phone layout and logout cleanup.",
    );
  } finally {
    await browser.close();
    fixture("clean");
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
