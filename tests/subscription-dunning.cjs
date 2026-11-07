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
      ["tests/Fixtures/subscription-dunning.php", run, password],
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
      .fill(`invoices-customer-${run}@example.test`);
    await page.locator("#account [name=password]").fill(password);
    await page.locator("button[value=login]").click();
    await page
      .locator("#notice")
      .getByText("Signed in.", { exact: true })
      .waitFor();
    await page.locator('a[href="#commercial-finance"]').click();
    const panel = page.locator("[data-subscription-notices]");
    await panel.getByRole("heading", { name: f.number, exact: true }).waitFor();
    await panel.getByText("Payment due", { exact: true }).waitFor();
    assert((await panel.textContent()).includes("5.25"));
    assert.equal(
      await panel.getByRole("button", { name: /pay|charge|refund/i }).count(),
      0,
    );
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.dir === "rtl");
    await panel.getByText("دفعة مستحقة", { exact: true }).waitFor();
    await panel
      .getByRole("button", { name: "تحديث التنبيهات", exact: true })
      .click();
    await panel.getByRole("heading", { name: f.number, exact: true }).waitFor();
    await page.locator("#logout").click();
    await page
      .locator("#notice")
      .getByText("تم تسجيل الخروج.", { exact: true })
      .waitFor();
    assert.equal(await page.locator("[data-subscription-notices]").count(), 0);
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel scheduled subscription notice UI: authoritative invoice, no collection action, EN/AR390px RTL, refresh and logout privacy. No PSP or notification delivery claimed.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
