// Isolated browser interaction contracts only: all API responses below are simulated.
const { chromium } = require("playwright"),
  assert = require("node:assert/strict");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const page = await browser.newPage();
    await page.route("http://127.0.0.1:8000/component-test", (route) =>
      route.fulfill({
        contentType: "text/html",
        body: '<main id="host"><section id="disputes"></section></main>',
      }),
    );
    await page.goto("http://127.0.0.1:8000/component-test");
    await page.addScriptTag({
      path: "services/business-api/public/external-disputes.js",
    });
    await page.evaluate(() => {
      window.EV_MESSAGES = {};
      window.EvMoney = {
        format: (n, digits) => (n / 10 ** digits).toFixed(digits),
      };
      window.testActor = { id: 1, role: "admin" };
      window.calls = [];
      window.failEvidence = true;
      window.forgeScope = false;
      window.forgeCurrency = false;
      window.storedEvidence = [];
      const row = {
        id: 9,
        invoice_id: 8,
        currency: "AED",
        minor_units: 2,
        amount_minor: 300,
        status: "needs_response",
        urgency: "evidence_due_soon",
        provider_observation_stale: true,
        evidence_due_at: "2099-01-01T00:00:00Z",
        provider_observed_at: "2026-10-28T00:00:00Z",
        settlement_hold: true,
        requires_financial_resolution: false,
        deadline_passed: false,
        has_uncertain_evidence: false,
        evidence: "STRIPE_TEST_CONTRACT",
        real_money_moved: false,
      };
      mountExternalDisputes({
        container: document.querySelector("#disputes"),
        actor: () => testActor,
        ui: (tag, text) => {
          const el = document.createElement(tag);
          el.textContent = text;
          return el;
        },
        api: async (path, method = "GET", data) => {
          calls.push({ path, method, data });
          if (method === "POST" && path.endsWith("/evidence/41/retry"))
            storedEvidence[0].status = "confirmed";
          if (method === "POST" && path.endsWith("/evidence") && failEvidence)
            throw Error("Simulated uncertain evidence response");
          if (path.includes("?") || path === "commercial/external-disputes") {
            const query = new URL(path, "https://fixture.invalid/").searchParams;
            const deadline = path.includes("/deadline-queue?");
            const scope = {currency: query.get("currency"), invoice_id: query.has("invoice_id") ? Number(query.get("invoice_id")) : null};
            if (!deadline) Object.assign(scope, {status: query.get("status"), attention: query.get("attention") === "1"});
            const matches = (!scope.currency || scope.currency === row.currency) &&
                (!scope.invoice_id || scope.invoice_id === row.invoice_id) &&
                (!scope.status || scope.status === row.status);
            if (forgeScope) scope.currency = "EUR";
            return { data: matches ? [forgeCurrency ? {...row, currency: [row.currency]} : row] : [], total: matches ? 1 : 0,
              current_page: Number(query.get("page")), last_page: 1,
              per_page: Number(query.get("per_page")), scope,
              evidence: "STRIPE_TEST_CONTRACT", real_money_moved: false,
              ...(deadline ? {window_hours: Number(query.get("window_hours")), provider_actions_performed: false} : {}) };
          }
          return {
            dispute: row,
            events: [],
            evidence_submissions: storedEvidence,
          };
        },
      });
    });
    assert.equal(
      await page.evaluate(() => calls.length),
      0,
      "Component must load lazily",
    );
    // Admin-only operational triage is read-only and displays provider
    // freshness and due-date urgency without issuing a payment/evidence call.
    await page
      .getByRole("button", { name: "Review evidence deadlines", exact: true })
      .click();
    await page.locator('[data-dispute-deadline-id="9"]').waitFor();
    await page.getByText("Evidence due within 48 hours", { exact: true }).waitFor();
    await page.getByText("Provider observation may be stale; inspect before acting", { exact: true }).waitFor();
    const queueCalls = await page.evaluate(() => calls.slice());
    assert(queueCalls.some(c =>
      c.path.includes("/deadline-queue?window_hours=48") && c.method === "GET"));
    assert(queueCalls.every(c => c.method === "GET"));
    await page.locator('[name=dispute_currency]').fill("USD");
    assert.equal(await page.locator("[data-dispute-deadline-id]").count(), 0);
    await page.getByRole("button", { name: "Load provider disputes", exact: true }).click();
    await page.getByText("No provider disputes in this view.", { exact: true }).waitFor();
    await page.locator('[name=dispute_currency]').fill("AED");
    await page.locator('[name=dispute_invoice]').fill("8");
    await page.locator('[name=dispute_window]').selectOption("72");
    await page.getByRole("button", { name: "Review evidence deadlines", exact: true }).click();
    await page.getByText("Evidence due within 72 hours", { exact: true }).waitFor();
    const filtered = await page.evaluate(() => calls.at(-1).path);
    assert(filtered.includes("currency=AED") && filtered.includes("invoice_id=8") && filtered.includes("window_hours=72"));
    await page.locator('[name=dispute_status]').selectOption("needs_response");
    await page.evaluate(() => forgeScope = true);
    await page.getByRole("button", { name: "Load provider disputes", exact: true }).click();
    await page.getByText("Provider review scope cannot be verified.", { exact: true }).waitFor();
    assert.equal(await page.locator("[data-dispute-id]").count(), 0);
    await page.evaluate(() => forgeScope = false);
    await page.locator('[name=dispute_currency]').fill("");
    await page.evaluate(() => forgeCurrency = true);
    await page.getByRole("button", { name: "Load provider disputes", exact: true }).click();
    await page.getByRole("status").getByText("Provider dispute receipt cannot be verified.", { exact: true }).waitFor();
    assert.equal(await page.locator("[data-dispute-id]").count(), 0);
    await page.evaluate(() => forgeCurrency = false);
    await page.locator('[name=dispute_currency]').fill("AED");
    await page
      .getByRole("button", { name: "Load provider disputes", exact: true })
      .click();
    await page
      .getByRole("button", { name: "Review dispute", exact: true })
      .click();
    await page
      .locator("textarea")
      .fill("<img src=x onerror=alert(1)> Literal private evidence");
    await page
      .getByRole("button", { name: "Send TEST evidence", exact: true })
      .click();
    await page
      .getByRole("status")
      .getByText("Simulated uncertain evidence response", { exact: true })
      .waitFor();
    assert.equal(await page.locator("img").count(), 0);
    // Read-only inspection redraws the form; its original uncertain key must survive.
    await page
      .getByRole("button", { name: "Inspect provider outcome", exact: true })
      .click();
    assert.equal(
      await page.locator("textarea").inputValue(),
      "<img src=x onerror=alert(1)> Literal private evidence",
    );
    await page.evaluate(() => (failEvidence = false));
    await page
      .getByRole("button", { name: "Send TEST evidence", exact: true })
      .click();
    const attempts = await page.evaluate(() =>
      calls.filter((call) => call.path.endsWith("/evidence")),
    );
    assert.equal(attempts.length, 2);
    assert.equal(
      attempts[0].data.idempotency_key,
      attempts[1].data.idempotency_key,
    );
    assert.deepEqual(attempts[0].data, attempts[1].data);
    assert.equal(
      await page.locator("textarea").inputValue(),
      "",
      "Confirmed evidence must clear private draft",
    );
    await page.evaluate(() => {
      storedEvidence = [
        {
          id: 41,
          status: "uncertain",
          retry_until: "2099-01-01T00:00:00Z",
          created_at: "2026-10-28T00:00:00Z",
        },
      ];
    });
    await page
      .getByRole("button", { name: "Inspect provider outcome", exact: true })
      .click();
    await page
      .getByRole("button", { name: "Retry stored TEST evidence", exact: true })
      .click();
    await page.waitForFunction(() => storedEvidence[0].status === "confirmed");
    const retry = await page.evaluate(() =>
      calls.filter((c) => c.path.endsWith("/evidence/41/retry")),
    );
    assert.equal(retry.length, 1);
    assert.equal(retry[0].method, "POST");
    assert.deepEqual(retry[0].data, {});
    assert.equal(
      await page
        .getByRole("button", {
          name: "Retry stored TEST evidence",
          exact: true,
        })
        .count(),
      0,
    );
    await page.evaluate(() => {
      testActor = null;
      document.querySelector("#host").replaceChildren();
    });
    assert.equal(await page.locator("textarea").count(), 0);
    console.log(
      "PASS isolated simulated dispute UI: bounded read-only deadline triage and stale-provider status, lazy loading, admin evidence mutation, literal text, error display, same-key retry retained across inspection, successful draft cleanup and logout removal. Not external provider verification.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
