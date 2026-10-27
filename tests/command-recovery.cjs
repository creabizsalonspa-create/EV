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
      ["tests/Fixtures/command-recovery.php", run, password],
      { encoding: "utf8" },
    ),
  );
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
    const card = page
      .locator("#simulation-list article")
      .filter({ hasText: `#${f.session_id} ·` });
    await card
      .getByText("Start transport · Outcome unknown", { exact: true })
      .waitFor();
    const ready = page.waitForResponse(
      (r) =>
        new URL(r.url()).pathname ===
        `/api/charging-sessions/${f.session_id}/reconcile`,
    );
    await card
      .getByRole("button", { name: "Reconcile received evidence", exact: true })
      .click();
    const reply = await ready;
    assert.equal(reply.status(), 200);
    const result = await reply.json();
    assert.equal(result.reconciled, 1);
    assert.equal(result.network_requests, 0);
    assert.equal(result.command_acceptance_verified, false);
    await card
      .getByText("Start transport · Source outcome observed", { exact: true })
      .waitFor();
    await card.getByText(/does not prove command acceptance/).waitFor();
    assert.equal(
      await card
        .getByRole("button", { name: "Reconcile received evidence" })
        .count(),
      0,
    );
    await page.locator("#language").click();
    await card
      .getByText("نقل طلب البدء · رُصدت النتيجة في المصدر", { exact: true })
      .waitFor();
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      ),
      false,
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS: real-Laravel scoped reconciliation of synthetic committed inbox evidence; no network/device command, no acceptance claim; EN/AR 390px status workflow",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
