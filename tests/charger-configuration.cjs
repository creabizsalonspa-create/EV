// Real local CitrineOS request/reply lineage, safe device profile only.
const { execFileSync, spawn } = require("node:child_process");
const fs = require("node:fs"),
  os = require("node:os"),
  pathUtil = require("node:path");
const { chromium } = require("playwright");
const { randomBytes, randomUUID } = require("node:crypto");
const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { SimulatedCharger } = require("./helpers/csms-local.cjs");
const { operatingSettings } = require("./helpers/operating-settings.cjs");
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function main() {
  execFileSync("./scripts/configuration-read-setup", { stdio: "pipe" });
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
    let client = new BusinessClient();
    client.csrf = (await client.request("csrf")).token;
    await client.request("login", "POST", {
      email: "canonical-owner-" + run + "@example.test",
      password,
    });
    client.csrf = (await client.request("csrf")).token;
    const device = new SimulatedCharger(f.protocol, f.identity);
    device.configurationHandler = operatingSettings(f.protocol);
    try {
      await device.connect();
      await device.boot();
      await device.status();
      const path =
        "vendor-ops/companies/" +
        f.company_id +
        "/chargers/" +
        f.charger_id +
        "/configuration-reads";
      const intent = {
        idempotency_key: randomUUID(),
        reason: "Inspect synthetic device operating settings",
      };
      const created = await client.request(path, "POST", intent);
      assert.equal(created.status, "transport_submitted");
      assert.equal(created.settings_changed, false);
      const replay = await client.request(path, "POST", intent);
      assert.equal(replay.id, created.id);
      let observed;
      const deadline = Date.now() + 20000;
      do {
        observed = await client.request(
          path + "/" + created.id + "/observe",
          "POST",
          {},
        );
        if (observed.status === "source_observed") break;
        await sleep(1000);
      } while (Date.now() < deadline);
      assert.equal(observed.status, "source_observed");
      assert.equal(observed.snapshot.length, 3);
      assert(
        observed.snapshot.every(
          (row) => row.status === "observed" && typeof row.value === "string",
        ),
      );
      assert.equal(observed.snapshot[0].value, "30");
      assert.equal(observed.physical_charging_verified, false);
      assert.equal(
        device.commands.filter((c) =>
          ["GetConfiguration", "GetVariables"].includes(c.action),
        ).length,
        1,
      );
      assert.equal(
        device.commands.filter((c) =>
          /Start|Stop|Reset|Firmware|Set/.test(c.action),
        ).length,
        0,
      );
      let expectedReads = 1;
      if (process.env.EV_CONFIGURATION_NATIVE === "1") {
        const dir = fs.mkdtempSync(
          pathUtil.join(os.tmpdir(), "ev-native-settings-"),
        );
        const input = pathUtil.join(dir, "fixture.json");
        fs.writeFileSync(
          input,
          JSON.stringify({
            ...f,
            owner: "canonical-owner-" + run + "@example.test",
            customer: "canonical-customer-" + run + "@example.test",
            password,
            fixture_only: true,
          }),
          { mode: 0o600 },
        );
        try {
          await new Promise((resolve, reject) => {
            const child = spawn(
              "flutter",
              [
                "test",
                "test/live_charger_configuration_test.dart",
                "--reporter",
                "expanded",
              ],
              {
                cwd: "apps/mobile",
                stdio: "inherit",
                env: { ...process.env, EV_NATIVE_CONFIGURATION_FIXTURE: input },
              },
            );
            child.once("error", reject);
            child.once("exit", (code) =>
              code === 0
                ? resolve()
                : reject(Error("Native settings source proof failed")),
            );
          });
        } finally {
          fs.rmSync(dir, { recursive: true, force: true });
        }
        expectedReads++;
        client = new BusinessClient();
        client.csrf = (await client.request("csrf")).token;
        await client.request("login", "POST", {
          email: "canonical-owner-" + run + "@example.test",
          password,
        });
        client.csrf = (await client.request("csrf")).token;
      }
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
        page.on("pageerror", (error) => errors.push(error.message));
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
          .getByRole("button", {
            name: "Device operating settings",
            exact: true,
          })
          .click();
        const pane = ops.locator("[data-configuration-reads]");
        await pane
          .getByRole("button", { name: "Read device settings", exact: true })
          .click();
        await pane
          .locator("article")
          .first()
          .getByRole("button", { name: "Check device reply", exact: true })
          .click();
        await pane
          .locator("article")
          .first()
          .getByText("Device reply observed", { exact: true })
          .waitFor();
        await pane
          .locator("article")
          .first()
          .getByText("Heartbeat interval (seconds): 30", { exact: true })
          .waitFor();
        expectedReads++;
        assert.equal(
          device.commands.filter((command) =>
            ["GetConfiguration", "GetVariables"].includes(command.action),
          ).length,
          expectedReads,
        );
        assert.equal(
          device.commands.filter((command) =>
            /Start|Stop|Reset|Firmware|Set/.test(command.action),
          ).length,
          0,
        );
        await page.locator("#language").click();
        if (await ops.locator("[data-configuration-reads]").count())
          await ops
            .locator("[data-configuration-reads]")
            .locator("article")
            .first()
            .getByText("فاصل النبضات (ثوانٍ): 30", { exact: true })
            .waitFor();

        await ops
          .getByRole("button", { name: "إعدادات تشغيل الشاحن", exact: true })
          .click();
        await ops
          .locator("[data-configuration-reads]")
          .getByText("تم رصد رد الشاحن", { exact: true })
          .first()
          .waitFor();
        assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
        assert(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          "Settings phone overflow",
        );
        assert.deepEqual(errors, []);
        await page.locator("#logout").click();
        await page.waitForFunction(
          () => document.querySelector("#vendor-operations").hidden,
        );
        assert.equal(await ops.textContent(), "");
      } finally {
        await browser.close();
      }
      console.log(
        JSON.stringify({
          protocol: f.protocol,
          source_configuration_observed: true,
          no_redispatch: true,
          web_settings_workflow: true,
          native_http_workflow: process.env.EV_CONFIGURATION_NATIVE === "1",
          settings_changed: false,
          physical_charging_verified: false,
        }),
      );
    } finally {
      await device.close();
    }
  }
}
main().catch((error) => {
  console.error(
    "Device configuration integration failed: " +
      (error.message?.startsWith("POST ")
        ? error.message
        : "source assertion or fixture unavailable; private data withheld"),
  );
  process.exitCode = 1;
});
