// Real loopback CSMS source + Laravel + browser. Synthetic chargers only.
const assert = require("node:assert/strict");
const { randomBytes } = require("node:crypto");
const { execFileSync } = require("node:child_process");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { SimulatedCharger, poll } = require("./helpers/csms-local.cjs");
(async () => {
  for (const prefix of ["16", "20"]) {
    const run = prefix + randomBytes(3).toString("hex");
    const password = randomBytes(24).toString("hex") + "aA9!";
    const f = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/charger-diagnostics.php", run, password],
        { encoding: "utf8" },
      ),
    );
    assert(f.fixture_only);
    const client = new BusinessClient();
    client.csrf = (await client.request("csrf")).token;
    await client.request("login", "POST", {
      email: `canonical-owner-${run}@example.test`,
      password,
    });
    client.csrf = (await client.request("csrf")).token;
    const path = `vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/source-diagnostics`;
    const absent = await client.request(path);
    assert.equal(absent.source_registered, false);
    assert.equal(absent.source_online, null);
    const charger = new SimulatedCharger(f.protocol, f.identity);
    try {
      await charger.connect();
      await charger.boot();
      await charger.status("Faulted");
      await charger.status("Available");
      const report = await poll(async () => {
        const value = await client.request(path);
        return value.source_online &&
          value.recent_message_observed &&
          value.events[0]?.connectorStatus === "Available" &&
          value.events.some((e) => e.connectorStatus === "Faulted")
          ? value
          : null;
      }, "source-backed online/fault/recovery diagnostics");
      assert.equal(report.protocol, f.protocol);
      assert.equal(report.commands_sent, false);
      assert.equal(report.physical_charging_verified, false);
      const faultPath = path.replace("/source-diagnostics", "/fault-alerts");
      const runObserver = () =>
        JSON.parse(
          execFileSync(
            "./scripts/csms-observe-faults",
            ["--once", `--binding=${f.binding_id}`],
            { encoding: "utf8" },
          ).trim(),
        );
      assert(runObserver().alerts_created >= 1);
      assert.equal(runObserver().alerts_created, 0);
      const monitored = await client.request(path);
      assert.equal(monitored.automatic_monitor.state, "caught_up");
      assert(monitored.automatic_monitor.checked_at);
      const inspected = await client.request(
        faultPath + "/inspect",
        "POST",
        {},
      );
      assert.equal(inspected.alerts_created, 0);
      assert.equal(
        (await client.request(faultPath + "/inspect", "POST", {}))
          .alerts_created,
        0,
      );
      const alerts = await client.request(faultPath);
      assert(alerts.data.some((a) => a.recovery_observed_at !== null));
      const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.EV_CHROMIUM_PATH || undefined,
        args: ["--no-sandbox"],
      });
      try {
        const context = await browser.newContext({
          viewport: { width: 390, height: 844 },
        });
        await context.addCookies(
          [...client.cookies].map(([name, value]) => ({
            name,
            value,
            url: client.base,
          })),
        );
        const page = await context.newPage(),
          errors = [];
        page.on("pageerror", (e) => errors.push(e.message));
        await page.goto(client.base);
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
          .getByRole("button", { name: "Source diagnostics", exact: true })
          .click();
        const panel = ops.locator("[data-source-diagnostics]");
        await panel
          .getByText("Source reports online", { exact: true })
          .waitFor();
        assert.match(await panel.innerText(), /Faulted/);
        await panel
          .getByRole("button", { name: "Fault alerts", exact: true })
          .click();
        const faultRow = panel.locator("[data-fault-alert]").first();
        await faultRow
          .getByRole("textbox")
          .fill("Investigating the observed simulator fault");
        await faultRow
          .getByRole("button", { name: "Save", exact: true })
          .click();
        await panel
          .getByText("Fault acknowledged", { exact: true })
          .first()
          .waitFor();
        await page.locator("#language").click();
        await panel
          .getByText("المصدر يبلغ عن الاتصال", { exact: true })
          .waitFor();
        assert.equal(
          await page.evaluate(
            () => document.documentElement.scrollWidth > innerWidth,
          ),
          false,
        );
        assert.deepEqual(errors, []);
      } finally {
        await browser.close();
      }
    } finally {
      await charger.close();
    }
    const offline = await poll(async () => {
      const r = await client.request(path);
      return r.source_online === false ? r : null;
    }, "source-backed disconnect");
    assert.equal(offline.commands_sent, false);
    console.log(
      JSON.stringify({
        protocol: f.protocol,
        evidence: "SIMULATED_SOURCE",
        web_en_ar: true,
        fault_recovery: true,
        disconnect: true,
        commands_sent: false,
      }),
    );
  }
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
