// Agent 9 browser security regression against an isolated localhost Laravel
// server. This test NEVER creates checkouts, captures money, or writes records.
// Usage: node tests/agent9-payment-return-browser.cjs http://127.0.0.1:18764
const test = require("node:test");
const assert = require("node:assert/strict");
const { chromium } = require("playwright");

const input = process.argv[2];
let base;
try { base = new URL(input); } catch { /* reject below */ }
if (!base || base.protocol !== "http:" || base.hostname !== "127.0.0.1" ||
    !/^[0-9]{1,5}$/.test(base.port) || Number(base.port) < 1024 ||
    Number(base.port) > 65535 || base.pathname !== "/" || base.search ||
    base.hash || base.username || base.password) {
  console.error("Usage: node tests/agent9-payment-return-browser.cjs http://127.0.0.1:HIGH_PORT");
  process.exit(2);
}
const uuid = "00000000-0000-4000-8000-000000000001";
for (const [family, path, familyValue] of [
  ["invoice", "/payments/hosted-return", "invoice"],
  ["corporate", "/payments/corporate-hosted-return", "corporate"],
]) {
  test(`${family}: forged success and provider status cannot confirm money`, async () => {
    const browser = await chromium.launch({ headless: true, args: ["--no-sandbox"] });
    try {
      const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
      const page = await context.newPage();
      const errors = [], writes = [], foreign = [], popups = [];
      page.on("pageerror", e => errors.push(e.message));
      page.on("popup", p => popups.push(p.url()));
      page.on("request", r => {
        if (r.method() !== "GET") writes.push(r.method());
        if (r.url().startsWith("http") && new URL(r.url()).origin !== base.origin) foreign.push("external");
      });
      const sentinel = "qa-spoofed-payment-success-marker";
      const response = await page.goto(new URL(
        path + "?hosted_reference=" + sentinel +
        "&success=paid&checkout_family=invoice&payment_status=captured", base
      ).href);
      assert.equal(response.status(), 200);
      assert.ok(response.headers()["content-security-policy"].includes("base-uri 'none'"));
      assert.equal(response.headers()["referrer-policy"], "no-referrer");
      assert.match(response.headers()["cache-control"], /no-store/);
      await assert.doesNotReject(() =>
        page.locator("#return-status").getByText("No valid original checkout reference", { exact: false })
          .waitFor({ timeout: 5000 })
      );
      assert.equal(await page.locator("[data-hosted-return]").getAttribute("data-checkout-family"), familyValue);
      assert.equal(await page.locator("#return-source").textContent(), "");
      assert.equal(await page.locator("#return-capture").isVisible(), false);
      assert.equal(await page.locator("#return-simulation").isVisible(), false);
      assert.equal(new URL(page.url()).search, "");
      assert.equal((await page.locator("body").textContent()).includes(sentinel), false);
      for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 950 });
        await page.locator("#theme-choice").selectOption("dark");
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true,
          `${family} dark layout at ${width}px must not overflow horizontally`);
      }
      await page.setViewportSize({ width: 390, height: 950 });
      await page.locator("#return-language").click();
      assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
      assert.deepEqual(writes, [], "passive public return must never send mutations");
      assert.deepEqual(foreign, [], "the local return page must not load remote resources");
      assert.deepEqual(popups, [], "no unexpected popup on public return");
      assert.deepEqual(errors, [], "no JavaScript exception");
      await context.close();
    } finally { await browser.close(); }
  });

  test(`${family}: unauthorized original reference stays unverified`, async () => {
    const browser = await chromium.launch({ headless: true, args: ["--no-sandbox"] });
    try {
      const page = await browser.newPage();
      const writes = [], errors = [];
      page.on("request", request => { if (request.method() !== "GET") writes.push(request.method()); });
      page.on("pageerror", err => errors.push(err.message));
      await page.goto(new URL(path + "?hosted_reference=" + uuid + "&success=paid", base).href);
      await page.locator("#return-status").getByText("Could not confirm the original payment", {
        exact: false,
      }).waitFor({ timeout: 6000 });
      assert.equal(new URL(page.url()).searchParams.get("hosted_reference"), uuid);
      assert.equal(new URL(page.url()).searchParams.has("success"), false);
      assert.equal(await page.locator("#return-source").textContent(), "");
      assert.equal(await page.locator("#return-capture").isVisible(), false);
      assert.deepEqual(writes, []);
      assert.deepEqual(errors, []);
    } finally { await browser.close(); }
  });
}
