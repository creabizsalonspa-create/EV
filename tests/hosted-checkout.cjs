// Actual Laravel canonical checkout journeys; local mock is explicitly not PSP acceptance.
const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  fs = require("node:fs"),
  assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/hosted-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  assert.equal(f.fixture_only, true);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  fs.mkdirSync("artifacts/hosted-checkout/screenshots", { recursive: true });
  try {
    async function login(role, finance = true) {
      const c = await browser.newContext(),
        p = await c.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));
      p.on("response", (r) => {
        if (r.status() >= 500)
          console.error("API failure", r.status(), r.url());
      });
      await p.goto(base);
      await p.locator("#account [name=email]").fill(f[role]);
      await p.locator("#account [name=password]").fill(password);
      const response = p.waitForResponse(
        (r) =>
          r.url().endsWith("/api/login") && r.request().method() === "POST",
      );
      await p.locator("button[value=login]").click();
      let r = await response;
      if (r.status() === 429) {
        const delay = Number(r.headers()["retry-after"]);
        assert(Number.isInteger(delay) && delay >= 1 && delay <= 60);
        for (let left = (delay + 1) * 1000; left > 0; left -= 30000)
          await p.waitForTimeout(Math.min(30000, left));
        const retry = p.waitForResponse(
          (r) =>
            r.url().endsWith("/api/login") && r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        r = await retry;
      }
      assert.equal(r.status(), 200);
      if (finance) {
        await p.locator('a[href="#commercial-finance"]').click();
        await p
          .getByRole("button", {
            name: "Hosted checkout & recovery",
            exact: true,
          })
          .waitFor();
      }
      return p;
    }
    async function api(p, path, method = "GET", body) {
      return p.evaluate(
        async ({ path, method, body }) => {
          const token =
            method === "GET"
              ? null
              : (
                  await (
                    await fetch("/api/csrf", {
                      headers: { Accept: "application/json" },
                    })
                  ).json()
                ).token;
          const r = await fetch("/api/" + path, {
            method,
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              "X-CSRF-TOKEN":
                token ||
                document.querySelector("meta[name=csrf-token]").content,
            },
            body: body ? JSON.stringify(body) : undefined,
          });
          return { status: r.status, data: await r.json() };
        },
        { path, method, body },
      );
    }
    const customer = await login("customer");
    await customer
      .getByRole("button", { name: "Secure hosted checkout", exact: true })
      .click();
    let panel = customer.locator("[data-hosted-checkout]");
    await panel.getByRole("checkbox").check();
    let resolveLost, rejectLost;
    const committed = new Promise((resolve, reject) => {
      resolveLost = resolve;
      rejectLost = reject;
    });
    await customer.route(
      "**/api/commercial/hosted-checkouts",
      async (route) => {
        if (route.request().method() !== "POST") return route.continue();
        try {
          const response = await route.fetch();
          assert.equal(response.status(), 201);
          const result = {
            checkout: (await response.json()).checkout,
            key: route.request().postDataJSON().idempotency_key,
          };
          await route.abort("failed");
          resolveLost(result);
        } catch (error) {
          rejectLost(error);
          await route.abort("failed");
        }
      },
    );
    await panel
      .getByRole("button", {
        name: "Create or recover original hosted checkout",
        exact: true,
      })
      .click();
    let lost;
    const lostTimeout = setTimeout(
      () =>
        rejectLost(
          new Error(
            "Hosted creation did not reach the backend within 20 seconds",
          ),
        ),
      20000,
    );
    try {
      lost = await committed;
    } finally {
      clearTimeout(lostTimeout);
    }
    const h = lost.checkout,
      originalKey = lost.key;
    await customer.unroute("**/api/commercial/hosted-checkouts");
    await panel
      .getByText("Payment was not confirmed.", { exact: false })
      .waitFor();
    assert.equal(await panel.locator("[data-hosted-id]").count(), 0);
    assert.equal(h.amount_minor, 1050);
    assert.equal(h.status, "open");
    assert.equal(h.evidence, "MOCK_HOSTED");
    assert.equal(h.real_money_moved, false);
    console.log(
      "Respecting unchanged platform request window before reload recovery.",
    );
    for (let left = 61000; left > 0; left -= 30000)
      await customer.waitForTimeout(Math.min(30000, left));
    await customer.reload();
    await customer.locator('a[href="#commercial-finance"]').click();
    await customer
      .getByRole("button", { name: "Secure hosted checkout", exact: true })
      .click();
    panel = customer.locator("[data-hosted-checkout]");
    await panel.getByRole("checkbox").check();
    const replay = customer.waitForResponse(
      (r) =>
        r.url().endsWith("/api/commercial/hosted-checkouts") &&
        r.request().method() === "POST",
    );
    await panel
      .getByRole("button", {
        name: "Create or recover original hosted checkout",
        exact: true,
      })
      .click();
    const replayed = await replay;
    assert.equal((await replayed.json()).checkout.id, h.id);
    assert.equal(
      replayed.request().postDataJSON().idempotency_key,
      originalKey,
    );
    const popup = customer.waitForEvent("popup");
    await panel
      .getByRole("link", {
        name: "Open clearly labeled local simulation",
        exact: true,
      })
      .click();
    const sim = await popup;
    await sim.waitForLoadState();
    await sim.locator("#return-consent").check();
    await sim.locator("#return-simulate").click();
    await sim.locator("#return-capture:not([hidden])").waitFor();
    assert.equal(
      (await api(customer, `finance/invoices/${f.invoice}`)).data.paid_minor,
      0,
    );
    sim.on("dialog", (d) => d.accept());
    await sim.locator("#return-capture").click();
    await sim
      .getByText("Captured in TEST accounting", { exact: true })
      .waitFor();
    const paid = await api(customer, `finance/invoices/${f.invoice}`);
    assert.equal(paid.data.paid_minor, 1050);
    assert.equal(paid.data.ledger_balanced, true);
    const replayCapture = await api(
      customer,
      `commercial/hosted-checkouts/${h.id}/capture`,
      "POST",
      {},
    );
    assert.equal(replayCapture.status, 200);
    assert.equal(
      (await api(customer, `finance/invoices/${f.invoice}`)).data.paid_minor,
      1050,
    );
    const ret = await customer.context().newPage();
    const returnPageResponse = await ret.goto(
      base +
        "/payments/hosted-return?hosted_reference=" +
        h.reference +
        "&success=paid",
    );
    assert.equal(returnPageResponse.status(), 200);
    const returnHeaders = returnPageResponse.headers();
    assert(returnHeaders["content-security-policy"].includes("base-uri 'none'"));
    assert(returnHeaders["content-security-policy"].includes("frame-ancestors 'none'"));
    assert.equal(returnHeaders["referrer-policy"], "no-referrer");
    assert.match(returnHeaders["cache-control"], /no-store/);
    assert.equal(returnHeaders["x-frame-options"], "DENY");
    await ret
      .getByText("Captured in TEST accounting", { exact: true })
      .waitFor();
    assert(!ret.url().includes("success="));
    assert.equal(
      new URL(ret.url()).searchParams.get("hosted_reference"),
      h.reference,
    );
    await ret.reload();
    await ret
      .getByText("Captured in TEST accounting", { exact: true })
      .waitFor();
    for (const width of [390, 768, 1440])
      for (const theme of ["light", "dark"]) {
        await ret.setViewportSize({ width, height: 950 });
        await ret.locator("#theme-choice").selectOption(theme);
        assert(
          await ret.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
        await ret.screenshot({
          path: `artifacts/hosted-checkout/screenshots/return-${theme}-${width}.png`,
        });
      }
    await ret.locator("#return-language").click();
    assert.equal(await ret.locator("html").getAttribute("dir"), "rtl");
    await ret.setViewportSize({ width: 390, height: 950 });
    for (const theme of ["light", "dark"]) {
      await ret.locator("#theme-choice").selectOption(theme);
      assert(
        await ret.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
      await ret.screenshot({
        path: `artifacts/hosted-checkout/screenshots/return-ar-${theme}-390.png`,
      });
    }
    await ret.close();
    await sim.close();
    console.log(
      "Respecting unchanged platform request window before subscription journey.",
    );
    for (let left = 61000; left > 0; left -= 30000)
      await customer.waitForTimeout(Math.min(30000, left));
    await customer.evaluate(() => localStorage.setItem("creabiz.locale", "en"));
    await customer.reload();
    await customer.locator('a[href="#commercial-finance"]').click();
    await customer
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    await customer
      .getByRole("button", { name: "Hosted subscription payment", exact: true })
      .click();
    panel = customer.locator("[data-hosted-checkout]");
    await panel.getByRole("checkbox").check();
    const subscriptionCreate = customer.waitForResponse(
      (r) =>
        r.url().endsWith("/api/commercial/hosted-checkouts") &&
        r.request().method() === "POST",
    );
    await panel
      .getByRole("button", {
        name: "Create or recover original hosted checkout",
        exact: true,
      })
      .click();
    const sh = (await (await subscriptionCreate).json()).checkout;
    assert.equal(sh.scope, "subscription");
    assert.equal(sh.subscription_id, f.subscription);
    assert.equal(
      (
        await api(
          customer,
          `commercial/hosted-checkouts/${sh.id}/simulate`,
          "POST",
          { consent: true, outcome: "authorized" },
        )
      ).status,
      200,
    );
    assert.equal(
      (
        await api(
          customer,
          `commercial/hosted-checkouts/${sh.id}/capture`,
          "POST",
          {},
        )
      ).status,
      200,
    );
    assert.equal(
      (await api(customer, `commercial/subscriptions/${f.subscription}`)).data
        .entitlements_active,
      true,
    );
    const vendor = await login("vendor");
    await vendor
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    await vendor
      .getByRole("button", { name: "Hosted subscription payment", exact: true })
      .click();
    const vendorPanel = vendor.locator("[data-hosted-checkout]");
    await vendorPanel.getByRole("checkbox").check();
    const vendorCreation = vendor.waitForResponse(
      (r) =>
        r.url().endsWith("/api/commercial/hosted-checkouts") &&
        r.request().method() === "POST",
    );
    await vendorPanel
      .getByRole("button", {
        name: "Create or recover original hosted checkout",
        exact: true,
      })
      .click();
    const vh = (await (await vendorCreation).json()).checkout;
    assert.equal(vh.scope, "subscription");
    assert.equal(vh.subscription_id, f.vendor_subscription);
    assert.equal(
      (
        await api(
          vendor,
          `commercial/hosted-checkouts/${vh.id}/simulate`,
          "POST",
          { consent: true, outcome: "authorized" },
        )
      ).status,
      200,
    );
    await vendorPanel
      .getByRole("button", { name: "Refresh source status", exact: true })
      .click();
    vendor.on("dialog", (d) => d.accept());
    const vendorCapture = vendor.waitForResponse(
      (r) =>
        r.url().endsWith(`/api/commercial/hosted-checkouts/${vh.id}/capture`) &&
        r.request().method() === "POST",
    );
    await vendorPanel
      .getByRole("button", {
        name: "Confirm capture of authorized amount",
        exact: true,
      })
      .click();
    assert.equal((await vendorCapture).status(), 200);
    assert.equal(
      (await api(vendor, `commercial/subscriptions/${f.vendor_subscription}`))
        .data.entitlements_active,
      true,
    );
    assert.equal(
      (await api(vendor, `commercial/hosted-checkouts/${h.id}`)).status,
      200,
    );
    assert.equal(
      (await api(vendor, `commercial/hosted-checkouts/${h.id}`)).data.checkout
        .hosted_url,
      null,
    );
    assert.equal(
      (
        await api(
          vendor,
          `commercial/hosted-checkouts/${h.id}/capture`,
          "POST",
          {},
        )
      ).status,
      404,
    );
    const foreign = await login("foreign", false);
    assert.equal(
      (await api(foreign, `commercial/hosted-checkouts/${h.id}`)).status,
      404,
    );
    assert.equal(
      (
        await api(
          foreign,
          `commercial/hosted-checkouts/by-reference/${h.reference}`,
        )
      ).status,
      404,
    );
    const guest = await browser.newPage();
    let posts = 0;
    guest.on("request", (r) => {
      if (r.method() === "POST") posts++;
    });
    await guest.goto(
      base +
        "/payments/hosted-return?hosted_reference=" +
        h.reference +
        "&success=paid",
    );
    await guest
      .getByText("Could not confirm the original payment.", { exact: false })
      .waitFor();
    assert.equal(posts, 0);
    assert.equal(await guest.locator("#return-source").innerText(), "");
    assert.equal(
      await guest
        .getByRole("link", { name: "Return to application", exact: true })
        .getAttribute("href"),
      "creabizev://payments/hosted-return",
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel hosted web: original identity reload/replay, explicit local mock authorization then canonical capture, no duplicate receipt, safe return query and private source, subscription activation, tenant/role controls, EN/AR RTL Light/Dark390/768/1440. No external PSP, 3DS or real-money acceptance.",
    );
  } catch (error) {
    console.error("Client errors:", JSON.stringify(errors));
    for (const c of browser.contexts())
      for (const p of c.pages())
        console.error(
          "Visible state:",
          await p.locator("html").getAttribute("lang"),
          (await p.locator("body").innerText()).slice(0, 6500),
        );
    throw error;
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
