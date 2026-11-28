// Actual Laravel + pinned local CitrineOS/Hasura metadata only; no charger socket.
const assert = require("node:assert/strict");
const { execFileSync } = require("node:child_process");
const { randomBytes, randomUUID } = require("node:crypto");
const fs = require("node:fs"),
  path = require("node:path");
const { tmpdir } = require("node:os");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");
const container =
  process.env.EV_SOURCE_REGISTRATION_API_CONTAINER || "ev-business-api";
assert(
  ["ev-business-api", "ev-agent1-charging"].includes(container),
  "Owned local API container required",
);
const mounts = JSON.parse(
  execFileSync(
    "docker",
    ["inspect", "--format", "{{json .Mounts}}", container],
    { encoding: "utf8" },
  ),
);
assert(
  mounts.some(
    (x) =>
      x.Destination === "/app" &&
      fs.realpathSync(x.Source) === fs.realpathSync(process.cwd()),
  ),
  "API must mount this exact checkout",
);
const base = "http://127.0.0.1:8000",
  run = randomBytes(4).toString("hex"),
  password = randomBytes(24).toString("hex") + "aA9!";
function fixture(mode) {
  return JSON.parse(
    execFileSync(
      "docker",
      [
        "exec",
        "-w",
        "/app/services/business-api",
        container,
        "php",
        "tests/Fixtures/charger-source-registration.php",
        mode,
        run,
        ...(mode === "seed" ? [password] : []),
      ],
      { encoding: "utf8" },
    ),
  );
}
async function login(email) {
  const c = new BusinessClient({ base });
  c.csrf = (await c.request("csrf")).token;
  c.user = await c.request("login", "POST", { email, password });
  c.csrf = (await c.request("csrf")).token;
  return c;
}
(async () => {
  const paced = process.env.EV_SOURCE_REGISTRATION_PACE === "1";
  const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
  if (paced) await waitForFreshWindow({ wait });
  const f = fixture("seed");
  assert(f.fixture_only);
  const before = fixture("snapshot");
  const inventoryOwner = await login(f.owner_email);
  const station = await inventoryOwner.request("stations", "POST", {
    name: "Direct registration " + run,
    country: "AE",
    currency: "AED",
    timezone: "Asia/Dubai",
    latitude: 25,
    longitude: 55,
    source: "direct_unverified",
    connector_type: "CCS2",
    power_kw: 50,
  });
  assert.equal(station.source, "direct_unverified");
  const companyBase = "vendor-ops/companies/" + f.company_id;
  const choices = await inventoryOwner.request(
    companyBase + "/catalog-stations?q=" + encodeURIComponent(station.name),
  );
  assert(
    choices.data.some((x) => x.id === station.id),
    "Owned direct catalog station must be linkable through normal API",
  );
  const site = await inventoryOwner.request(companyBase + "/sites", "POST", {
    name: "Direct source site",
    country: "AE",
    timezone: "Asia/Dubai",
    address: "Synthetic",
    latitude: 25,
    longitude: 55,
    station_id: station.id,
    idempotency_key: randomUUID(),
  });
  for (const charger of f.chargers) {
    const created = await inventoryOwner.request(
      companyBase + "/sites/" + site.id + "/chargers",
      "POST",
      {
        asset_reference: charger.asset,
        manufacturer: "Synthetic",
        model: "ContractFixture",
        source: "direct_unverified",
        ocpp_protocol: charger.protocol,
        idempotency_key: randomUUID(),
      },
    );
    charger.id = created.id;
  }
  if (process.env.EV_SOURCE_REGISTRATION_NATIVE_FIXTURE) {
    const out = path.resolve(process.env.EV_SOURCE_REGISTRATION_NATIVE_FIXTURE);
    assert(
      out.startsWith(path.resolve("artifacts") + path.sep),
      "Private synthetic native fixture must remain under ignored artifacts",
    );
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(
      out,
      JSON.stringify({
        fixture_only: true,
        base,
        owner: f.owner_email,
        password,
        company_id: f.company_id,
        chargers: f.chargers,
      }),
      { mode: 0o600 },
    );
  }
  const admin = await login(f.admin_email);
  // Exercise the real MFA endpoint even when the generic local gate is disabled.
  await admin.request("admin/second-factor/verify", "POST", fixture("mfa"));
  admin.csrf = (await admin.request("csrf")).token;
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const screenshots = path.join("artifacts", "charging-source-registration");
  fs.mkdirSync(screenshots, { recursive: true });
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    await context.addCookies(
      [...admin.cookies].map(([name, value]) => ({ name, value, url: base })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(base);
    const ops = page.locator("#vendor-operations");
    await ops
      .locator("article")
      .filter({
        has: page.getByRole("heading", { name: f.company_name, exact: true }),
      })
      .first()
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
    const ids = [];
    for (const charger of f.chargers) {
      const row = ops
        .locator("article")
        .filter({
          has: page.getByRole("heading", { name: charger.asset, exact: true }),
        })
        .last();
      await row
        .getByRole("button", { name: "Secure charger admission", exact: true })
        .click();
      const panel = row.locator("[data-charger-source-registration]");
      await panel
        .getByLabel("Charger source identity (maximum 36 characters)", {
          exact: true,
        })
        .fill(charger.identity);
      assert.equal(
        await row
          .locator('[data-admission] [data-vendor-form="Prepare identity"]')
          .count(),
        0,
      );
      await panel
        .getByRole("button", {
          name: "Prepare source registration",
          exact: true,
        })
        .click();
      await panel
        .getByText("Prepared; no source request sent", { exact: true })
        .waitFor();
      await panel.locator("input[type=checkbox]").check();
      await panel
        .getByRole("button", {
          name: "Register source metadata once",
          exact: true,
        })
        .click();
      await panel
        .getByText("Source record observed", { exact: true })
        .waitFor();
      const endpoint = `vendor-ops/companies/${f.company_id}/chargers/${charger.id}/source-registration`;
      const observed = await admin.request(endpoint);
      assert.equal(observed.state, "registered");
      assert.equal(observed.current_inventory_matches_registration, true);
      assert.equal(observed.device_commands_sent, 0);
      assert.equal(observed.charging_capability_granted, false);
      assert.equal(observed.charger_hardware_verified, false);
      assert.match(observed.verified_at, /Z$/);
      ids.push(observed.source_station_id);
      charger.verified_receipt = {
        registration_id: observed.registration_id,
        binding_id: observed.binding_id,
        profile_id: observed.profile_id,
        source_station_id: observed.source_station_id,
        verified_at: observed.verified_at,
      };
      const replay = await admin.request(
        endpoint + "/" + observed.registration_id + "/register",
        "POST",
      );
      assert.equal(replay.verified_at, observed.verified_at);
    }
    await page.screenshot({
      path: path.join(screenshots, "admin-en-390.png"),
      fullPage: true,
    });
    await page.locator("#language").click();
    await page
      .locator("[data-charger-source-registration]")
      .last()
      .getByText("تمت ملاحظة سجل المصدر", { exact: true })
      .waitFor();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "Source controls must fit390px RTL",
    );
    await page.screenshot({
      path: path.join(screenshots, "admin-ar-390.png"),
      fullPage: true,
    });
    const owner = await login(f.owner_email),
      ownerContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
      });
    await ownerContext.addCookies(
      [...owner.cookies].map(([name, value]) => ({ name, value, url: base })),
    );
    const ownerPage = await ownerContext.newPage();
    await ownerPage.goto(base);
    const ownerOps = ownerPage.locator("#vendor-operations");
    await ownerOps
      .locator("article")
      .filter({
        has: ownerPage.getByRole("heading", {
          name: f.company_name,
          exact: true,
        }),
      })
      .first()
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    await ownerOps
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
    await ownerOps
      .getByRole("button", { name: "Secure charger admission", exact: true })
      .first()
      .click();
    const readOnly = ownerOps.locator("[data-charger-source-registration]");
    await readOnly
      .getByText("Source record observed", { exact: true })
      .waitFor();
    assert.equal(await readOnly.locator("form,input").count(), 0);
    assert.equal(
      await readOnly
        .getByRole("button", {
          name: "Register source metadata once",
          exact: true,
        })
        .count(),
      0,
    );
    await ownerPage.screenshot({
      path: path.join(screenshots, "owner-read-only-390.png"),
      fullPage: true,
    });
    assert.deepEqual(errors, []);
    assert.deepEqual(
      fixture("snapshot"),
      before,
      "Metadata registration must not change commands/inbox/session/finance",
    );
    assert.equal(new Set(ids).size, 2);
    if (process.env.EV_RUN_AUTHENTICATED_NATIVE_REGISTRATION === "1") {
      // Use actual authenticated readbacks, never synthetic retained receipts.
      // The Flutter fixture is private, short-lived and not an uploaded artifact.
      const directory = fs.mkdtempSync(path.join(tmpdir(), "ev-native-source-registration-"));
      try {
        const file = path.join(directory, "fixture.json");
        fs.writeFileSync(file, JSON.stringify({
          fixture_only: true,
          base,
          owner: f.owner_email,
          password,
          company_id: f.company_id,
          chargers: f.chargers.map(({ id, protocol, verified_receipt }) => ({ id, protocol, verified_receipt })),
          authenticated_source_readback: true,
          expected_inventory_matches: true,
        }), { mode: 0o600, flag: "wx" });
        execFileSync("flutter", ["test", "--no-pub", "test/live_charger_source_registration_test.dart", "--reporter", "expanded"], {
          cwd: "apps/mobile", stdio: "inherit",
          env: { ...process.env, EV_NATIVE_SOURCE_REGISTRATION_FIXTURE: file },
        });
        for (const charger of f.chargers) {
          const observed = await admin.request(`${companyBase}/chargers/${charger.id}/source-registration`);
          for (const [key, value] of Object.entries(charger.verified_receipt)) {
            assert.equal(observed[key], value, `Native observation changed registration ${key}`);
          }
          assert.equal(observed.current_inventory_matches_registration, true);
        }
        assert.deepEqual(fixture("snapshot"), before);
        console.log("PASS actual native HTTP projection of both authenticated registration readbacks; retained receipt and charging/finance state unchanged.");
      } finally {
        fs.rmSync(directory, { recursive: true, force: true });
      }
    }
    console.log(
      "PASS actual Laravel+local CitrineOS/Hasura source registration1.6/2.0.1: real Admin MFA, canonical disabled admission/bindings, single source creation/readback and receipt replay, EN/AR390 web controls and read-only owner status; unchanged charging and finance. No charger sockets, device commands or hardware acceptance.",
    );
  } finally {
    await browser.close();
  }
  if (paced) await waitForFreshWindow({ wait });
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
