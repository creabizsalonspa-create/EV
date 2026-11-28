const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
(async () => {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/reservation-lifecycle.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const expiry = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["artisan", "reservations:expire-unused", `--reservation=${f.unused}`],
      { encoding: "utf8" },
    ),
  );
  assert.equal(expiry.expired, 1);
  const arrivedExpiry = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["artisan", "reservations:expire-unused", `--reservation=${f.arrived_unused}`],
      { encoding: "utf8" },
    ),
  );
  assert.equal(arrivedExpiry.expired, 1);
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
      .fill(`canonical-customer-${run}@example.test`);
    await page.locator("#account [name=password]").fill(password);
    const logged = page.waitForResponse(
      (r) =>
        new URL(r.url()).pathname === "/api/login" &&
        r.request().method() === "POST",
    );
    await page.locator("button[value=login]").click();
    assert.equal((await logged).status(), 200);
    await page
      .locator("#notice")
      .getByText("Signed in.", { exact: true })
      .waitFor();
    const button = page
      .locator("#reservation-list")
      .getByRole("button", { name: "Check in", exact: true });
    await button.waitFor();
    const reservations = page.locator("#reservation-list");
    await reservations.getByText("No arrival was reported for this reservation", {exact: true}).waitFor();
    await reservations.getByText("Arrival was reported but the reservation went unused", {exact: true}).waitFor();
    await page.locator("#language").click();
    await reservations.getByText("لم يُبلّغ عن الوصول لهذا الحجز", {exact: true}).waitFor();
    await reservations.getByText("أُبلغ عن الوصول لكن الحجز لم يُستخدم", {exact: true}).waitFor();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.lang === "en");
    page.once("dialog", async (dialog) => {
      assert.match(dialog.message(), /self-reported/);
      await dialog.dismiss();
    });
    await button.click();
    const response = page.waitForResponse(
      (r) =>
        new URL(r.url()).pathname === `/api/reservations/${f.current}/check-in`,
    );
    page.once("dialog", (dialog) => dialog.accept());
    await button.click();
    const reply = await response;
    assert.equal(reply.status(), 201);
    const data = await reply.json();
    assert.equal(data.physical_presence_verified, false);
    await reservations.locator("article").filter({hasText: `#${f.current} ·`})
      .getByText(/Self-reported arrival/)
      .waitFor();
    assert.equal(
      await page
        .locator("#reservation-list")
        .getByRole("button", { name: "Check in", exact: true })
        .count(),
      0,
    );
    assert(
      await page
        .locator("#reservation-list")
        .innerText()
        .then((t) => t.includes("Reservation window ended")),
    );
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS: real-Laravel unused expiry, distinct EN/AR arrival outcomes, explicit self-reported check-in and authoritative refresh at 390px",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
