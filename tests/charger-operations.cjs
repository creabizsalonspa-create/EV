const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  assert = require("node:assert/strict");
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
    const ops = page.locator("#vendor-operations");
    await ops
      .locator("article")
      .filter({ has: page.getByRole("heading", { name: f.name, exact: true }) })
      .first()
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    await ops
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
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
    assert.deepEqual(errors, []);
    console.log(
      "PASS real Laravel EN/AR390px charger identity preparation, one-time secret/hide/revoke, no browser credential storage, unknown-command case/source absence/audit; no resend, forced capacity release or physical acceptance.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
