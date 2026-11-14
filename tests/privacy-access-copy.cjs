// Actual local Admin -> owner web copy/discard; no erasure, money or live data.
const { withPrivacyFixture } = require("./helpers/privacy-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
withPrivacyFixture(async (f) => {
  const owner = new BusinessClient({ base: f.base });
  owner.csrf = (await owner.request("csrf")).token;
  await owner.request("login", "POST", f.accounts.customer);
  owner.csrf = (await owner.request("csrf")).token;
  const receipt = await owner.request("account/privacy-requests", "POST", {
    kind: "access",
    details: "Please prepare a scoped personal account copy.",
    idempotency_key: "private-copy-" + f.run,
  });
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const admin = await browser.newPage({
      viewport: { width: 390, height: 844 },
    });
    const errors = [];
    admin.on("pageerror", (e) => errors.push(e.message));
    await admin.goto(f.base);
    await admin.locator("#account [name=email]").fill(f.accounts.admin.email);
    await admin
      .locator("#account [name=password]")
      .fill(f.accounts.admin.password);
    await admin.locator("button[value=login]").click();
    const root = admin.locator("#admin-privacy");
    const decision = () =>
      root.getByRole("form", {
        name: "Privacy review decision " + receipt.id,
        exact: true,
      });
    await decision().locator("select").selectOption("reviewing");
    await decision()
      .locator("textarea")
      .fill("Reviewed private account copy scope and owner identity.");
    await decision().locator("button").click();
    const filters = root.getByRole("form", {
      name: "Admin privacy request filters",
    });
    await filters.locator("select").first().selectOption("reviewing");
    await filters.locator("button").click();
    const card = root
      .locator("article")
      .filter({ hasText: "Privacy request #" + receipt.id + " ·" });
    await card
      .getByLabel("Administrator password / كلمة مرور المسؤول", { exact: true })
      .fill(f.accounts.admin.password);
    const prepared = admin.waitForResponse(
      (r) =>
        new URL(r.url()).pathname ===
          `/api/admin/privacy-requests/${receipt.id}/access-copy` &&
        r.request().method() === "POST",
    );
    await card
      .getByRole("button", {
        name: "Prepare private account copy / إعداد نسخة الحساب الخاصة",
        exact: true,
      })
      .click();
    assert.equal((await prepared).status(), 201);
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    await context.addCookies(
      [...owner.cookies].map(([name, value]) => ({ name, value, url: f.base })),
    );
    const page = await context.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(f.base);
    const mine = page.locator("#account-privacy");
    const request = mine
      .locator("article")
      .filter({ hasText: "Request #" + receipt.id + " ·" });
    await request
      .getByLabel("Current password / كلمة المرور الحالية", { exact: true })
      .fill(f.accounts.customer.password);
    await request
      .getByRole("button", {
        name: "View private account copy / عرض نسخة الحساب الخاصة",
        exact: true,
      })
      .click();
    await request
      .locator("pre")
      .filter({ hasText: f.accounts.customer.email })
      .waitFor();
    assert.equal(
      await request.locator("input[type=password]").inputValue(),
      "",
    );
    assert(
      !(await request.locator("pre").textContent()).includes(
        f.accounts.other.email,
      ),
    );
    assert.equal(await request.locator("img").count(), 0);
    await request
      .getByRole("button", {
        name: "Discard private copy / إتلاف النسخة الخاصة",
        exact: true,
      })
      .click();
    await mine
      .getByText("Private copy: revoked / حالة النسخة الخاصة", { exact: true })
      .waitFor();
    assert.equal(await mine.locator("pre").count(), 0);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(
      "PASS actual Admin review -> encrypted scoped copy -> owner reauthentication/view -> discard, phone viewport; no erasure or financial mutation",
    );
  } finally {
    await browser.close();
  }
}).catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
