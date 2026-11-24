const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  assert = require("node:assert/strict"),
  { SimulatedCharger, graph, poll } = require("./helpers/csms-local.cjs");
(async () => {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/charger-operations.php", run, password],
      { encoding: "utf8" },
    ),
  );
  assert(f.fixture_only);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  let simulator = null;
  try {
    const page = await browser.newPage({
        viewport: { width: 390, height: 844 },
      }),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("http://127.0.0.1:8000");
    await page
      .locator("#account [name=email]")
      .fill(`canonical-owner-${run}@example.test`);
    await page.locator("#account [name=password]").fill(password);
    let waiting = page.waitForResponse(
      (r) =>
        new URL(r.url()).pathname === "/api/login" &&
        r.request().method() === "POST",
    );
    await page.locator("button[value=login]").click();
    assert.equal((await waiting).status(), 200);
    await page
      .locator("#notice")
      .getByText("Signed in.", { exact: true })
      .waitFor();
    await page
      .locator("#charging-dashboard")
      .getByText(/^Stations: /)
      .waitFor();
    assert.match(
      await page.locator("#charging-dashboard").innerText(),
      /Open source-linked sessions: \d+/,
    );
    assert.match(
      await page.locator("#charging-dashboard").innerText(),
      /does not prove connector availability/,
    );
    await page.locator("#language").click();
    await page
      .locator("#charging-dashboard")
      .getByText(/^المحطات: /)
      .waitFor();
    await page.locator("#language").click();
    const ops = page.locator("#vendor-operations");
    await ops
      .locator("article")
      .filter({ has: page.getByRole("heading", { name: f.name, exact: true }) })
      .first()
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    // A real, linked vendor-owned site exposes its station-wide
    // reservation policy without assuming live charger availability.
    const policyButton = ops.getByRole("button", {
      name: "Manage station availability blocks", exact: true,
    });
    await policyButton.waitFor();
    await policyButton.click();
    const policy = ops.locator("[data-station-block-editor]");
    await policy.getByRole("heading", {
      name: "Station availability blocks", exact: true,
    }).waitFor();
    const utc = (offset) => new Date(Date.now() + offset).toISOString().slice(0, 19) + "Z";
    const fields = policy.locator("form");
    await fields.locator("[name=starts_at]").fill(utc(3600000));
    await fields.locator("[name=ends_at]").fill(utc(7200000));
    await fields.locator("[name=reason_en]").fill("Scheduled source-safe maintenance");
    await fields.locator("[name=reason_ar]").fill("صيانة مجدولة آمنة");
    waiting = page.waitForResponse((r) =>
      new URL(r.url()).pathname === `/api/stations/${f.station_id}/availability-blocks` &&
      r.request().method() === "POST");
    await fields.getByRole("button", { name: "Publish station block" }).click();
    const published = await waiting;
    assert.equal(published.status(), 201);
    const publishedBlock = await published.json();
    assert.equal(publishedBlock.station_id, f.station_id);
    assert.equal(publishedBlock.scope, "STATION_WIDE_OPERATOR_POLICY");
    assert.equal(publishedBlock.physical_availability_verified, false);
    await policy.getByText(/Scheduled source-safe maintenance/).waitFor();
    await page.locator("#language").click();
    await policy.getByText(/صيانة مجدولة آمنة/).waitFor();
    await page.locator("#language").click();
    waiting = page.waitForResponse((r) =>
      new URL(r.url()).pathname ===
        `/api/stations/${f.station_id}/availability-blocks/${publishedBlock.id}/cancel` &&
      r.request().method() === "POST");
    await policy.getByRole("button", { name: "Cancel this block" }).click();
    assert.equal((await waiting).status(), 200);
    await policy.getByRole("button", { name: "Cancel this block" }).waitFor({
      state: "detached",
    });
    await policy.getByRole("button", {
      name: "Close station availability blocks", exact: true,
    }).click();
    assert.equal(await ops.locator("[data-station-block-editor]").count(), 0);
    await ops
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
    // An unbooted simulator has no complete live source identity. The new
    // per-connector source view must fail closed, without a device command,
    // customer data or any physical availability assertion.
    await ops
      .getByRole("button", { name: "Source diagnostics", exact: true })
      .click();
    const liveSource = ops.locator("[data-source-diagnostics]");
    await liveSource.getByRole("button", {
      name: "Read live connector source", exact: true,
    }).waitFor();
    const pendingLive = page.waitForResponse((r) =>
      new URL(r.url()).pathname ===
        `/api/vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/live-connectors` &&
      r.request().method() === "GET",
    );
    await liveSource.getByRole("button", {
      name: "Read live connector source", exact: true,
    }).click();
    const unavailableLive = await pendingLive;
    assert([409, 503].includes(unavailableLive.status()));
    await liveSource.getByText(
      "Local connector source unavailable; no control is authorized.",
      { exact: true },
    ).waitFor();
    assert.equal(
      await liveSource.getByText("Recent source status:", { exact: false }).count(),
      0,
    );

    await ops
      .getByRole("button", { name: "Secure charger admission", exact: true })
      .click();
    const admission = ops.locator("[data-admission]");
    await admission.locator("[name=identity]").fill(f.identity);
    waiting = page.waitForResponse(
      (r) => r.url().endsWith("/admission") && r.request().method() === "POST",
    );
    await admission.getByRole("button", { name: "Save", exact: true }).click();
    assert.equal((await waiting).status(), 201);
    await admission
      .getByRole("button", { name: "Issue / rotate credential", exact: true })
      .waitFor();
    page.once("dialog", (d) => d.accept());
    waiting = page.waitForResponse(
      (r) =>
        r.url().endsWith("/admission/credential") &&
        r.request().method() === "POST",
    );
    await admission
      .getByRole("button", { name: "Issue / rotate credential", exact: true })
      .click();
    assert.equal((await waiting).status(), 201);
    const secret = await admission.locator("textarea").inputValue();
    assert.equal(secret.length, 40);
    assert(
      !JSON.stringify(
        await page.evaluate(() => ({ ...localStorage, ...sessionStorage })),
      ).includes(secret),
    );
    await admission
      .getByRole("button", { name: "Hide secret", exact: true })
      .click();
    assert.equal(await admission.locator("textarea").count(), 0);
    await admission
      .getByRole("button", { name: "Refresh admission", exact: true })
      .click();
    await admission
      .getByRole("button", { name: "Revoke credential", exact: true })
      .waitFor();
    page.once("dialog", (d) => d.accept());
    waiting = page.waitForResponse(
      (r) =>
        r.url().endsWith("/admission/credential") &&
        r.request().method() === "DELETE",
    );
    await admission
      .getByRole("button", { name: "Revoke credential", exact: true })
      .click();
    assert.equal((await waiting).status(), 200);
    await admission.getByText("Admission disabled", { exact: true }).waitFor();
    await admission.getByText("Credential revoked", { exact: true }).waitFor();
    await admission
      .getByRole("button", { name: "Credential history", exact: true })
      .click();
    await admission
      .getByRole("heading", { name: "Credential history", exact: true })
      .waitFor();
    await admission
      .getByText("Credential issued or rotated", { exact: true })
      .waitFor();
    assert.equal(
      await admission.getByText("Operator action", { exact: true }).count(),
      3,
    );
    assert(!(await admission.textContent()).includes(secret));

    const card = page.locator(
      `#command-list [data-command-id="${f.command_id}"]`,
    );
    await card
      .getByRole("button", { name: "Operational recovery", exact: true })
      .click();
    const recovery = card.locator("[data-operational-case]");
    await recovery
      .locator("textarea")
      .fill("Investigating source absence <literal>");
    waiting = page.waitForResponse(
      (r) => r.url().endsWith("/case") && r.request().method() === "POST",
    );
    await recovery
      .getByRole("button", { name: "Open recovery case", exact: true })
      .click();
    assert.equal((await waiting).status(), 201);
    await recovery
      .getByRole("button", {
        name: "Inspect authenticated simulator source",
        exact: true,
      })
      .waitFor();
    waiting = page.waitForResponse((r) => r.url().endsWith("/inspect-source"));
    await recovery
      .getByRole("button", {
        name: "Inspect authenticated simulator source",
        exact: true,
      })
      .click();
    const checked = await waiting;
    assert.equal(checked.status(), 200);
    const result = await checked.json();
    assert.equal(result.source_observed, false);
    assert.equal(result.command_resent, false);
    assert.equal(result.capacity_release_forced, false);
    await recovery
      .getByText("Recovery case: Awaiting source evidence", { exact: true })
      .waitFor();
    assert.equal(await recovery.locator("option[value=resolved]").count(), 0);
    await recovery
      .getByText("opened · Investigating source absence <literal>", {
        exact: true,
      })
      .waitFor();
    await page.locator("#language").click();
    await recovery
      .getByText("حالة الاسترداد: بانتظار أدلة المصدر", { exact: true })
      .waitFor();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    // Return from Arabic recovery review and reopen an independent scoped
    // source panel before testing the known local simulator.
    await page.locator("#language").click();
    await ops.getByRole("button", {
      name: "Source diagnostics", exact: true,
    }).click();
    // Supply only a generated local 2.0.1 simulator. A fully authenticated
    // typed Boot/Heartbeat plus persisted matching status event must permit
    // a *recent source report*, but never physical availability or command.
    simulator = new SimulatedCharger("2.0.1", f.identity);
    await simulator.connect();
    const boot = await simulator.call("BootNotification", {
      reason: "PowerUp",
      chargingStation: {
        vendorName: "Synthetic",
        model: "Fixture",
        firmwareVersion: "sim-fw-v1",
      },
    });
    assert.equal(boot.status, "Accepted");
    await simulator.call("Heartbeat", {});
    await simulator.status("Available", 7, 5);
    await poll(async () => {
      const stations = await graph(
        "query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol chargePointVendor chargePointModel latestOcppMessageTimestamp}}",
        { name: f.identity },
      );
      if (stations.ChargingStations.length !== 1 ||
          stations.ChargingStations[0].isOnline !== true) return false;
      const sourceId = stations.ChargingStations[0].id;
      const states = await graph(
        "query($station:Int!){Connectors(where:{tenantId:{_eq:1},stationId:{_eq:$station}},limit:101){id} StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:5){connectorDatabaseId evseId connectorId connectorStatus}}",
        { station: sourceId },
      );
      return states.Connectors.length === 1 &&
          states.StatusNotifications[0]?.connectorStatus === "Available" &&
          states.StatusNotifications[0]?.evseId === 5 &&
          states.StatusNotifications[0]?.connectorId === 7;
    }, "Bounded matching synthetic CSMS connector source");

    const liveResponse = page.waitForResponse((r) =>
      new URL(r.url()).pathname ===
        `/api/vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/live-connectors` &&
      r.request().method() === "GET",
    );
    await liveSource.getByRole("button", {
      name: "Read live connector source", exact: true,
    }).click();
    const verified = await liveResponse;
    assert.equal(verified.status(), 200);
    const observation = await verified.json();
    assert.equal(observation.evidence, "SIMULATED_CSMS_SOURCE");
    assert.equal(observation.source_topology_matches_local, true);
    assert.equal(observation.physical_availability_verified, false);
    assert.equal(observation.remote_control_authorized, false);
    assert.equal(observation.commands_sent, false);
    assert.equal(observation.connectors.length, 1);
    assert.equal(observation.connectors[0].state, "recent");
    assert.equal(observation.connectors[0].reported_status, "Available");
    assert.equal(observation.connectors[0].physical_available, false);
    await liveSource.getByText("Recent source status: Available", { exact: true }).waitFor();
    assert.equal(simulator.commands.length, 0);

    assert.deepEqual(errors, []);
    console.log(
      "PASS real Laravel EN/AR390px vendor station-wide availability publish/cancel, owner-linked site navigation; charger identity, credential hide/revoke, unknown-command recovery, negative unbooted CSMS and positive authenticated 2.0.1 typed connector source status; no device commands, forced capacity release or physical acceptance.",
    );
  } finally {
    await simulator?.close();
    await browser.close();
  }
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
