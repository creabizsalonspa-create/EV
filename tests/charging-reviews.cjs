const { execFileSync, spawnSync } = require("node:child_process");
const { randomBytes, randomUUID } = require("node:crypto");
const fs = require("node:fs"),
  path = require("node:path"),
  os = require("node:os");
const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
function verifyRegionalReceiptParser() {
  const source = fs.readFileSync("services/business-api/public/platform.js", "utf8");
  const start = source.indexOf("function verifyRegionalConfigurationReceipt(");
  const end = source.indexOf('el("region-config").onsubmit', start);
  assert(start >= 0 && end > start);
  const verify = new Function(source.slice(start, end) + "\nreturn verifyRegionalConfigurationReceipt;")();
  const reviewed = {currency: "AED", minor_units: 2, timezone: "Asia/Dubai", tax_bps: 0, commerce_enabled: false};
  const values = {currency: "AED", minor_units: "2", timezone: "Asia/Dubai", tax_bps: "0", expected_revision: 3};
  const saved = {country: "AE", ...reviewed, configuration_revision: 3};
  assert.equal(verify(saved, "AE", values, reviewed).tax_bps, 0);
  assert.equal(verify({...saved, configuration_revision: 4, tax_bps: null}, "AE",
    {...values, tax_bps: null}, reviewed).tax_bps, null);
  assert.equal(verify({...saved, configuration_revision: 1}, "AE",
    {...values, expected_revision: 0}, null).currency, "AED");
  const invalid = [
    [{...saved, configuration_revision: 4}, values, reviewed],
    [{...saved, tax_bps: null}, {...values, tax_bps: null}, reviewed],
    [{...saved, configuration_revision: 3}, values, null],
    [{...saved, commerce_enabled: true}, values, reviewed],
    [{...saved, country: "US"}, values, reviewed],
    [{...saved, tax_bps: null}, values, reviewed],
    [{...saved, configuration_revision: 0}, {...values, expected_revision: 0}, null],
    [{...saved, configuration_revision: 5}, values, reviewed],
  ];
  for (const [receipt, request, before] of invalid)
    assert.throws(() => verify(receipt, "AE", request, before), /Regional configuration receipt cannot be verified/);
  console.log("PASS regional receipt parser: 3 valid and " + invalid.length + " rejected cases");
}
async function main() {
  verifyRegionalReceiptParser();
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const args = ["tests/Fixtures/charging-reviews.php", run, password];
  const local = process.env.EV_LOCAL_PHP_EXEC === "1";
  const f = JSON.parse(
    execFileSync(
      local ? "docker" : "./scripts/php",
      local
        ? [
            "exec",
            "-u",
            "1000:1000",
            "-w",
            "/app/services/business-api",
            "ev-business-api",
            "php",
            ...args,
          ]
        : args,
      { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
    ),
  );
  if (process.env.EV_REVIEWS_NATIVE_ONLY === "1") {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), "ev-feedback-native-"));
    try {
      const input = path.join(dir, "fixture.json");
      fs.writeFileSync(input, JSON.stringify(f), { mode: 0o600 });
      const result = spawnSync(
        "flutter",
        [
          "test",
          "test/live_charging_reviews_test.dart",
          "--reporter",
          "expanded",
        ],
        {
          cwd: "apps/mobile",
          stdio: "inherit",
          env: { ...process.env, EV_NATIVE_REVIEWS_FIXTURE: input },
        },
      );
      assert.equal(result.status, 0, "Native feedback HTTP proof failed");
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
    return;
  }
  const login = async (role) => {
    const c = new BusinessClient();
    c.csrf = (await c.request("csrf")).token;
    await c.request("login", "POST", f.accounts[role]);
    c.csrf = (await c.request("csrf")).token;
    return c;
  };
  const customer = await login("customer"),
    admin = await login("admin");
  const { chromium } = require("playwright");
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
      [...customer.cookies].map(([name, value]) => ({
        name,
        value,
        url: customer.base,
      })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(customer.base);
    const root = page.locator("#charging-reviews");
    await root
      .getByRole("button", {
        name: "Completed sessions for feedback",
        exact: true,
      })
      .click();
    await root
      .getByRole("button", { name: "Write feedback", exact: true })
      .click();
    const form = root.locator("[data-review-editor]");
    await form.locator("[name=rating]").selectOption("4");
    await form
      .locator("[name=body]")
      .fill("Synthetic helpful feedback <script>plain text only</script>");
    await form.getByRole("checkbox").check();
    await form
      .getByRole("button", { name: "Send for moderation", exact: true })
      .click();
    await root
      .getByRole("button", { name: "My feedback", exact: true })
      .click();
    await root
      .getByText(
        "Synthetic helpful feedback <script>plain text only</script>",
        { exact: true },
      )
      .waitFor();
    let row = (await customer.request("charging-reviews/mine")).data[0];
    assert.equal(row.state, "pending");
    assert.equal(row.evidence, "SIMULATED_APP_ONLY");
    assert.equal(
      (await customer.request(`stations/${f.station_id}/reviews`)).total,
      0,
    );
    // Admin uses the actual independent moderation screen, without driver controls.
    const adminContext = await browser.newContext();
    await adminContext.addCookies(
      [...admin.cookies].map(([name, value]) => ({
        name,
        value,
        url: admin.base,
      })),
    );
    const ap = await adminContext.newPage();
    await ap.goto(admin.base);
    const editRegion = ap.locator('[data-region-configuration-edit="AE"]');
    // Exercise the versioned editor against the real current region with
    // a no-op save; no regional tax/currency values are changed.
    await editRegion.click();
    const regionalForm = ap.locator("#region-config");
    const revision = await regionalForm.getAttribute("data-expected-revision");
    assert(Number(revision) >= 1);
    const savedRegion = ap.waitForResponse((response) =>
      response.request().method() === "PUT" &&
      new URL(response.url()).pathname === "/api/regions/AE",
    );
    await regionalForm.locator("button").click();
    const receipt = await savedRegion;
    assert.equal(receipt.status(), 200);
    assert.equal(String((await receipt.json()).configuration_revision), revision);
    await editRegion.waitFor();
    await ap.waitForFunction(() => !document.querySelector("#region-config").inert);
    const wrongZone = ap.waitForResponse((response) =>
      response.request().method() === "PUT" &&
      new URL(response.url()).pathname === "/api/regions/AE",
    );
    await regionalForm.locator('[name="timezone"]').fill("America/Toronto");
    await regionalForm.locator("button").click();
    assert.equal((await wrongZone).status(), 422);
    await ap.getByText("Choose a valid time zone for the selected country.", {exact: true}).waitFor();
    await editRegion.click();
    assert.equal(await regionalForm.locator('[name="timezone"]').inputValue(), "Asia/Dubai");
    // A genuine no-op is not allowed to acknowledge a revision increment.
    // Alter only the response; the real API still receives the same no-op.
    await ap.route("**/api/regions/AE", async route => {
      if (route.request().method() !== "PUT") return route.continue();
      const response = await route.fetch();
      const data = await response.json();
      assert.equal(response.status(), 200);
      data.configuration_revision++;
      await route.fulfill({response, json: data});
    }, {times: 1});
    await regionalForm.locator("button").click();
    await ap.waitForFunction(() => !document.querySelector("#region-config").dataset.expectedRevision &&
      !document.querySelector("#region-config").inert);
    assert.equal(await regionalForm.getAttribute("data-expected-values"), null);
    await editRegion.click();
    assert.equal(await regionalForm.getAttribute("data-expected-revision"), revision);
    const intake = ap.locator('[data-region-review-intake="AE"]');
    await intake.filter({ hasText: /^Pause new charging feedback$/ }).click();
    await intake.filter({ hasText: /^Allow new charging feedback$/ }).waitFor();
    await ap.locator("#language").click();
    await intake.filter({ hasText: /^السماح باستقبال ملاحظات الشحن الجديدة$/ }).click();
    await intake.filter({ hasText: /^إيقاف استقبال ملاحظات الشحن الجديدة$/ }).waitFor();
    await ap.locator("#language").click();
    const adminRoot = ap.locator("#charging-reviews");
    const card = adminRoot
      .locator("article")
      .filter({
        hasText: "Synthetic helpful feedback <script>plain text only</script>",
      });
    await card
      .locator("textarea")
      .fill("Reviewed synthetic feedback for publication");
    await card.getByRole("button", { name: "Publish", exact: true }).click();
    await card.getByText("published", { exact: true }).waitFor();
    let pub = await customer.request(`stations/${f.station_id}/reviews`);
    assert.equal(pub.groups[1].count, 1);
    assert(!JSON.stringify(pub).includes("user_id"));
    assert(!JSON.stringify(pub).includes("session_id"));
    await root.getByRole("button", { name: "Refresh", exact: true }).click();
    await root
      .getByRole("button", { name: "Revise feedback", exact: true })
      .click();
    const revised = root.locator("[data-review-editor]");
    await revised
      .locator("[name=body]")
      .fill("Revised synthetic feedback, pending fresh moderation");
    await revised.getByRole("checkbox").check();
    await revised
      .getByRole("button", { name: "Send for moderation", exact: true })
      .click();
    await root.getByText("pending", { exact: true }).waitFor();
    assert.equal(
      (await customer.request(`stations/${f.station_id}/reviews`)).total,
      0,
    );
    await page.locator("#language").click();
    await root
      .getByRole("heading", { name: "ملاحظات الشحن", exact: true })
      .waitFor();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth + 1,
      ),
      false,
    );
    await page.locator("#language").click();
    await root
      .getByRole("button", { name: "Withdraw publication", exact: true })
      .click();
    await root.getByText("withdrawn", { exact: true }).waitFor();
    await root
      .getByRole("button", { name: "Feedback history", exact: true })
      .click();
    await root
      .getByText("Revised synthetic feedback, pending fresh moderation", {
        exact: true,
      })
      .waitFor();
    // Preserve a detached old callback and verify logout invalidates its
    // authority before any API dispatch; reset even unsaved form values.
    await editRegion.click();
    await regionalForm.locator('[name="currency"]').fill("ZZZ");
    await ap.evaluate(() => {
      window.oldRegionalIntake = document.querySelector('[data-region-review-intake="AE"]').onclick;
    });
    await ap.locator("#logout").click();
    await ap.waitForFunction(() => document.querySelector("#region-config").hidden);
    assert.equal(await regionalForm.locator('[name="currency"]').inputValue(), "");
    assert.equal(await regionalForm.getAttribute("data-expected-revision"), null);
    assert.equal(await editRegion.count(), 0);
    let oldDispatches = 0;
    await ap.route("**/api/regions/AE/review-intake", async (route) => {
      oldDispatches++;
      await route.abort();
    });
    await ap.evaluate(async () => { await window.oldRegionalIntake(); });
    assert.equal(oldDispatches, 0);
    await page.locator("#logout").click();
    await page.waitForFunction(
      () => document.querySelector("#charging-reviews").hidden,
    );
    assert.equal(await root.textContent(), "");
    assert.deepEqual(errors, []);
    console.log(
      "PASS completed app simulator -> consented private feedback -> independent Admin moderation -> public anonymous rating -> revised pending -> withdrawal/history, plain-text safety, Arabic phone and logout. No physical charging verification.",
    );
  } finally {
    await browser.close();
  }
}
main().catch((e) => {
  console.error(
    e.name === "AssertionError" || e.name === "TimeoutError"
      ? e.message
      : "Feedback integration failed; private fixture details withheld.",
  );
  process.exitCode = 1;
});
