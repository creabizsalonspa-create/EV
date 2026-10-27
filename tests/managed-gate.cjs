const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/managed-gate.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  try {
    async function action(page, path, method, click, expected = 200) {
      for (let attempt = 0; attempt < 2; attempt++) {
        const ready = page.waitForResponse(
          (r) =>
            new URL(r.url()).pathname === path &&
            r.request().method() === method,
        );
        await click();
        const r = await ready;
        if (r.status() === 429 && attempt === 0) {
          const h = r.headers()["retry-after"];
          assert(/^\d+$/.test(h || "") && Number(h) <= 60);
          await page.waitForTimeout((Number(h) + 1) * 1000);
          continue;
        }
        assert.equal(r.status(), expected, path);
        return r;
      }
    }
    async function login(role) {
      const context = await browser.newContext();
      context.setDefaultTimeout(15000);
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`gate-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      await action(p, "/api/login", "POST", () =>
        p.locator("button[value=login]").click(),
      );
      await p.locator("#signed").waitFor();
      return p;
    }
    const owner = await login("owner"),
      customer = await login("customer"),
      company = owner.locator("#vendor-operations");
    const card = company
      .locator("article")
      .filter({
        has: owner.getByRole("heading", { name: fixture.name, exact: true }),
      })
      .first();
    await card
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    const form = (title) =>
      company.locator('[data-vendor-form="' + title + '"]');
    const site = form("Create site"),
      selector = site.locator("[name=station_id]");
    await selector
      .locator(`option[value="${fixture.station_id}"]`)
      .waitFor({ state: "attached" });
    await selector.selectOption(String(fixture.station_id));
    await owner.locator("#language").click();
    assert.equal(
      await selector.locator("option:checked").textContent(),
      "Create fleet · AE",
    );
    await owner.locator("#language").click();
    await site.locator("[name=name]").fill("Linked simulator site");
    await site.locator("[name=address]").fill("Synthetic address");
    const path = "/api/vendor-ops/companies/" + fixture.company_id;
    const created = await (
      await action(
        owner,
        path + "/sites",
        "POST",
        () => site.getByRole("button", { name: "Save", exact: true }).click(),
        201,
      )
    ).json();
    assert.equal(Number(created.station_id), fixture.station_id);
    async function refresh() {
      await action(customer, "/api/stations", "GET", () =>
        customer.locator("#refresh-catalog").click(),
      );
    }
    const station = customer.locator(
      `#stations [data-station-id="${fixture.station_id}"]`,
    );
    await refresh();
    await station
      .getByText(
        "Reservations and new starts are temporarily unavailable for this managed simulator.",
        { exact: true },
      )
      .waitFor();
    assert.equal(
      await station
        .getByRole("button", { name: "Reserve simulator slot", exact: true })
        .count(),
      0,
    );
    await company
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
    const charger = form("Create charger");
    await charger.locator("[name=asset_reference]").fill("GATE-" + run);
    await charger.locator("[name=manufacturer]").fill("Synthetic");
    await charger.locator("[name=model]").fill("Fixture");
    await charger.locator("[name=source]").selectOption("simulator");
    const chargerData = await (
      await action(
        owner,
        path + "/sites/" + created.id + "/chargers",
        "POST",
        () =>
          charger.getByRole("button", { name: "Save", exact: true }).click(),
        201,
      )
    ).json();
    await company
      .getByRole("button", { name: "Open connectors", exact: true })
      .click();
    const connector = form("Create connector");
    await connector.locator("[name=connector_number]").fill("1");
    await connector.locator("[name=max_power_kw]").fill("50");
    const connectorData = await (
      await action(
        owner,
        path + "/chargers/" + chargerData.id + "/connectors",
        "POST",
        () =>
          connector.getByRole("button", { name: "Save", exact: true }).click(),
        201,
      )
    ).json();
    await refresh();
    await station
      .getByRole("button", { name: "Reserve simulator slot", exact: true })
      .waitFor();
    const maintenance = form("Set maintenance");
    await maintenance
      .locator("[name=maintenance_reason]")
      .fill("Private synthetic maintenance");
    await maintenance
      .locator("[name=maintenance_blocked]")
      .selectOption("true");
    await action(
      owner,
      path +
        "/chargers/" +
        chargerData.id +
        "/connectors/" +
        connectorData.id +
        "/maintenance",
      "PATCH",
      () =>
        maintenance.getByRole("button", { name: "Save", exact: true }).click(),
    );
    await refresh();
    await station
      .getByText(
        "Reservations and new starts are temporarily unavailable for this managed simulator.",
        { exact: true },
      )
      .waitFor();
    assert.equal(
      await station
        .getByRole("button", { name: "Reserve simulator slot", exact: true })
        .count(),
      0,
    );
    assert.equal(
      await station
        .getByText("Private synthetic maintenance", { exact: true })
        .count(),
      0,
    );
    await customer.locator("#language").click();
    await customer.setViewportSize({ width: 390, height: 844 });
    await station
      .getByText(
        "الحجوزات وبدء الجلسات الجديدة غير متاحة مؤقتاً لهذه المحطة التجريبية المُدارة.",
        { exact: true },
      )
      .waitFor();
    assert(
      await customer.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    );
    // Recover policy, reserve through the real UI, then move only this synthetic booking into its active slot.
    await maintenance
      .locator("[name=maintenance_blocked]")
      .selectOption("false");
    await maintenance
      .locator("[name=maintenance_reason]")
      .fill("Synthetic recovery for cancellation QA");
    await action(
      owner,
      path +
        "/chargers/" +
        chargerData.id +
        "/connectors/" +
        connectorData.id +
        "/maintenance",
      "PATCH",
      () =>
        maintenance.getByRole("button", { name: "Save", exact: true }).click(),
    );
    await customer.locator("#language").click();
    await customer.setViewportSize({ width: 1100, height: 850 });
    await refresh();
    const start = new Date(Date.now() + 3600000);
    start.setUTCMinutes(0, 0, 0);
    await station
      .locator("input[type=datetime-local]")
      .fill(start.toISOString().slice(0, 16));
    const booking = await (
      await action(
        customer,
        "/api/reservations",
        "POST",
        () =>
          station
            .getByRole("button", {
              name: "Reserve simulator slot",
              exact: true,
            })
            .click(),
        201,
      )
    ).json();
    assert(Number.isSafeInteger(booking.id));
    execFileSync("./scripts/php", [
      "-r",
      `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
       if (!$app->environment('local') || config('database.default') !== 'sqlite') throw new RuntimeException('Local SQLite only');
       $r=App\\Models\\Reservation::findOrFail(${booking.id}); $s=App\\Models\\Station::findOrFail($r->station_id);
       if ($s->id !== ${fixture.station_id} || App\\Models\\User::findOrFail($s->vendor_id)->email !== 'gate-owner-${run}@example.test' || App\\Models\\User::findOrFail($r->user_id)->email !== 'gate-customer-${run}@example.test') throw new RuntimeException('Synthetic identity mismatch');
       $now=Carbon\\CarbonImmutable::now('UTC');$r->update(['starts_at'=>$now->startOfHour()->addMinutes(intdiv($now->minute,30)*30)->format('Y-m-d H:i:s')]);
       Illuminate\\Support\\Facades\\DB::table('csms_bindings')->insert(['station_id'=>$s->id,'identifier'=>'ev-test-managed-gate-${run}','protocol'=>'1.6','created_at'=>now(),'updated_at'=>now()]);`,
    ]);
    await customer.reload();
    const bookingCard = customer
      .locator("#reservation-list article")
      .filter({ hasText: "#" + booking.id + " ·" });
    const queued = await (
      await action(
        customer,
        "/api/reservations/" + booking.id + "/csms-start",
        "POST",
        () =>
          bookingCard
            .getByRole("button", {
              name: "Request CSMS simulator start",
              exact: true,
            })
            .click(),
        202,
      )
    ).json();
    const sessionCard = customer
      .locator("#simulation-list article")
      .filter({ hasText: "#" + queued.session.id + " ·" });
    customer.once("dialog", (d) => d.accept());
    await action(customer, "/api/reservations/" + booking.id, "DELETE", () =>
      sessionCard
        .getByRole("button", { name: "Cancel undispatched start", exact: true })
        .click(),
    );
    await sessionCard
      .getByText(
        new RegExp("^SIMULATED · #" + queued.session.id + " · cancelled ·"),
      )
      .waitFor();
    await sessionCard
      .getByText("Start transport · cancelled", { exact: true })
      .waitFor();
    const sessions = await (
      await customer.request.get(base + "/api/simulations")
    ).json();
    assert.equal(sessions.data[0].status, "cancelled");
    assert.equal(sessions.data[0].start_command_status, "cancelled");
    assert.equal(sessions.data[0].can_cancel_before_dispatch, false);
    const expiryFixture = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/unsent-expiry.php", run, String(fixture.station_id)],
        { encoding: "utf8" },
      ),
    );
    const expiryResult = JSON.parse(
      execFileSync(
        "./scripts/php",
        [
          "artisan",
          "csms:expire-unsent",
          "--reservation=" + expiryFixture.reservation_id,
        ],
        { encoding: "utf8" },
      ),
    );
    assert.equal(expiryResult.expired, 1);
    assert.equal(expiryResult.evidence, "SIMULATED_CSMS");
    const replay = JSON.parse(
      execFileSync(
        "./scripts/php",
        [
          "artisan",
          "csms:expire-unsent",
          "--reservation=" + expiryFixture.reservation_id,
        ],
        { encoding: "utf8" },
      ),
    );
    assert.equal(replay.expired, 0);
    await customer.reload();
    const expiredCard = customer
      .locator("#simulation-list article")
      .filter({ hasText: "#" + expiryFixture.session_id + " ·" });
    await expiredCard
      .filter({ hasText: "Expired before charging started" })
      .waitFor();
    assert.equal(await expiredCard.getByRole("button").count(), 0);
    await customer.locator("#language").click();
    await expiredCard
      .filter({ hasText: "انتهى الحجز قبل بدء الشحن" })
      .waitFor();
    await owner.reload();
    await owner
      .locator("#simulation-list article")
      .filter({ hasText: "#" + expiryFixture.session_id + " ·" })
      .filter({ hasText: "Expired before charging started" })
      .waitFor();
    assert.deepEqual(errors, []);
    console.log(
      "PASS: real Laravel owner-scoped catalog linking, literal station names across Arabic, empty-site admission block, matching connector recovery and maintenance blocking in customer UI; no private reasons leaked; RTL phone layout; actual pending-CSMS cancellation and targeted CLI expiry without dispatch; idempotent replay and shared customer/vendor EN/AR terminal state.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
