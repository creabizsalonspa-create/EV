"use strict";
const assert = require("node:assert/strict");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const { chromium } = require("playwright");
(async () => {
  const base = new URL(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
  assert(
    base.protocol === "http:" &&
      ["localhost", "127.0.0.1"].includes(base.hostname) &&
      !base.username &&
      !base.password &&
      base.pathname === "/" &&
      !base.search &&
      !base.hash,
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  let checks = 0;
  try {
    for (const lang of ["en", "ar"]) {
      const run = randomBytes(4).toString("hex"),
        password = randomBytes(24).toString("hex") + "aA9!";
      const fixture = (value) =>
        JSON.parse(
          execFileSync(
            process.env.EV_TEST_PHP_RUNNER || "./scripts/php",
            ["tests/Fixtures/native-coupons.php", run, value],
            { encoding: "utf8" },
          ),
        );
      const f = fixture(password),
        before = fixture("snapshot"),
        context = await browser.newContext({
          viewport: { width: 390, height: 844 },
        }),
        page = await context.newPage();
      const errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      await context.addInitScript(
        ({ lang }) => {
          localStorage.setItem("creabiz.locale", lang);
          localStorage.setItem("creabiz.theme", "dark");
        },
        { lang },
      );
      const csrf = await (
        await context.request.get(base.origin + "/api/csrf")
      ).json();
      const login = await context.request.post(base.origin + "/api/login", {
        headers: { "X-CSRF-TOKEN": csrf.token, Accept: "application/json" },
        data: { email: f.customer, password },
      });
      assert.equal(login.status(), 200);
      checks++;
      await page.goto(base.origin);
      await page.locator('a[href="#commercial-finance"]').click();
      await page
        .locator("[data-customer-coupons=true]")
        .waitFor({ state: "visible" });
      const root = page.locator("[data-customer-coupons=true]"),
        button = (key) => root.locator(`[data-coupon="${key}"]`);
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=claim]").disabled,
      );
      assert.equal(await page.locator("html").getAttribute("lang"), lang);
      checks++;
      let dispatched = null,
        lost = false;
      await page.route("**/api/commercial/coupons/claim", async (route) => {
        if (!lost && route.request().method() === "POST") {
          dispatched = route.request().postDataJSON();
          const reply = await route.fetch();
          assert.equal(reply.status(), 200);
          lost = true;
          await route.abort("failed");
        } else await route.continue();
      });
      await button("code").fill(f.code);
      await button("claim").click();
      await page.waitForFunction(
        () =>
          document.querySelector("[data-coupon=claim]").disabled &&
          document
            .querySelector("[data-customer-coupons]")
            .getAttribute("aria-busy") === "false",
      );
      assert(lost && dispatched);
      checks++;
      const saved = await page.evaluate(() =>
        Object.keys(localStorage)
          .filter((k) => k.startsWith("creabiz.coupon-intent.v1."))
          .map((k) => ({ key: k, value: JSON.parse(localStorage.getItem(k)) })),
      );
      assert.equal(saved.length, 1);
      assert.equal(saved[0].value.key, dispatched.idempotency_key);
      assert(!JSON.stringify(saved).includes(f.code));
      checks++;
      await page.reload();
      await page.locator('a[href="#commercial-finance"]').click();
      await page
        .locator("[data-customer-coupons=true]")
        .waitFor({ state: "visible" });
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=inspect]").disabled,
      );
      assert.equal(await button("code").inputValue(), "");
      await button("inspect").click();
      await page.waitForFunction(
        () =>
          !Object.keys(localStorage).some((k) =>
            k.startsWith("creabiz.coupon-intent.v1."),
          ),
      );
      await root.locator("article").waitFor({ state: "visible" });
      assert.equal(await root.locator("img").count(), 0);
      assert(
        (await root.innerText()).includes(
          lang === "ar"
            ? "مكافأة قسيمة تجريبية"
            : "<img src=x onerror=alert(1)> Synthetic coupon reward",
        ),
      );
      checks++;
      // A known invalid code remains a saved intent until a server cancellation fence.
      await button("code").fill("UNKNOWN-" + run.toUpperCase() + "-COUPON");
      await button("claim").click();
      await page.waitForFunction(
        () =>
          document
            .querySelector("[data-customer-coupons]")
            .getAttribute("aria-busy") === "false" &&
          document.querySelector("[data-coupon=claim]").disabled,
      );
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=retire]").disabled,
      );
      await button("code").fill("UNKNOWN-" + run.toUpperCase() + "-COUPON");
      page.once("dialog", (d) => d.accept());
      await button("retire").click();
      await page.waitForFunction(
        () =>
          !Object.keys(localStorage).some((k) =>
            k.startsWith("creabiz.coupon-intent.v1."),
          ),
      );
      checks++;
      assert.deepEqual(fixture("snapshot"), before);
      assert.deepEqual(errors, []);
      checks++;
      await context.close();
    }
    console.log(
      `PASS: ${checks} actual Laravel customer coupon EN/AR390 controls, lost acknowledgement, private recovery, cancellation and unchanged-finance checks.`,
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
