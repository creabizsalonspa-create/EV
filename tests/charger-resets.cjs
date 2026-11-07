// Actual local CitrineOS + Laravel + operator browser. No physical commands.
const assert = require("node:assert/strict");
const { randomBytes, randomUUID } = require("node:crypto");
const { execFileSync } = require("node:child_process");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { SimulatedCharger, poll } = require("./helpers/csms-local.cjs");
(async () => {
  execFileSync("./scripts/reset-operations-setup", { stdio: "pipe" });
  for (const prefix of ["16", "20"]) {
    const run = prefix + randomBytes(3).toString("hex"),
      password = randomBytes(24).toString("hex") + "aA9!";
    const f = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/charger-diagnostics.php", run, password],
        { encoding: "utf8" },
      ),
    );
    const c = new BusinessClient();
    c.csrf = (await c.request("csrf")).token;
    await c.request("login", "POST", {
      email: `canonical-owner-${run}@example.test`,
      password,
    });
    c.csrf = (await c.request("csrf")).token;
    const charger = new SimulatedCharger(f.protocol, f.identity);
    charger.acceptSafeReset = true;
    const path = `vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/reset-operations`;
    const browser = await chromium.launch({
      headless: true,
      executablePath: process.env.EV_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    });
    try {
      await charger.connect();
      await charger.boot();
      await charger.status();
      const ctx = await browser.newContext({
        viewport: { width: 390, height: 844 },
      });
      await ctx.addCookies(
        [...c.cookies].map(([name, value]) => ({ name, value, url: c.base })),
      );
      const page = await ctx.newPage(),
        errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(c.base);
      const ops = page.locator("#vendor-operations");
      await ops
        .locator("article")
        .filter({
          has: page.getByRole("heading", { name: f.name, exact: true }),
        })
        .first()
        .getByRole("button", { name: "Open company", exact: true })
        .click();
      await ops
        .getByRole("button", { name: "Open chargers", exact: true })
        .click();
      await ops
        .getByRole("button", { name: "Safe reset operations", exact: true })
        .click();
      const pane = ops.locator("[data-reset-operations]"),
        form = pane.locator("form").first();
      await form
        .locator("[name=reason]")
        .fill("Synthetic safe reset operational review");
      const key = await form.locator("[name=idempotency_key]").inputValue();
      await form.getByRole("button", { name: "Save", exact: true }).click();
      await pane.getByText("transport_submitted", { exact: true }).waitFor();
      const latest = (await c.request(path)).data[0];
      await c.request(path, "POST", {
        reason: "Synthetic safe reset operational review",
        idempotency_key: key,
      });
      await poll(
        async () => charger.commands.some((x) => x.action === "Reset"),
        "safe reset receipt",
      );
      const resets = charger.commands.filter((x) => x.action === "Reset");
      assert.equal(resets.length, 1);
      assert.equal(
        resets[0].payload.type,
        f.protocol === "1.6" ? "Soft" : "OnIdle",
      );
      await assert.rejects(
        () =>
          c.request(path, "POST", {
            reason: "Must not resend unresolved reset",
            idempotency_key: randomUUID(),
          }),
        /409/,
      );
      await new Promise((r) => setTimeout(r, 21000));
      await charger.status();
      await pane
        .locator("[name=note]")
        .fill("Reviewed fresh simulator idle state after reset");
      await pane
        .locator("form")
        .last()
        .getByRole("button", { name: "Save", exact: true })
        .click();
      await pane
        .getByText(
          "Fresh idle source reviewed; physical reset execution remains unverified",
          { exact: true },
        )
        .waitFor();
      assert(
        (await c.request(path)).data.find((x) => x.id === latest.id)
          .reviewed_at,
      );
      await page.locator("#language").click();
      await pane
        .getByRole("heading", {
          name: "عمليات إعادة التشغيل الآمنة",
          exact: true,
        })
        .waitFor();
      assert.equal(
        await page.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
      );
      assert.deepEqual(errors, []);
      console.log(
        `${f.protocol}: safe reset, one-shot replay, admission hold, fresh source review and operator EN/AR390px workflow passed; simulator only`,
      );
    } finally {
      await browser.close();
      await charger.close();
    }
  }
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
