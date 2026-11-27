const assert = require("node:assert/strict");
const { waitForFreshWindow } = require("./auth-batches.cjs");

const panel = (page) => page.locator("[data-coupon-panel]");
const button = (area, name) => area.getByRole("button", { name, exact: true });

async function couponPost(page, path, click, status = 200) {
  const completion = page.waitForResponse((response) =>
    new URL(response.url()).pathname === "/api/commercial/coupons" + path &&
    response.request().method() === "POST");
  await click();
  const response = await completion;
  assert.equal(response.status(), status, await response.text());
  return response.json();
}

async function exerciseCoupons({ admin, customer, reward, run, decide }) {
  const adminPanel = panel(admin), customerPanel = panel(customer);
  const local = (date) => admin.evaluate((wire) => {
    const d = new Date(wire);
    return new Date(+d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }, date.toISOString());
  async function publish(code, listed) {
    await adminPanel.locator("[name=coupon_code]").fill(code);
    await adminPanel.locator("[name=coupon_reward]").fill(String(reward.id));
    await adminPanel.locator("[name=coupon_cap]").fill("2");
    await adminPanel.locator("[name=coupon_user_cap]").fill("1");
    await adminPanel.locator("[name=coupon_listed]").setChecked(listed);
    await adminPanel.locator("[name=coupon_start]").fill(await local(new Date()));
    await adminPanel.locator("[name=coupon_end]").fill(await local(new Date(Date.now() + 3600000)));
    const row = await couponPost(admin, "", () => button(adminPanel, "Publish coupon").click(), 201);
    assert.equal(Boolean(row.listed), listed);
    assert.equal(row.claim_limit, 2);
    assert.equal(row.per_user_limit, 1);
    assert.equal(Object.hasOwn(row, "code_hash"), false);
    await adminPanel.locator(`[data-coupon-id="${row.id}"]`).waitFor();
    return row;
  }

  const listedCode = `PUBLIC-COUPON-${run.toUpperCase()}`;
  const privateCode = `PRIVATE-COUPON-${run.toUpperCase()}`;
  const listed = await publish(listedCode, true);
  const privateCoupon = await publish(privateCode, false);
  const privateRow = adminPanel.locator(`[data-coupon-id="${privateCoupon.id}"]`);
  const listedRow = adminPanel.locator(`[data-coupon-id="${listed.id}"]`);
  await admin.locator("#language").click();
  await privateRow.getByText("رمز خاص", { exact: true }).waitFor();
  await listedRow.getByText("مدرجة للأعضاء المؤهلين", { exact: true }).waitFor();
  await admin.locator("#language").click();

  await button(customerPanel, "Browse eligible coupons").click();
  const offer = customerPanel.locator(`[data-coupon-id="${listed.id}"]`);
  await offer.getByText(reward.name, { exact: true }).waitFor();
  assert.equal(await customerPanel.locator(`[data-coupon-id="${privateCoupon.id}"]`).count(), 0);
  assert.equal(await customerPanel.getByText(listedCode).count(), 0);
  await customer.setViewportSize({ width: 390, height: 844 });
  await customer.locator("#language").click();
  await offer.getByText(reward.name_ar, { exact: true }).waitFor();
  assert.equal(await customer.locator("html").getAttribute("dir"), "rtl");
  assert(await customer.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  const publicReceipt = await couponPost(customer, `/eligible/${listed.id}/claim`,
    () => button(offer, "المطالبة بالمكافأة المدرجة").click());
  assert.equal(publicReceipt.claim.status, "pending");
  assert.equal(publicReceipt.claim.points_cost, 0);
  await customerPanel.getByText("بانتظار التسليم", { exact: true }).waitFor();
  await customerPanel.getByText("سجل تجريبي", { exact: true }).waitFor();
  await customer.locator("#language").click();
  await customerPanel.getByText("Pending fulfillment", { exact: true }).waitFor();

  const claimPath = "**/api/commercial/coupons/claim";
  let expectedReceipt, privateKey;
  // Commit the actual claim on Laravel and deliberately lose the reply.
  const dropReply = async (route) => {
    privateKey = route.request().postDataJSON().idempotency_key;
    const response = await route.fetch();
    assert.equal(response.status(), 200, await response.text());
    expectedReceipt = (await response.json()).claim;
    await route.abort("failed");
  };
  await customer.route(claimPath, dropReply);
  const codeInput = customerPanel.locator("[name=coupon_code]");
  await codeInput.fill(privateCode);
  await button(customerPanel, "Claim coupon").click();
  await customerPanel.getByText("Coupon request could not be confirmed. Check the receipt or retry the same request.", { exact: true }).waitFor();
  await customer.unroute(claimPath, dropReply);
  const browserDraft = await customer.evaluate((key) => {
    return Object.keys(sessionStorage)
      .filter((name) => name.startsWith("creabiz.coupon-claim.v1."))
      .map((name) => sessionStorage.getItem(name))
      .find((raw) => raw.includes(key));
  }, privateKey);
  assert(browserDraft && !browserDraft.includes(privateCode));
  await customer.reload();
  await customer.locator('a[href="#commercial-finance"]').click();
  assert.equal(await codeInput.inputValue(), "");

  await codeInput.fill(listedCode);
  await button(customerPanel, "Claim coupon").click();
  await customerPanel.getByText("Check the previous claim receipt or clear the coupon draft.", { exact: true }).waitFor();

  await button(adminPanel, "Refresh coupons").click();
  await privateRow.getByText("· 1/2", { exact: true }).waitFor();
  await privateRow.locator('[name="revocation_reason"]').fill("Synthetic stock retirement");
  await couponPost(admin, `/${privateCoupon.id}/revoke`, () => button(privateRow, "Revoke coupon").click());
  await privateRow.getByText("Revoked", { exact: true }).waitFor();
  const capabilityPath = "**/api/commercial/noncash/status";
  const disableClaims = (route) => route.fulfill({
    json: {
      enabled: false, activation_mode: "disabled", points_actions_enabled: false,
      referrals_enabled: false, verified_live_source_required: false,
      money_movement: false, fulfillment: "MANUAL_NON_MONETARY", delivery_verified: false,
    },
  });
  await customer.route(capabilityPath, disableClaims);
  await codeInput.fill(privateCode);
  const noMutation = () => { throw Error("Disabled capability sent a claim"); };
  await customer.route(claimPath, noMutation);
  await button(customerPanel, "Claim coupon").click();
  await customerPanel.getByText(
    "New coupon claims are disabled. Existing receipts remain available.", { exact: true },
  ).waitFor();
  await customer.unroute(claimPath, noMutation);
  // Reopening the workspace refreshes finance panels against the same
  // per-account budget. Wait before the first receipt or history request.
  await waitForFreshWindow({ wait: (ms) => customer.waitForTimeout(ms) });
  const recovered = await couponPost(customer, "/claim-intents/cancel",
    () => button(customerPanel, "Clear coupon draft").click());
  assert.equal(recovered.state, "accepted");
  assert.equal(typeof recovered.request_binding, "string");
  await customer.unroute(capabilityPath, disableClaims);
  assert.equal(recovered.claim.id, expectedReceipt.id);
  await customerPanel.getByText("#" + expectedReceipt.id, { exact: true }).waitFor();
  await decide(expectedReceipt.redemption_id);

  const historyResponse = customer.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/commercial/coupons/claims" &&
      url.searchParams.get("page") === "1" && response.request().method() === "GET";
  });
  await button(customerPanel, "Refresh coupons").click();
  const history = await historyResponse;
  assert.equal(history.status(), 200, await history.text());
  const fulfilled = (await history.json()).data.find((row) => row.id === expectedReceipt.id);
  assert.equal(fulfilled?.status, "fulfilled");
  const receipt = customerPanel.locator("article").filter({
    has: customer.getByText("#" + expectedReceipt.id, { exact: true }),
  });
  await receipt.getByText("Fulfilled", { exact: true }).waitFor();
  await customer.locator("#language").click();
  await receipt.getByText("تم التسليم", { exact: true }).waitFor();
  await customer.locator("#language").click();
  assert.equal(await customerPanel.getByText("#" + expectedReceipt.id, { exact: true }).count(), 1);

  // Keep the real history request and vary only one receipt's immutable
  // provenance in a controlled response. Current configuration stays sandbox.
  const historyPath = "**/api/commercial/coupons/claims?page=1";
  const reviewedHistory = async (route) => {
    const response = await route.fetch();
    assert.equal(response.status(), 200);
    const history = await response.json();
    const row = history.data.find((entry) => entry.id === expectedReceipt.id);
    assert(row);
    Object.assign(row, {
      activation_mode: "reviewed_manual",
      activation_recorded: true,
      evidence: "REVIEWED_MANUAL_NONCASH",
    });
    await route.fulfill({ response, json: history });
  };
  await customer.route(historyPath, reviewedHistory, { times: 1 });
  await button(customerPanel, "Refresh coupons").click();
  await receipt.getByText("Reviewed manual noncash record", { exact: true }).waitFor();
  await customer.locator("#language").click();
  await receipt.getByText("سجل غير نقدي يدوي مراجع", { exact: true }).waitFor();
  await customer.locator("#language").click();
  await codeInput.fill(privateCode);
  await couponPost(customer, "/claim", () => button(customerPanel, "Claim coupon").click(), 409);
  await customerPanel.getByText("Coupon request declined. Check the code, dates and limits.", { exact: true }).waitFor();
  const cancelled = await couponPost(customer, "/claim-intents/cancel",
    () => button(customerPanel, "Clear coupon draft").click());
  assert.equal(cancelled.state, "cancelled");
  assert.equal(cancelled.claim, null);
  await customerPanel.getByText("Saved claim cancelled by the server.", { exact: true }).waitFor();
  assert.equal(await customer.evaluate(() => Object.keys(sessionStorage)
    .some(key => key.startsWith("creabiz.coupon-claim.v1."))), false);
  // Restore the loyalty section selected by the caller before the reload.
  await button(customer.locator("[data-loyalty]"), "Open loyalty & rewards").click();
  await button(customer.locator("[data-loyalty]"), "Reconcile point reversals and expiry").waitFor();
  return privateKey;
}

async function verifyForeignCouponPrivacy(foreign, key) {
  await button(panel(foreign), "Refresh coupons").click();
  await panel(foreign).getByText("No coupon claims", { exact: true }).waitFor();
  const result = await foreign.evaluate((idempotency_key) =>
    api("commercial/coupons/claim-receipt", "POST", { idempotency_key }), key);
  assert.equal(result.claim, null);
}

module.exports = { exerciseCoupons, verifyForeignCouponPrivacy };
