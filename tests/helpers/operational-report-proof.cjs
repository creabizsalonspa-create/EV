const assert = require("node:assert/strict");
const { chromium } = require("playwright");
const { spawn } = require("node:child_process");
const fs = require("node:fs"),
  os = require("node:os"),
  path = require("node:path");
module.exports = async function (fixture, clients) {
  const reportPath = `vendor-ops/companies/${fixture.company_id}/operational-analytics`;
  const report = await clients.vendor.request(reportPath);
  assert.equal(report.evidence, "SIMULATED_SOURCE");
  assert.equal(report.physical_uptime_verified, false);
  assert.equal(report.billing_authority, false);
  assert.equal(report.sites.length, 2);
  assert(
    report.sites.every(
      (s) =>
        s.source_completed === 1 &&
        s.completed_energy_wh > 0 &&
        s.observed_busy_seconds > 0,
    ),
  );
  assert.equal(
    report.invoice_facts.reduce((n, x) => n + x.invoices, 0),
    2,
  );
  const admin = await clients.admin.request(reportPath);
  assert.deepEqual(
    admin.sites.map((s) => s.completed_energy_wh),
    report.sites.map((s) => s.completed_energy_wh),
  );
  await assert.rejects(clients.customer.request(reportPath), /failed \(404\)/);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    const failedReads = [];
    page.on("response", (response) => {
      const pathname = new URL(response.url()).pathname;
      if (
        response.request().method() === "GET" &&
        response.status() >= 400 &&
        [
          "/api/vendor-ops/companies",
          "/api/support",
          "/api/regions",
          "/api/me",
        ].some((path) => pathname === path || pathname.startsWith(path + "/"))
      ) {
        failedReads.push({ path: pathname, status: response.status() });
        if (failedReads.length > 12) failedReads.shift();
      }
    });
    await page.goto(clients.vendor.base);
    // Logout must exercise a separate browser session, never revoke the CLI
    // vendor session needed by the following source-review assertions.
    await page
      .locator("#account [name=email]")
      .fill(fixture.accounts.vendor.email);
    await page
      .locator("#account [name=password]")
      .fill(fixture.accounts.vendor.password);
    const login = page.waitForResponse(
      (r) =>
        new URL(r.url()).pathname === "/api/login" &&
        r.request().method() === "POST",
    );
    await page.locator("button[value=login]").click();
    assert.equal((await login).status(), 200);

    const ops = page.locator("#vendor-operations");
    try {
      await ops
        .locator("article")
        .filter({
          has: page.getByRole("heading", {
            name: `Synthetic charging lab ${fixture.run}`,
            exact: true,
          }),
        })
        .first()
        .getByRole("button", { name: "Open company", exact: true })
        .click();
    } catch (error) {
      throw new Error(
        error.message +
          "\nCompany bootstrap failed reads (paths/status only): " +
          JSON.stringify(failedReads),
      );
    }
    await ops
      .getByRole("button", {
        name: "Charging operational analytics",
        exact: true,
      })
      .click();
    const panel = ops.locator("[data-operational-analytics]");
    await panel
      .getByText(/Operational metric: Completed energy/)
      .first()
      .waitFor();
    const csvLink = panel.getByRole("link", {
      name: "Download report CSV",
      exact: true,
    });
    const csv = await context.request.get(
      new URL(await csvLink.getAttribute("href"), clients.vendor.base).href,
    );
    assert.equal(csv.status(), 200);
    assert((await csv.text()).includes("completed_energy_wh"));
    assert((csv.headers()["cache-control"] || "").includes("no-store"));
    const pdf = await context.request.get(
      clients.vendor.base +
        (await panel
          .getByRole("link", { name: "Download report PDF", exact: true })
          .getAttribute("href")),
    );
    assert.equal(pdf.status(), 200);
    assert((await pdf.body()).subarray(0, 5).equals(Buffer.from("%PDF-")));
    const prior = await csvLink.getAttribute("href");
    await panel.locator("[name=operational_site_id]").fill("999999");
    assert.equal(
      await csvLink.getAttribute("href"),
      prior,
      "Unapplied filter edits must not change report exports",
    );
    await page.locator("#language").click();
    await panel
      .getByText(/مقياس التشغيل: الطاقة المكتملة/)
      .first()
      .waitFor();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    await page.locator("#logout").click();
    await page.locator("#account [name=email]").waitFor();
    assert.equal(await ops.locator("[data-operational-analytics]").count(), 0);
    assert.deepEqual(errors, []);
    assert.equal(
      (await clients.vendor.request(reportPath)).evidence,
      "SIMULATED_SOURCE",
      "Browser logout must not invalidate the independent CLI vendor session",
    );
  } finally {
    await browser.close();
  }
  if (process.env.EV_OPERATIONAL_NATIVE === "1") {
    const dir = fs.mkdtempSync(
        path.join(os.tmpdir(), "ev-operational-native-"),
      ),
      input = path.join(dir, "fixture.json");
    fs.writeFileSync(
      input,
      JSON.stringify({
        fixture_only: true,
        company_id: fixture.company_id,
        accounts: fixture.accounts,
      }),
      { mode: 0o600 },
    );
    try {
      await new Promise((resolve, reject) => {
        const child = spawn(
          "flutter",
          [
            "test",
            "test/live_operational_analytics_test.dart",
            "--reporter",
            "expanded",
          ],
          {
            cwd: "apps/mobile",
            stdio: "inherit",
            env: { ...process.env, EV_NATIVE_OPERATIONAL_FIXTURE: input },
          },
        );
        child.once("error", reject);
        child.once("exit", (code) =>
          code === 0
            ? resolve()
            : reject(Error("Native operational analytics proof failed")),
        );
      });
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
  }
  console.log(
    "PASS: actual dual-protocol charging -> operational energy/utilization/invoice reporting, scoped web CSV/PDF, Arabic phone layout and logout privacy.",
  );
};
