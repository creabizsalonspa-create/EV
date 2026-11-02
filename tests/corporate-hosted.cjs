// Actual Laravel statement-root collection. Explicit local mocks are not PSP/SCA acceptance.
const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  assert = require("node:assert/strict"),
  fs = require("node:fs");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
  const password = randomBytes(24).toString("hex") + "aA9!",
    run = randomBytes(4).toString("hex");
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/corporate-hosted-browser.php", run, password],
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
  fs.mkdirSync("artifacts/corporate-hosted/screenshots", { recursive: true });
  try {
    async function login(role, open = false) {
      const c = await browser.newContext(),
        p = await c.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p.locator("#account [name=email]").fill(f[role]);
      await p.locator("#account [name=password]").fill(password);
      const submitted = p.waitForResponse(
        (r) =>
          r.url().endsWith("/api/login") && r.request().method() === "POST",
      );
      await p.locator("button[value=login]").click();
      const response = await submitted;
      assert.equal(response.status(), 200);
      if (open) await openStatement(p);
      return p;
    }
    async function openStatement(p) {
      await p.locator('a[href="#commercial-finance"]').click();
      const root = p.locator("[data-corporate-billing]");
      await root
        .getByRole("button", { name: "Open corporate billing", exact: true })
        .click();
      const form = root.locator(
        'form[data-corporate-form="Open existing company"]',
      );
      await form.locator("[name=fleet_id]").fill(String(f.fleet_id));
      await form
        .getByRole("button", { name: "Open existing company", exact: true })
        .click();
      await root
        .getByRole("button", { name: "View company statements", exact: true })
        .click();
      await root
        .getByRole("button", {
          name: "View statement reconciliation",
          exact: true,
        })
        .click();
      await root
        .getByRole("button", {
          name: "Corporate hosted checkout & recovery",
          exact: true,
        })
        .click();
      await root
        .locator("[data-hosted-checkout]")
        .getByRole("checkbox")
        .waitFor();
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
          const response = await fetch("/api/" + path, {
            method,
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              ...(token ? { "X-CSRF-TOKEN": token } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
          });
          return { status: response.status, data: await response.json() };
        },
        { path, method, body },
      );
    }
    async function pause(p) {
      console.log(
        "Respecting unchanged platform request budget before corporate recovery.",
      );
      for (let left = 61000; left > 0; left -= 30000)
        await p.waitForTimeout(Math.min(30000, left));
    }
    const owner = await login("owner", true),
      panel = owner.locator("[data-hosted-checkout]");
    for (const theme of ["light", "dark"])
      for (const width of [390, 768, 1440]) {
        await owner.setViewportSize({ width, height: 1000 });
        await owner.locator("#theme-choice").selectOption(theme);
        await panel.screenshot({
          path: `artifacts/corporate-hosted/screenshots/review-${theme}-${width}.png`,
        });
        assert.equal(
          await owner.evaluate(
            () => document.documentElement.scrollWidth > innerWidth,
          ),
          false,
        );
      }
    await owner.setViewportSize({ width: 390, height: 1000 });
    await owner.locator("#language").click();
    await owner.waitForFunction(() => document.documentElement.dir === "rtl");
    assert.equal(await panel.getByRole("checkbox").count(), 1);
    for (const theme of ["light", "dark"]) {
      await owner.locator("#theme-choice").selectOption(theme);
      await panel.screenshot({
        path: `artifacts/corporate-hosted/screenshots/review-ar-${theme}-390.png`,
      });
      assert.equal(
        await owner.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
      );
    }
    await owner.locator("#language").click();
    await owner.waitForFunction(() => document.documentElement.dir === "ltr");
    await panel.getByRole("checkbox").check();
    let resolveCommitted, rejectCommitted;
    const committed = new Promise((resolve, reject) => {
      resolveCommitted = resolve;
      rejectCommitted = reject;
    });
    await owner.route(
      "**/api/commercial/corporate/hosted-checkouts",
      async (route) => {
        if (route.request().method() !== "POST") return route.continue();
        try {
          const r = await route.fetch();
          assert.equal(r.status(), 201);
          const data = await r.json();
          await route.abort("failed");
          resolveCommitted({
            row: data.checkout,
            key: route.request().postDataJSON().idempotency_key,
          });
        } catch (e) {
          rejectCommitted(e);
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
    const timer = setTimeout(
      () => rejectCommitted(new Error("Corporate creation timed out")),
      20000,
    );
    let lost;
    try {
      lost = await committed;
    } finally {
      clearTimeout(timer);
    }
    await owner.unroute("**/api/commercial/corporate/hosted-checkouts");
    assert.equal(lost.row.scope, "corporate");
    assert.equal(lost.row.statement_id, f.statement_id);
    assert.equal(lost.row.amount_minor, f.amount_minor);
    assert.equal(lost.row.real_money_moved, false);
    await panel
      .getByText("Payment was not confirmed.", { exact: false })
      .waitFor();
    await pause(owner);
    await owner.reload();
    await openStatement(owner);
    await panel.getByRole("checkbox").check();
    const replay = owner.waitForResponse(
      (r) =>
        r.url().endsWith("/api/commercial/corporate/hosted-checkouts") &&
        r.request().method() === "POST",
    );
    await panel
      .getByRole("button", {
        name: "Create or recover original hosted checkout",
        exact: true,
      })
      .click();
    const replayResponse = await replay;
    assert.equal(
      replayResponse.request().postDataJSON().idempotency_key,
      lost.key,
    );
    assert.equal((await replayResponse.json()).checkout.id, lost.row.id);
    const h = lost.row;
    const popup = owner.waitForEvent("popup");
    await panel
      .getByRole("link", {
        name: "Open clearly labeled local simulation",
        exact: true,
      })
      .click();
    const sim = await popup;
    await sim.locator("#return-consent").check();
    const authorize = sim.waitForResponse(
      (r) =>
        r
          .url()
          .endsWith(
            `/api/commercial/corporate/hosted-checkouts/${h.id}/simulate`,
          ) && r.request().method() === "POST",
    );
    await sim.locator("#return-simulate").click();
    assert.equal((await authorize).status(), 200);
    await sim.locator("#return-capture:not([hidden])").waitFor();
    sim.on("dialog", (d) => d.accept());
    const captured = sim.waitForResponse(
      (r) =>
        r
          .url()
          .endsWith(
            `/api/commercial/corporate/hosted-checkouts/${h.id}/capture`,
          ) && r.request().method() === "POST",
    );
    await sim.locator("#return-capture").click();
    assert.equal((await captured).status(), 200);
    await sim
      .getByText("Captured in TEST accounting", { exact: true })
      .waitFor();
    assert.equal(
      (
        await api(
          owner,
          `commercial/corporate/hosted-checkouts/${h.id}/capture`,
          "POST",
          {},
        )
      ).status,
      200,
    );
    const statement = await api(
      owner,
      `commercial/corporate/statements/${f.statement_id}`,
    );
    assert.equal(statement.status, 200);
    assert.equal(statement.data.paid_minor, f.amount_minor);
    assert.equal(statement.data.net_collected_minor, f.amount_minor);
    assert(statement.data.receipt);
    assert.equal(statement.data.real_payment_processed, false);
    const receipt = statement.data.receipt.id;
    const repeated = await api(
      owner,
      `commercial/corporate/statements/${f.statement_id}`,
    );
    assert.equal(repeated.data.receipt.id, receipt);
    const driver = await login("driver");
    assert.equal(
      (await api(driver, `commercial/corporate/hosted-checkouts/${h.id}`))
        .status,
      404,
    );
    assert.equal(
      (
        await api(
          driver,
          `commercial/corporate/hosted-checkouts/${h.id}/capture`,
          "POST",
          {},
        )
      ).status,
      404,
    );
    for (const id of f.invoice_ids) {
      const invoice = await api(driver, `finance/invoices/${id}`);
      assert.equal(invoice.status, 200);
      assert.equal(invoice.data.amount_due_minor, 0);
      assert.equal(invoice.data.ledger_balanced, true);
      assert.equal(invoice.data.payments.length, 1);
    }
    const admin = await login("admin");
    assert.equal(
      (await api(admin, `commercial/corporate/hosted-checkouts/${h.id}`)).data
        .checkout.owner,
      false,
    );
    assert.equal(
      (
        await api(
          admin,
          `commercial/corporate/hosted-checkouts/${h.id}/capture`,
          "POST",
          {},
        )
      ).status,
      404,
    );
    await sim.goto(
      base +
        `/payments/corporate-hosted-return?hosted_reference=${h.reference}&success=paid&checkout_family=invoice`,
    );
    await sim
      .getByText("Captured in TEST accounting", { exact: true })
      .waitFor();
    assert(!sim.url().includes("success="));
    assert(!sim.url().includes("checkout_family="));
    for (const theme of ["light", "dark"]) {
      await sim.setViewportSize({ width: 390, height: 1000 });
      await sim.locator("#theme-choice").selectOption(theme);
      if ((await sim.locator("html").getAttribute("dir")) !== "rtl")
        await sim.locator("#return-language").click();
      assert.equal(await sim.locator("html").getAttribute("dir"), "rtl");
      await sim.screenshot({
        path: `artifacts/corporate-hosted/screenshots/return-ar-${theme}-390.png`,
      });
      assert.equal(
        await sim.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
      );
    }
    const guest = await (await browser.newContext()).newPage();
    const posts = [];
    guest.on("request", (r) => {
      if (r.method() === "POST") posts.push(r.url());
    });
    await guest.goto(
      base +
        `/payments/corporate-hosted-return?hosted_reference=${h.reference}&success=paid`,
    );
    await guest
      .locator("#return-status")
      .getByText("Could not confirm", { exact: false })
      .waitFor();
    assert.equal(await guest.locator("#return-source").textContent(), "");
    assert.equal(
      await guest
        .getByRole("link", { name: "Return to application", exact: true })
        .getAttribute("href"),
      "creabizev://payments/hosted-return",
    );
    assert.deepEqual(posts, []);
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel corporate hosted: original durable identity after committed-but-lost response, explicit mock authorization/capture, atomic original invoice allocation, duplicate receipt protection, payer/Admin/driver controls, inert public return, responsive EN/AR Light/Dark. No real PSP/SCA/money verified.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
