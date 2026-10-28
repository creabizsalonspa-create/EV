// Actual Laravel and browser; synthetic identities, mock payouts and private test PDFs only.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes, createHash } = require("node:crypto");
const fs = require("node:fs");
const assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/payout-browser.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  const artifacts = "artifacts/finance-v3/screenshots";
  fs.mkdirSync(artifacts, { recursive: true });
  try {
    async function login(role) {
      const context = await browser.newContext(),
        p = await context.newPage();
      p.setDefaultTimeout(20000);
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`payout-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      async function attemptLogin() {
        const pending = p.waitForResponse(
          (r) =>
            r.url().endsWith("/api/login") && r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        return pending;
      }
      let response = await attemptLogin();
      if (response.status() === 429) {
        const seconds = Number(response.headers()["retry-after"]);
        assert(Number.isInteger(seconds) && seconds >= 1 && seconds <= 60);
        await p.waitForTimeout((seconds + 1) * 1000);
        response = await attemptLogin();
      }
      assert.equal(response.status(), 200);
      await p.locator('a[href="#commercial-finance"]').click();
      await p
        .locator("[data-vendor-payouts]")
        .getByRole("button", {
          name: "Open vendor payout operations",
          exact: true,
        })
        .waitFor();
      return p;
    }
    const maker = await login("maker"),
      mp = maker.locator("[data-vendor-payouts]");
    await mp
      .getByRole("button", {
        name: "Open vendor payout operations",
        exact: true,
      })
      .click();
    const form = mp.locator('[data-payout-form="Configure test recipient"]');
    await form.locator("[name=vendor_id]").fill(String(fixture.vendor));
    await form.locator("[name=account_reference]").fill("mock_Browser" + run);
    await form.locator("[name=bank_reference]").fill("mock_Bank" + run);
    const accountResponse = maker.waitForResponse(
      (r) =>
        r
          .url()
          .endsWith(`/commercial/vendors/${fixture.vendor}/payout-accounts`) &&
        r.request().method() === "POST",
    );
    await form
      .getByRole("button", { name: "Configure test recipient", exact: true })
      .click();
    const account = (await (await accountResponse).json()).account;
    assert.equal(account.status, "pending_consent");
    assert(!("account_reference" in account));
    const vendor = await login("vendor"),
      vp = vendor.locator("[data-vendor-payouts]");
    await vp
      .getByRole("button", {
        name: "Open vendor payout operations",
        exact: true,
      })
      .click();
    const vc = vp.locator(`[data-payout-record="${account.id}"]`);
    await vc.locator("[type=checkbox]").check();
    await vc
      .getByRole("button", {
        name: "Consent to test payout account",
        exact: true,
      })
      .click();
    await vc.locator('[data-payout-state="pending_verification"]').waitFor();
    await mp
      .getByRole("button", { name: "Refresh payouts", exact: true })
      .click();
    await mp
      .locator(`[data-payout-record="${account.id}"]`)
      .getByRole("button", { name: "Verify with payout provider", exact: true })
      .click();
    await mp
      .locator(
        `[data-payout-record="${account.id}"] [data-payout-state="active"]`,
      )
      .waitFor();
    await mp
      .getByRole("button", { name: "Payout records", exact: true })
      .click();
    const pf = mp.locator('[data-payout-form="Prepare test payout"]');
    await pf.locator("[name=settlement_id]").fill(String(fixture.settlement));
    await pf.locator("[name=account_id]").fill(String(account.id));
    const created = maker.waitForResponse(
      (r) =>
        r
          .url()
          .endsWith(`/commercial/settlements/${fixture.settlement}/payouts`) &&
        r.request().method() === "POST",
    );
    await pf
      .getByRole("button", { name: "Prepare test payout", exact: true })
      .click();
    const payout = (await (await created).json()).payout;
    assert.equal(payout.status, "prepared");
    await mp.locator(`[data-payout-record="${payout.id}"]`).waitFor();
    assert.equal(
      await mp
        .getByRole("button", {
          name: "Approve payout independently",
          exact: true,
        })
        .count(),
      0,
    );
    const checker = await login("checker"),
      cp = checker.locator("[data-vendor-payouts]");
    await cp
      .getByRole("button", {
        name: "Open vendor payout operations",
        exact: true,
      })
      .click();
    await cp
      .getByRole("button", { name: "Payout records", exact: true })
      .click();
    await cp
      .locator(`[data-payout-record="${payout.id}"]`)
      .getByRole("button", {
        name: "Approve payout independently",
        exact: true,
      })
      .click();
    await cp
      .locator(`[data-payout-record="${payout.id}"]`)
      .getByRole("button", { name: "Execute test payout", exact: true })
      .click();
    await cp
      .locator(`[data-payout-record="${payout.id}"] [data-payout-state="paid"]`)
      .waitFor();
    await cp
      .locator(`[data-payout-record="${payout.id}"]`)
      .getByRole("button", { name: "Inspect payout history", exact: true })
      .click();
    await cp.locator("[data-payout-history]").waitFor();
    assert((await cp.innerText()).includes("MOCK · no bank transfer"));
    const config = maker.locator("[data-financial-documents]");
    await config
      .getByRole("button", { name: "Open financial documents", exact: true })
      .click();
    await config
      .getByRole("button", { name: "Issuer configuration", exact: true })
      .click();
    for (const [name, value] of Object.entries({
      legal_name: "Synthetic platform " + run,
      legal_name_ar: "منصة تجريبية",
      address: "Synthetic Dubai address",
      address_ar: "عنوان تجريبي دبي",
      effective_from: new Date(Date.now() - 86400000).toISOString(),
    }))
      await config.locator(`[name="${name}"]`).fill(value);
    await config
      .getByRole("button", { name: "Publish issuer version", exact: true })
      .click();
    await config
      .getByRole("heading", {
        name: new RegExp("^Synthetic platform " + run + " · v[0-9]+$"),
        exact: true,
      })
      .waitFor();
    // Generate a real PDF through the owner UI; download bytes match immutable metadata.
    const dp = vendor.locator("[data-financial-documents]");
    await dp
      .getByRole("button", { name: "Open financial documents", exact: true })
      .click();
    await dp.locator("[name=source_id]").fill(String(fixture.invoice));
    await dp.locator("[name=locale]").selectOption("ar");
    const generated = vendor.waitForResponse(
      (r) =>
        r.url().endsWith("/financial/documents") &&
        r.request().method() === "POST",
    );
    await dp
      .getByRole("button", { name: "Generate private document", exact: true })
      .click();
    const doc = await (await generated).json();
    assert(doc.id);
    await dp.getByRole("link", { name: "Download PDF", exact: true }).waitFor();
    const pdf = await vendor.request.get(
      `${base}/api/financial/documents/${doc.id}/download/pdf`,
    );
    assert.equal(pdf.status(), 200);
    assert.match(pdf.headers()["cache-control"], /no-store/);
    const bytes = await pdf.body();
    assert(bytes.subarray(0, 5).equals(Buffer.from("%PDF-")));
    assert.equal(
      createHash("sha256").update(bytes).digest("hex"),
      pdf.headers()["x-document-sha256"],
    );
    const download = vendor.waitForEvent("download");
    await dp.getByRole("link", { name: "Download PDF", exact: true }).click();
    await (
      await download
    ).saveAs(`artifacts/finance-v3/arabic-invoice-${run}.pdf`);
    const outsider = await browser.newContext();
    assert.equal(
      (
        await outsider.request.get(
          `${base}/api/financial/documents/${doc.id}/download/pdf`,
          { headers: { Accept: "application/json" } },
        )
      ).status(),
      401,
    );
    await outsider.close();
    await dp.locator("[name=source_kind]").selectOption("vendor_settlement");
    await dp.locator("[name=source_id]").fill(String(fixture.settlement));
    const vendorStatement = vendor.waitForResponse(
      (r) =>
        r.url().endsWith("/financial/documents") &&
        r.request().method() === "POST",
    );
    await dp
      .getByRole("button", { name: "Generate private document", exact: true })
      .click();
    const statementDoc = await (await vendorStatement).json();
    assert.equal(statementDoc.current_source_state, "provider_paid");
    assert.equal(statementDoc.external_provider_verified, false);
    await dp
      .getByRole("heading", { name: statementDoc.number, exact: true })
      .waitFor();
    for (const theme of ["light", "dark"])
      for (const width of [390, 768, 1440]) {
        await vendor.setViewportSize({ width, height: 1100 });
        await vendor.locator("#theme-choice").selectOption(theme);
        await vendor.screenshot({
          path: `${artifacts}/${theme}-${width}.png`,
          fullPage: true,
        });
        await vp.screenshot({
          path: `${artifacts}/${theme}-${width}-payout-panel.png`,
        });
        await dp.screenshot({
          path: `${artifacts}/${theme}-${width}-documents-panel.png`,
        });
        assert(
          await vendor.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
      }
    await vendor.locator("#language").click();
    await vendor.waitForFunction(() => document.documentElement.dir === "rtl");
    for (const theme of ["light", "dark"]) {
      await vendor.locator("#theme-choice").selectOption(theme);
      await vendor.setViewportSize({ width: 390, height: 1100 });
      await vendor.screenshot({
        path: `${artifacts}/ar-${theme}-390.png`,
        fullPage: true,
      });
      await vp.screenshot({
        path: `${artifacts}/ar-${theme}-390-payout-panel.png`,
      });
      await dp.screenshot({
        path: `${artifacts}/ar-${theme}-390-documents-panel.png`,
      });
      assert(
        await vendor.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
    }
    assert.deepEqual(errors, []);
    console.log(
      "PASS: actual-Laravel vendor consent, verification, maker/checker payout, mock provider outcome/history; private Arabic PDF and owner download/hash; anonymous denial; Light/Dark responsive and Arabic RTL screenshots. No live payout or legal acceptance.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
