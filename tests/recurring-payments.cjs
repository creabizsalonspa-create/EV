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
      ["tests/Fixtures/recurring-payments.php", run, password],
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
    await page
      .getByRole("button", { name: "Open saved authorizations", exact: true })
      .click();
    await page
      .getByRole("heading", { name: "visa •••• 4242", exact: true })
      .waitFor();
    await page
      .getByRole("button", { name: "Revoke authorization", exact: true })
      .click();
    await page
      .getByRole("button", { name: "Remove from provider", exact: true })
      .waitFor();
    await page
      .getByRole("button", { name: "Renewal collection", exact: true })
      .click();
    await page
      .getByText("Automatic collection disabled", { exact: true })
      .waitFor();
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.dir === "rtl");
    await page.getByText("التحصيل التلقائي معطّل", { exact: true }).waitFor();
    await page
      .getByRole("button", { name: "سجل التفويض", exact: true })
      .click();
    await page.getByText("revoked", { exact: true }).waitFor();
    await page.locator("#logout").click();
    await page
      .locator("#notice")
      .getByText("تم تسجيل الخروج.", { exact: true })
      .waitFor();
    assert.equal(
      await page.getByText("visa •••• 4242", { exact: true }).count(),
      0,
    );
    assert.deepEqual(errors, []);
    // Isolated hosted-flow browser contracts. No external PSP request or credential.
    await page.goto("http://127.0.0.1:8000");
    await page.waitForFunction(
      () => typeof window.mountRecurringPayments === "function",
    );
    await page.evaluate(() => {
      document.body.replaceChildren();
      const root = document.createElement("section");
      document.body.append(root);
      window.EV_MESSAGES = {};
      window.contractCalls = [];
      const state = {
        methods: { data: [], current_page: 1, last_page: 1 },
        version: 0,
        consent_version: "recurring-v1",
        hosted_available: true,
        collection_available: true,
      };
      mountRecurringPayments({
        container: root,
        actor: () => ({ id: 99 }),
        ui: (tag, en) => {
          const n = document.createElement(tag);
          n.textContent = en;
          return n;
        },
        api: async (path, method = "GET", body) => {
          window.contractCalls.push({ path, method, body });
          if (method === "POST")
            return {
              id: 9,
              status: "pending",
              hosted_url: "https://checkout.stripe.com/c/pay/test",
            };
          if (path.includes("payment-methods")) return state;
          return {
            data: [{ id: 9, status: "pending", expires_at: "2030-01-01" }],
            current_page: 1,
            last_page: 1,
          };
        },
      });
    });
    await page
      .getByRole("button", { name: "Open saved authorizations", exact: true })
      .click();
    await page.getByRole("checkbox").check();
    await page
      .getByRole("button", { name: "Add with hosted provider", exact: true })
      .click();
    const link = page.getByRole("link", {
      name: "Continue securely with provider",
      exact: true,
    });
    await link.waitFor();
    assert.equal(
      await link.getAttribute("href"),
      "https://checkout.stripe.com/c/pay/test",
    );
    assert.equal(await link.getAttribute("rel"), "noopener noreferrer");
    const calls = await page.evaluate(() =>
      contractCalls.filter((c) => c.method === "POST"),
    );
    assert.equal(calls.length, 1);
    assert.equal(calls[0].body.consent, true);
    assert.equal("payment_method" in calls[0].body, false);
    console.log(
      "PASS recurring web: actual Laravel masked methods, versioned revocation, policy disable, consent audit, EN/AR390px RTL/logout; isolated hosted enrollment contract and safe external link. No live PSP acceptance.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
