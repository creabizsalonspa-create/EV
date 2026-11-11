// Real Laravel HTTP/browser workflows. All accounting identities and payments are synthetic.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const fs = require("node:fs");
const assert = require("node:assert/strict");
const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
(async () => {
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
    await root
      .getByRole("button", { name: "Load recovery records", exact: true })
      .click();
    await root
      .getByText("No payment recovery records", { exact: true })
      .waitFor();
    const vendor = await login("vendor"),
      vr = vendor.locator("#commercial-finance");
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
      "PASS actual Laravel financial workspace: checkout authorization/capture, refund request/review, subscription gateway capture/entitlement, immutable subscription credit/refund and entitlement revocation, recovery queue privacy, dispute resolution, settlement approval/test payout, CSV download, both themes three widths RTL and logout privacy. MOCK payments only.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
