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
      window.storedEvidence = [];
      const row = {
        id: 9,
        invoice_id: 8,
        currency: "AED",
        minor_units: 2,
        amount_minor: 300,
        status: "needs_response",
        evidence_due_at: "2099-01-01T00:00:00Z",
        provider_observed_at: "2026-10-28T00:00:00Z",
        settlement_hold: true,
        requires_financial_resolution: false,
        deadline_passed: false,
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
          if (path.includes("?") || path === "commercial/external-disputes")
            return { data: [row], current_page: 1, last_page: 1 };
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
      "PASS isolated simulated dispute UI: lazy loading, admin evidence mutation, literal text, error display, same-key retry retained across inspection, successful draft cleanup and logout removal. Not external provider verification.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
