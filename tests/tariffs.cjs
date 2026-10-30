const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const station = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/tariffs.php", run, password],
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
    async function login(role) {
      const context = await browser.newContext();
      context.setDefaultTimeout(15000);
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`pricing-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      for (let attempt = 0; attempt < 2; attempt++) {
        const ready = p.waitForResponse(
          (r) =>
            new URL(r.url()).pathname === "/api/login" &&
            r.request().method() === "POST",
        );
        await p.locator("button[value=login]").click();
        const response = await ready;
        if (response.status() === 429 && attempt === 0) {
          const h = response.headers()["retry-after"];
          assert(/^\d+$/.test(h || ""));
          assert(Number(h) <= 60);
          await p.waitForTimeout((Number(h) + 1) * 1000);
          continue;
        }
        assert.equal(response.status(), 200);
        break;
      }
      await p.locator("#signed").waitFor();
      return p;
    }
    const admin = await login("admin"),
      aroot = admin.locator("#tariff-operations");
    async function open(p) {
      const root = p.locator("#tariff-operations");
      const card = root
        .locator("article")
        .filter({
          has: p.getByRole("heading", { name: station.name, exact: true }),
        })
        .first();
      await card
        .getByRole("button", { name: "Manage pricing", exact: true })
        .click();
      await root.locator("[data-tariff-form]").waitFor();
      return root.locator("[data-tariff-detail]");
    }
    let detail = await open(admin),
      form = detail.locator("form");
    await form.locator("[name=price]").fill("1.25");
    await form.locator("[name=tax]").fill("5");
    await form.locator("[name=commission]").fill("12.5");
    await form
      .getByRole("button", { name: "Publish pricing version", exact: true })
      .click();
    await detail
      .getByText("Tax: 5.00% · Commission: 12.50%", { exact: true })
      .waitFor();
    const vendor = await login("vendor"),
      vroot = vendor.locator("#tariff-operations");
    await vroot
      .getByText("Page 1 of 2 · 26 records", { exact: true })
      .waitFor();
    await vroot.getByRole("button", { name: "Next", exact: true }).click();
    await vroot
      .getByText("Page 2 of 2 · 26 records", { exact: true })
      .waitFor();
    await vroot.getByRole("button", { name: "Previous", exact: true }).click();
    detail = await open(vendor);
    form = detail.locator("form");
    assert.equal(await form.locator("[name=commission]").count(), 0);
    await form.locator("[name=price]").fill("٢٫٥٠");
    await form.locator("[name=tax]").fill("٥");
    await form
      .getByRole("button", { name: "Publish pricing version", exact: true })
      .click();
    await detail.getByText("2.50 AED / kWh", { exact: true }).waitFor();
    assert.equal(
      await detail
        .getByText("Tax: 5.00% · Commission: 12.50%", { exact: true })
        .count(),
      2,
    );
    assert.equal(await vroot.locator("img").count(), 0);
    form = detail.locator("form");
    await form.locator("[name=price]").fill("2.75");
    let lostBody,
      publishedId,
      posts = 0;
    const pattern = `**/api/stations/${station.id}/tariffs`;
    await vendor.route(pattern, async (route) => {
      if (route.request().method() !== "POST") return route.continue();
      posts++;
      lostBody = route.request().postDataJSON();
      const response = await route.fetch();
      assert.equal(response.status(), 201);
      publishedId = (await response.json()).id;
      await route.abort("failed");
    });
    await form
      .getByRole("button", { name: "Publish pricing version", exact: true })
      .click();
    await detail
      .getByText(
        "Refresh pricing history before submitting again. The previous version may already have been saved.",
        { exact: true },
      )
      .waitFor();
    assert.equal(
      await form
        .getByRole("button", { name: "Publish pricing version", exact: true })
        .isDisabled(),
      true,
    );
    assert.equal(posts, 1);
    const token = (await (await vendor.request.get(base + "/api/csrf")).json())
      .token;
    const replay = await vendor.request.post(
      base + `/api/stations/${station.id}/tariffs`,
      {
        headers: { Accept: "application/json", "X-CSRF-TOKEN": token },
        data: lostBody,
      },
    );
    assert.equal(replay.status(), 201);
    assert.equal((await replay.json()).id, publishedId);
    await vendor.unroute(pattern);
    await detail
      .getByRole("button", { name: "Refresh pricing history", exact: true })
      .click();
    await detail.getByText("2.75 AED / kWh", { exact: true }).waitFor();
    const versions = await (
      await vendor.request.get(base + `/api/stations/${station.id}/tariffs`)
    ).json();
    assert.equal(versions.total, 3);
    assert(
      versions.data.every(
        (v) => v.commission_bps === 1250 && !Object.hasOwn(v, "publishing_key"),
      ),
    );
    await vendor.locator("#language").click();
    await vendor.setViewportSize({ width: 390, height: 844 });
    await vroot
      .getByRole("heading", { name: "التسعير التجريبي", exact: true })
      .waitFor();
    assert(
      await vendor.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    );
    await vendor.locator("#logout").click();
    await vendor.waitForFunction(
      () => document.querySelector("#tariff-operations").hidden,
    );
    assert.equal(await vroot.textContent(), "");
    const other = await login("other");
    assert.equal(
      (
        await other.request.get(base + `/api/stations/${station.id}/tariffs`)
      ).status(),
      404,
    );
    const customer = await login("customer");
    assert.equal(
      (await customer.request.get(base + "/api/finance/stations")).status(),
      403,
    );
    assert.equal(await customer.locator("#tariff-operations").isHidden(), true);
    assert.deepEqual(errors, []);
    console.log(
      "PASS: real Laravel paginated pricing inventory, admin commission retention, exact Arabic price/tax publishing, committed lost-ack idempotency, immutable three-version history, role/tenant isolation, RTL phone layout and logout cleanup.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
