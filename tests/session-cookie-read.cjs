// Actual loopback session/cookie contract. Never replay charging or financial writes.
const assert = require("node:assert/strict");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { execFileSync } = require("node:child_process");
const fs = require("node:fs");
async function bounded(promise, label) {
  let timer;
  try {
    return await Promise.race([
      promise,
      new Promise(
        (_, reject) =>
          (timer = setTimeout(
            () => reject(Error("Session regression deadline: " + label)),
            15000,
          )),
      ),
    ]);
  } finally {
    clearTimeout(timer);
  }
}
(async () => {
  const cookieName =
    process.env.EV_TEST_SESSION_COOKIE ||
    execFileSync(
      "./scripts/php",
      [
        "-r",
        'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if (!app()->environment(["local","testing"])) exit(2); echo config("session.cookie");',
      ],
      { encoding: "utf8" },
    ).trim();
  assert(
    /^[A-Za-z0-9_.-]{1,100}$/.test(cookieName),
    "Invalid test cookie name",
  );
  const local = {
    base: process.env.EV_AUTH_TEST_URL || "http://127.0.0.1:8000",
  };
  const a = await new BusinessClient(local).register("customer"),
    b = await new BusinessClient(local).register("customer");
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
  });
  let release;
  try {
    const page = await browser.newPage();
    page.setDefaultTimeout(15000);
    const signIn = async (account) => {
      await page
        .locator("#account [name=email]")
        .fill(account.testCredentials.email);
      await page
        .locator("#account [name=password]")
        .fill(account.testCredentials.password);
      await page.locator("button[value=login]").click();
      await page.locator("#account").waitFor({ state: "hidden" });
      await page.locator("#notice").filter({ hasText: "Signed in." }).waitFor();
    };
    await page.goto(a.base, { waitUntil: "networkidle" });
    await signIn(a);
    await page.waitForLoadState("networkidle");
    let seen,
      finished,
      intercept = true,
      sessionCookies = 0;
    const ready = new Promise((r) => (seen = r)),
      done = new Promise((r) => (finished = r)),
      held = new Promise((r) => (release = r));
    await page.route("**/api/vehicles", async (route) => {
      if (!intercept || route.request().method() !== "GET")
        return route.continue();
      intercept = false;
      const response = await route.fetch();
      assert.equal(
        response.status(),
        200,
        "Owned original read must really succeed",
      );
      sessionCookies = (await response.headersArray()).filter(
        (h) =>
          h.name.toLowerCase() === "set-cookie" &&
          h.value.split("=", 1)[0] === cookieName,
      ).length;
      seen();
      await held;
      await route.fulfill({ response });
      finished();
    });
    await page.evaluate(() => {
      window.pendingOldSessionRead = fetch("/api/vehicles", {
        headers: { Accept: "application/json" },
      });
    });
    await bounded(ready, "original authenticated read");
    await page.locator("#logout").click();
    await page.locator("#account [name=email]").waitFor();
    await signIn(b);
    const identity = () =>
      page.evaluate(async () => {
        const r = await fetch("/api/me", {
          headers: { Accept: "application/json" },
        });
        return { status: r.status, body: await r.json() };
      });
    const before = await identity();
    assert.equal(before.status, 200);
    assert.equal(before.body.id, b.user.id);
    release();
    await bounded(done, "delayed read release");
    await page.evaluate(() => window.pendingOldSessionRead);
    const after = await identity();
    assert.equal(
      after.status,
      200,
      "Old read must not replace the later login cookie",
    );
    assert.equal(after.body.id, b.user.id);
    assert.equal(
      sessionCookies,
      0,
      "Stable private GET must not reissue its old session cookie",
    );
    await page.locator("#logout").click();
    await page.locator("#account [name=email]").waitFor();
    fs.mkdirSync("artifacts/session-cookie-read", { recursive: true });
    fs.writeFileSync(
      "artifacts/session-cookie-read/result.json",
      JSON.stringify(
        {
          old_read_http: 200,
          old_session_cookies: sessionCookies,
          new_actor_before: true,
          new_actor_after: true,
          after_http: 200,
          charging_or_financial_mutations: false,
        },
        null,
        2,
      ) + "\n",
      { mode: 0o600 },
    );
  } finally {
    if (release) release();
    await browser.close();
  }
  console.log(
    "PASS real delayed private GET cannot overwrite a later account login; normal cookie rotation and logout retained, no charging/finance mutation.",
  );
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
