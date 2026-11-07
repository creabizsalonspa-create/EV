const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  assert = require("node:assert/strict");
(async () => {
  const base = "http://127.0.0.1:8000",
    run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/canonical-topology.php", run, password],
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
    async function action(p, path, method, click, expected = 200) {
      const pending = p.waitForResponse(
        (r) =>
          new URL(r.url()).pathname === path && r.request().method() === method,
      );
      await click();
      const r = await pending;
      assert.equal(r.status(), expected, path);
      return r;
    }
    async function login(role) {
      const ctx = await browser.newContext();
      ctx.setDefaultTimeout(15000);
      const p = await ctx.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`canonical-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      for (let attempt = 0; attempt < 2; attempt++) {
        const ready = p.waitForResponse(
          (r) =>
            new URL(r.url()).pathname === "/api/login" &&
            r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        const reply = await ready;
        if (reply.status() === 429 && attempt === 0) {
          const seconds = reply.headers()["retry-after"];
          assert(/^\d+$/.test(seconds || "") && Number(seconds) <= 60);
          await p.waitForTimeout((Number(seconds) + 1) * 1000);
          continue;
        }
        assert.equal(reply.status(), 200);
        break;
      }
      await p.locator("#signed").waitFor();
      await p
        .locator("#notice")
        .getByText("Signed in.", { exact: true })
        .waitFor();
      return p;
    }
    const owner = await login("owner"),
      ops = owner.locator("#vendor-operations");
    const company = ops
      .locator("article")
      .filter({
        has: owner.getByRole("heading", { name: f.name, exact: true }),
      })
      .first();
    await company
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Canonical EVSE mapping", exact: true })
      .click();
    const pane = ops.locator("[data-topology]"),
      evses = [];
    const prefix = `/api/vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}`;
    for (const number of [1, 2]) {
      const form = pane.locator('[data-vendor-form="Create EVSE"]');
      await form.locator("[name=number]").fill(String(number));
      owner.once("dialog", (d) => d.accept());
      evses.push(
        await (
          await action(
            owner,
            prefix + "/evses",
            "POST",
            () =>
              form.getByRole("button", { name: "Save", exact: true }).click(),
            201,
          )
        ).json(),
      );
    }
    const maps = [];
    for (let i = 0; i < 3; i++) {
      const evse = evses[i === 2 ? 1 : 0],
        row = pane.locator(`[data-evse-id="${evse.id}"]`);
      await row
        .getByRole("button", { name: "Map connector", exact: true })
        .click();
      const form = row.locator(
        `[data-connector-id="${f.connectors[i]}"] [data-vendor-form="Map connector"]`,
      );
      owner.once("dialog", (d) => d.accept());
      maps.push(
        await (
          await action(
            owner,
            `${prefix}/evses/${evse.id}/connectors/${f.connectors[i]}/mapping`,
            "POST",
            () =>
              form.getByRole("button", { name: "Save", exact: true }).click(),
            201,
          )
        ).json(),
      );
    }
    const customer = await login("customer");
    await customer.locator("#filters [name=q]").fill(f.name);
    await action(customer, "/api/stations", "GET", () =>
      customer.locator("#filters button").click(),
    );
    const card = customer.locator(
      `#stations [data-station-id="${f.station_id}"]`,
    );
    await card
      .getByRole("button", { name: "Choose connector", exact: true })
      .click();
    await card.locator("[data-mapping-id]").first().waitFor();
    const start = new Date(Date.now() + 86400000);
    start.setUTCMinutes(0, 0, 0);
    const slot = start.toISOString().slice(0, 16);
    async function reserve(index, status = 201) {
      const form = card.locator(`[data-mapping-id="${maps[index].id}"] form`);
      await form.locator("input[type=datetime-local]").fill(slot);
      return (
        await action(
          customer,
          "/api/reservations",
          "POST",
          () =>
            form
              .getByRole("button", {
                name: "Reserve simulator slot",
                exact: true,
              })
              .click(),
          status,
        )
      ).json();
    }
    const first = await reserve(0);
    await reserve(2);
    await reserve(1, 409);
    const reserved = await (
      await customer.request.get(base + "/api/reservations")
    ).json();
    assert.equal(reserved.total, 2);
    assert.notEqual(
      reserved.data[0].charging_evse_id,
      reserved.data[1].charging_evse_id,
    );
    const booking = customer
      .locator("#reservation-list article")
      .filter({ hasText: "#" + first.id + " ·" });
    await action(customer, "/api/reservations/" + first.id, "DELETE", () =>
      booking.getByRole("button", { name: "Cancel", exact: true }).click(),
    );
    await reserve(1);
    await customer.locator("#language").click();
    await customer.setViewportSize({ width: 390, height: 844 });
    assert.equal(await customer.locator("html").getAttribute("dir"), "rtl");
    await card
      .getByRole("button", { name: "اختيار الموصل", exact: true })
      .waitFor();
    assert(
      await customer.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await customer.locator("#logout").click();
    await customer.locator("#account").waitFor();
    assert.equal(await card.locator("form").count(), 0);
    assert.deepEqual(errors, []);
    console.log(
      "PASS: actual Laravel vendor creates EVSEs and immutable connector maps; customer explicitly selects resources, parallel EVSE slots succeed, sibling connector conflict, cancellation releases capacity; Arabic phone layout and logout remove actions. Simulation only.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
