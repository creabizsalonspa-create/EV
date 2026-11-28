// Real Laravel HTTP/browser workflows. All accounting identities and payments are synthetic.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const fs = require("node:fs");
const assert = require("node:assert/strict");
const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
(async () => {
  await require('./payment-recovery-scope.cjs')();
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  execFileSync("./scripts/php", [
    "tests/Fixtures/invoice-history.php",
    run,
    password,
  ]);
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  try {
    async function login(role) {
      const context = await browser.newContext();
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      p.on("response", (response) => {
        const pathname = new URL(response.url()).pathname;
        if (pathname.startsWith("/api/commercial/") && response.status() >= 400)
          console.error(
            "Commercial QA response",
            response.request().method(),
            pathname,
            response.status(),
          );
      });
      p.setDefaultTimeout(15000);
      await p.goto(base);
      await p
        .locator("#account [name=email]")
        .fill(`invoices-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      await p.locator("button[value=login]").click();
      await p.locator('a[href="#commercial-finance"]').click();
      await p.locator("#commercial-finance h2").waitFor();
      await p
        .locator("#commercial-finance .finance-panel")
        .nth(2)
        .getByRole("heading", { name: "Plans & subscriptions", exact: true })
        .waitFor();
      return p;
    }
    const admin = await login("admin"),
      rootAdmin = admin.locator("#commercial-finance");
    const planForm = rootAdmin.locator("form").filter({
      has: admin.getByRole("button", {
        name: "Publish subscription plan",
        exact: true,
      }),
    });
    await planForm.locator("[name=code]").fill("test-" + run);
    await planForm.locator("[name=name]").fill("Commercial " + run);
    await planForm.locator("[name=audience]").selectOption("customer");
    await planForm
      .getByRole("button", { name: "Publish subscription plan", exact: true })
      .click();
    await rootAdmin
      .getByRole("heading", { name: "Commercial " + run, exact: true })
      .waitFor();
    const customer = await login("customer"),
      root = customer.locator("#commercial-finance");
    const checkoutButtons = root.getByRole("button", {
      name: "Open test checkout",
      exact: true,
    });
    await checkoutButtons.nth(1).click();
    await root
      .getByRole("button", { name: "Authorize test payment", exact: true })
      .waitFor();
    await root
      .getByRole("button", { name: "Authorize test payment", exact: true })
      .click();
    await root
      .getByRole("button", { name: "Capture test payment", exact: true })
      .waitFor();
    await root
      .getByRole("button", { name: "Capture test payment", exact: true })
      .click();
    await root.getByRole("heading", { name: /#\d+ · captured/ }).waitFor();
    const refund = root
      .locator(".finance-row")
      .filter({ has: customer.getByRole("heading", { name: /^SIM-/ }) })
      .nth(1)
      .locator("form")
      .filter({
        has: customer.getByRole("button", {
          name: "Request refund",
          exact: true,
        }),
      });
    await refund.locator("[name=amount]").fill("1.00");
    await refund
      .getByLabel("Reason", { exact: true })
      .fill("Browser refund " + run);
    await refund
      .getByRole("button", { name: "Request refund", exact: true })
      .click();
    await root.getByText("Browser refund " + run, { exact: true }).waitFor();

    // Assert the Admin's actual rendered outstanding-source summary, not a
    // hidden JSON fixture. The group spans the full filtered queue even when
    // the detail page is bounded; no cross-currency sum or approval click.
    const reviewPanel = rootAdmin.locator("[data-finance-approval-queue]");
    await rootAdmin.getByLabel("Finance approval kind").selectOption("refund");
    await rootAdmin.getByRole("button", {
      name: "Load pending finance reviews", exact: true,
    }).click();
    await reviewPanel.locator("[data-finance-approval-group-summary]").waitFor();
    const pendingResponse = await admin.request.get(
      base + "/api/finance/approval-queue?kind=refund&per_page=20&page=1",
      { headers: { Accept: "application/json" } },
    );
    assert.equal(pendingResponse.status(), 200);
    const pending = await pendingResponse.json();
    assert.equal(pending.summary.currency_and_precision_isolated, true);
    assert.equal(
      pending.summary.scope,
      "ALL_CURRENT_FILTERED_PENDING_RECORDS_NOT_PAGE",
    );
    assert.equal(
      pending.summary.groups.reduce((total, group) =>
        total + group.review_count, 0),
      pending.total,
      "Full filtered pending approval count must not be current-page-only",
    );
    const renderedRefunds = await reviewPanel
      .locator('[data-finance-approval-group="refund"]').allInnerTexts();
    assert.equal(renderedRefunds.length, pending.summary.groups.length);
    for (const group of pending.summary.groups) {
      const source = renderedRefunds.find(
        (value) => value.includes(group.currency) &&
          value.includes(String(group.signed_amount_minor)),
      );
      assert(source, "Admin must show exact server-grouped pending finance amount");
    }
    assert(
      !(await reviewPanel.innerText()).includes("Browser refund " + run),
      "Private customer refund notes must never leak into Admin review groups",
    );
    const plan = root.locator(".finance-row").filter({
      has: customer.getByRole("heading", {
        name: "Commercial " + run,
        exact: true,
      }),
    });
    await plan.getByRole("button", { name: "Subscribe", exact: true }).click();
    await root
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    await root
      .getByRole("button", { name: "Open subscription checkout", exact: true })
      .click();
    await root
      .getByRole("button", {
        name: "Authorize subscription payment",
        exact: true,
      })
      .click();
    await root
      .getByRole("button", {
        name: "Capture subscription payment",
        exact: true,
      })
      .click();
    await root.getByRole("heading", { name: /#\d+ · active/ }).waitFor();
    await root
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    await root
      .getByText("Commercial " + run + " · Active / فعال", { exact: true })
      .waitFor();
    const subscriptionId = await root
      .getByRole("heading", { name: /#\d+ · active/ })
      .textContent()
      .then((value) => Number(value.match(/#(\d+)/)[1]));
    // The plan preview consumes a private GET and must not create a new
    // charge/invoice or change the currently paid entitlement.
    await waitForFreshWindow({ wait });
    const previewForm = root.locator(
      '[data-subscription-id="' + subscriptionId + '"] form',
    ).filter({
      has: customer.getByRole("button", {
        name: "Update subscription", exact: true,
      }),
    });
    await previewForm.locator("[name=action]").selectOption("change_plan");
    const proposedPlan = Number(
      await previewForm.locator("[name=plan_id]").inputValue(),
    );
    assert(Number.isSafeInteger(proposedPlan) && proposedPlan > 0);
    const previewRequest = customer.waitForResponse((response) =>
      new URL(response.url()).pathname ===
        "/api/commercial/subscriptions/" + subscriptionId + "/change-preview" &&
      response.request().method() === "GET",
    );
    await root
      .getByRole("button", { name: "Preview next cycle charges", exact: true })
      .click();
    const previewResponse = await previewRequest;
    assert.equal(previewResponse.status(), 200);
    const quote = await previewResponse.json();
    assert.equal(quote.subscription_id, subscriptionId);
    assert.equal(quote.proposed_plan_id, proposedPlan);
    assert.equal(quote.amount_due_now_minor, 0);
    assert.equal(quote.proration_supported, false);
    assert.equal(quote.comparison_is_proration, false);
    assert(Number.isSafeInteger(quote.current_plan_list_total_minor));
    assert(["month", "year"].includes(quote.current_plan_interval));
    assert.equal(typeof quote.cycles_comparable, "boolean");
    if (quote.cycles_comparable)
      assert(Number.isSafeInteger(quote.nominal_plan_list_difference_minor));
    else
      assert.equal(quote.nominal_plan_list_difference_minor, null);
    assert.equal(quote.quote_is_binding, false);
    assert.equal(quote.real_payment_processed, false);
    await root.locator(
      '[data-subscription-change-preview="' + subscriptionId + '"]',
    ).getByRole("heading", { name: "Non-binding next cycle preview", exact: true }).waitFor();
    assert.match(await root.locator(
      '[data-subscription-change-preview="' + subscriptionId + '"]',
    ).innerText(), /Due now: zero/);
    const pricePane = await root.locator(
      '[data-subscription-change-preview="' + subscriptionId + '"]',
    ).innerText();
    assert.match(pricePane, /Current published list/);
    assert.match(
      pricePane,
      quote.cycles_comparable ? /Nominal list difference/ : /Different billing intervals/,
    );
    await root
      .getByRole("button", { name: "Load recovery records", exact: true })
      .click();
    await root
      .getByText("No payment recovery records", { exact: true })
      .waitFor();
    const vendor = await login("vendor"),
      vr = vendor.locator("#commercial-finance");
    // Finance dashboard is a private read-only issuance-date cohort view. Its
    // collected/refunded amounts are not claims of legal revenue or cash payout.
    const revenueForm = vr.locator("form").filter({
      has: vendor.getByRole("button", {
        name: "View revenue cohorts", exact: true,
      }),
    });
    await revenueForm.locator("[name=currency]").fill("AED");
    const revenueRequest = vendor.waitForResponse((response) => {
      const url = new URL(response.url());
      return url.pathname.endsWith("/revenue-cohorts") &&
        response.request().method() === "GET";
    });
    await revenueForm.getByRole("button", {
      name: "View revenue cohorts", exact: true,
    }).click();
    const revenueResponse = await revenueRequest;
    assert.equal(revenueResponse.status(), 200);
    const cohorts = await revenueResponse.json();
    assert.equal(cohorts.real_money_movement, false);
    assert.equal(cohorts.not_cashflow_or_payout_eligibility, true);
    assert.equal(cohorts.external_psp_or_bank_verified, false);
    assert.equal(cohorts.basis, "INVOICE_ISSUANCE_DATE_UTC_COHORT");
    await vr.locator("[data-vendor-revenue-results]").
      getByRole("heading", { name: "Invoice-cohort totals (simulated)" }).waitFor();
    const invoiceDays = await vr.locator("[data-vendor-revenue-results] [data-revenue-date]").count();
    assert(invoiceDays > 0 && invoiceDays <= 93);
    const exportLink = vr.locator("[data-vendor-revenue-results]")
      .getByRole("link", { name: "Download private CSV", exact: true });
    assert.match(await exportLink.getAttribute("href"), /\/api\/commercial\/vendors\/\d+\/revenue-cohorts\/export\?/);
    await revenueForm.locator("[name=from]").fill("2026-10-01");
    assert.equal(await vr.locator("[data-vendor-revenue-results]").locator("*").count(), 0,
      "Editing cohort filters must clear prior financial results");
    // Reopen a current report, then simulate revocation from the server on a
    // subsequent refresh. A failed private GET must erase the old totals and
    // CSV link, not leave a former session's finance data on screen.
    const reloadRevenue = vendor.waitForResponse((response) =>
      new URL(response.url()).pathname.endsWith("/revenue-cohorts") &&
      response.request().method() === "GET");
    await revenueForm.getByRole("button", {
      name: "View revenue cohorts", exact: true,
    }).click();
    assert.equal((await reloadRevenue).status(), 200);
    await vr.locator("[data-vendor-revenue-results]")
      .getByRole("heading", { name: "Invoice-cohort totals (simulated)" }).waitFor();
    const revokedPath = new RegExp("api/commercial/vendors/[0-9]+/revenue-cohorts[?]");
    await vendor.route(revokedPath, async (route) => route.fulfill({
      status: 409, contentType: "application/json",
      body: JSON.stringify({ message: "Account authorization changed; sign in again." }),
    }));
    const refused = vendor.waitForResponse((response) =>
      new URL(response.url()).pathname.endsWith("/revenue-cohorts") &&
      response.request().method() === "GET");
    await revenueForm.getByRole("button", {
      name: "View revenue cohorts", exact: true,
    }).click();
    assert.equal((await refused).status(), 409);
    await vendor.waitForFunction(() => !document.querySelector("#commercial-finance").inert);
    assert.equal(await vr.locator("[data-vendor-revenue-results]").locator("*").count(), 0,
      "Denied cohort refresh must not retain prior financial output or CSV export");
    await vendor.unroute(revokedPath);
    // Keep the existing customer refund review and Admin reconciliation flows
    // intact; do not issue any synthetic payment or refund from this report.
    const request = vr.locator(".finance-row").filter({
      has: vendor.getByText("Browser refund " + run, { exact: true }),
    });
    await request.locator("[name=note]").fill("Approved in browser");
    await request
      .getByRole("button", { name: "Review refund", exact: true })
      .click();
    await vr.getByText("Browser refund " + run, { exact: true }).waitFor();
    // Internal dispute decision and vendor settlement use the same posted source records.
    const dispute = root
      .locator(".finance-row")
      .filter({ has: customer.getByRole("heading", { name: /^SIM-/ }) })
      .nth(1)
      .locator("form")
      .filter({
        has: customer.getByRole("button", {
          name: "Open dispute",
          exact: true,
        }),
      });
    await dispute.locator("[name=amount]").fill("0.50");
    await dispute.locator("[name=reason]").fill("Browser dispute " + run);
    await dispute
      .getByRole("button", { name: "Open dispute", exact: true })
      .click();
    await root.getByText("Browser dispute " + run, { exact: true }).waitFor();
    await rootAdmin
      .getByRole("button", { name: "Refresh finance", exact: true })
      .click();
    const disputeReview = rootAdmin.locator(".finance-row").filter({
      has: admin.getByText("Browser dispute " + run, { exact: true }),
    });
    // A corrupted HTTP read must not create an Admin money decision control.
    // Only the browser response changes; no ledger or provider data is touched.
    const disputePath = "**/api/commercial/cases*";
    await admin.route(disputePath, async (route) => {
      const response = await route.fetch();
      const json = await response.json();
      if (Array.isArray(json.data)) {
        json.data = json.data.map((item) =>
          item.reason === "Browser dispute " + run
            ? { ...item, amount_minor: "fabricated-money" }
            : item,
        );
      }
      await route.fulfill({ response, json });
    });
    await rootAdmin
      .getByRole("button", { name: "Refresh finance", exact: true })
      .click();
    await disputeReview
      .getByText("Dispute amount unverified; refresh before review.", { exact: true })
      .waitFor();
    assert.equal(
      await disputeReview.getByRole("button", { name: "Resolve dispute", exact: true }).count(),
      0,
      "Malformed minor amounts cannot authorize a dispute resolution UI",
    );
    await admin.unroute(disputePath);
    await rootAdmin
      .getByRole("button", { name: "Refresh finance", exact: true })
      .click();
    await disputeReview.getByRole("button", { name: "Resolve dispute", exact: true }).waitFor();
    assert.equal(
      await disputeReview.locator("bdi[data-case-minor-amount]").textContent(),
      "50",
      "Customer dispute should display exact canonical 0.50 as 50 minor units",
    );
    assert.equal(
      await disputeReview.getByText("Disputed amount (minor units)", { exact: true }).count(),
      1,
    );
    await disputeReview
      .locator("[name=resolution]")
      .fill("Accepted browser dispute");
    await disputeReview
      .getByRole("button", { name: "Resolve dispute", exact: true })
      .click();
    const vendorId = await vendor.evaluate(async () => {
      const r = await fetch("/api/me", {
        headers: { Accept: "application/json" },
      });
      return (await r.json()).id;
    });
    async function statement() {
      await admin.waitForFunction(
        () => !document.querySelector("#commercial-finance").inert,
      );
      const f = rootAdmin.locator("form").filter({
        has: admin.getByRole("button", {
          name: "View earnings statement",
          exact: true,
        }),
      });
      await f.locator("[name=vendor]").fill(String(vendorId));
      await f
        .getByRole("button", { name: "View earnings statement", exact: true })
        .click();
      await admin.waitForFunction(
        () => !document.querySelector("#commercial-finance").inert,
      );
    }
    // Deliberately prove the real protected-read limiter before crossing into
    // the settlement phase. No financial write is sent during exhaustion.
    await admin.waitForFunction(
      () => !document.querySelector("#commercial-finance").inert,
    );
    let limited = false;
    for (let attempt = 0; attempt < 61; attempt++) {
      const response = await admin.request.get(
        base + "/api/commercial/settlements",
        {
          headers: { Accept: "application/json" },
        },
      );
      if (response.status() === 429) {
        const retry = Number(response.headers()["retry-after"]);
        assert(Number.isFinite(retry) && retry >= 0 && retry <= 60);
        limited = true;
        break;
      }
      assert.equal(
        response.status(),
        200,
        "Only successful authorized reads may prepare the budget boundary",
      );
    }
    assert(
      limited,
      "The production protected-read budget must remain enforced",
    );
    console.log(
      "PASS enforced protected-read 429 boundary; no financial write was retried or limiter cleared.",
    );
    await waitForFreshWindow({ wait });
    const fresh = await admin.request.get(
      base + "/api/commercial/settlements",
      {
        headers: { Accept: "application/json" },
      },
    );
    assert.equal(
      fresh.status(),
      200,
      "A fresh window must restore normal reads without clearing the limiter",
    );
    const prior = (await fresh.json()).data.map(({ id, state }) => ({
      id,
      state,
    }));
    await statement();
    const schedule = rootAdmin.locator("form").filter({
      has: admin.getByRole("button", {
        name: "Save settlement schedule",
        exact: true,
      }),
    });
    await schedule
      .getByRole("button", { name: "Save settlement schedule", exact: true })
      .click();
    await statement();
    const createdResponse = admin.waitForResponse(
      (r) =>
        new URL(r.url()).pathname ===
          `/api/commercial/vendors/${vendorId}/settlements` &&
        r.request().method() === "POST",
    );
    await rootAdmin
      .getByRole("button", { name: "Request test settlement", exact: true })
      .click();
    const created = await createdResponse;
    assert.equal(created.status(), 201);
    const settlement = await created.json();
    assert.equal(settlement.vendor_id, vendorId);
    assert.equal(settlement.state, "pending");
    const row = () =>
      rootAdmin.locator(".finance-row").filter({
        has: admin.getByRole("heading", {
          name: settlement.number,
          exact: true,
        }),
      });
    const approvalResponse = admin.waitForResponse(
      (r) =>
        new URL(r.url()).pathname ===
          `/api/commercial/settlements/${settlement.id}/approve` &&
        r.request().method() === "POST",
    );
    await row()
      .getByRole("button", { name: "Approve settlement", exact: true })
      .click();
    const approved = await approvalResponse;
    assert.equal(approved.status(), 200);
    assert.equal((await approved.json()).state, "approved");
    const payoutResponse = admin.waitForResponse(
      (r) =>
        new URL(r.url()).pathname ===
          `/api/commercial/settlements/${settlement.id}/simulate-payout` &&
        r.request().method() === "POST",
    );
    await row()
      .getByRole("button", { name: "Record test payout", exact: true })
      .click();
    const paid = await payoutResponse;
    assert.equal(paid.status(), 200);
    const paidReceipt = await paid.json();
    assert.equal(paidReceipt.id, settlement.id);
    assert.equal(paidReceipt.state, "simulated_paid");
    await row()
      .getByText(/simulated_paid/)
      .waitFor();
    const detail = await admin.request.get(
      base + `/api/commercial/settlements/${settlement.id}`,
      {
        headers: { Accept: "application/json" },
      },
    );
    assert.equal(detail.status(), 200);
    const reconciled = await detail.json();
    assert.equal(reconciled.reconciled, true);
    assert.equal(reconciled.real_payout_enabled, false);
    for (const action of ["drafted", "approve", "simulate-payout"])
      assert.equal(
        reconciled.events.filter((e) => e.action === action).length,
        1,
        "Each scoped financial transition must record one audit event",
      );
    const after = await admin.request.get(
      base + "/api/commercial/settlements",
      {
        headers: { Accept: "application/json" },
      },
    );
    assert.equal(after.status(), 200);
    const afterRows = (await after.json()).data;
    for (const original of prior) {
      const current = afterRows.find((s) => s.id === original.id);
      if (current)
        assert.equal(
          current.state,
          original.state,
          "This test must not mutate another settlement",
        );
    }
    await admin.waitForFunction(
      () => !document.querySelector("#commercial-finance").inert,
    );
    // Settlement/review refreshes consume this Admin's unchanged60/min budget.
    // Start the subscription-credit phase in a fresh window; never replay a refund.
    await waitForFreshWindow({ wait });
    const subAdmin = rootAdmin.locator(
      `[data-subscription-id="${subscriptionId}"]`,
    );
    await subAdmin
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    // Manage renders its detail before the asynchronous action releases inert.
    // Interact only after the existing financial mutation fence is released.
    await admin.waitForFunction(
      () => !document.querySelector("#commercial-finance").inert,
    );
    const credit = subAdmin.locator("form").filter({
      has: admin.getByRole("button", {
        name: "Refund subscription invoice",
        exact: true,
      }),
    });
    await credit
      .locator("[name=reason]")
      .fill("Full subscription refund " + run);
    const creditResponse = admin.waitForResponse(
      (response) =>
        new URL(response.url()).pathname ===
          `/api/commercial/subscriptions/${subscriptionId}/credits` &&
        response.request().method() === "POST",
    );
    await credit
      .getByRole("button", { name: "Refund subscription invoice", exact: true })
      .click();
    const credited = await creditResponse;
    assert(
      credited.ok(),
      `Subscription credit rejected with status ${credited.status()}; no retry issued.`,
    );
    await rootAdmin
      .getByRole("heading", {
        name: new RegExp("#" + subscriptionId + " · cancelled"),
      })
      .waitFor();
    // The customer phase has already exercised a full financial workspace
    // under the real 60/user/minute limit. Admin actions do not reset that
    // budget. Pace this new read batch; never clear limiter state or replay
    // financial mutations to obtain a green test.
    await new Promise((resolve) => setTimeout(resolve, 60000));
    await root
      .getByRole("button", { name: "Refresh finance", exact: true })
      .click();
    await root
      .getByRole("heading", {
        name: new RegExp("#" + subscriptionId + " · cancelled"),
      })
      .waitFor();
    await root
      .getByRole("button", { name: "Manage subscription", exact: true })
      .click();
    await root
      .getByText("Commercial " + run + " · Inactive / غير فعال", {
        exact: true,
      })
      .waitFor();
    await root
      .getByRole("button", {
        name: "View subscription credit notes",
        exact: true,
      })
      .click();
    await root
      .getByText("Full subscription refund " + run, { exact: true })
      .waitFor();
    const download = customer.waitForEvent("download");
    await root
      .getByRole("button", { name: "Download report CSV", exact: true })
      .click();
    assert.equal(
      (await download).suggestedFilename(),
      "creabiz-test-finance.csv",
    );
    fs.mkdirSync("artifacts/finance-commercial/screenshots", {
      recursive: true,
    });
    for (const theme of ["light", "dark"]) {
      await customer.locator("#theme-choice").selectOption(theme);
      for (const width of [390, 768, 1440]) {
        await customer.setViewportSize({ width, height: 900 });
        assert(
          await customer.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
        );
        await root.evaluate((n) => n.scrollIntoView({ block: "start" }));
        await customer.screenshot({
          path: `artifacts/finance-commercial/screenshots/${theme}-${width}.png`,
        });
      }
    }
    await customer.locator("#language").click();
    await root
      .getByRole("heading", { name: "المالية والأعمال", exact: true })
      .waitFor();
    assert.equal(await customer.locator("html").getAttribute("dir"), "rtl");
    await root.getByText("المبلغ المتنازع عليه (وحدات نقدية صغرى)", { exact: true }).first().waitFor();
    await customer.setViewportSize({ width: 390, height: 900 });
    for (const theme of ["light", "dark"]) {
      await customer.locator("#theme-choice").selectOption(theme);
      await root.evaluate((n) => n.scrollIntoView({ block: "start" }));
      assert(
        await customer.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
      await customer.screenshot({
        path: `artifacts/finance-commercial/screenshots/${theme}-ar-390.png`,
      });
    }

    await customer.locator("#logout").click();
    await customer.waitForFunction(
      () => document.querySelector("#commercial-finance").hidden,
    );
    assert.equal(await root.textContent(), "");
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel financial workspace: checkout authorization/capture, refund request/review, vendor invoice-cohort analytics, nonbinding next-cycle preview/zero due, subscription gateway capture/entitlement, immutable subscription credit/refund and entitlement revocation, recovery queue privacy, dispute resolution, settlement approval/test payout, CSV download, both themes three widths RTL and logout privacy. MOCK payments only.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
