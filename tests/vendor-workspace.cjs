// Real local Laravel onboarding, private evidence and staff/inventory workflows.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomUUID } = require("node:crypto");
const assert = require("node:assert/strict");
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  assert(
    ["127.0.0.1", "localhost"].includes(new URL(base).hostname),
    "Synthetic company QA is loopback-only.",
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  let phase = "initializing";
  const stage = (value) => {
    phase = value;
    console.log("KYC QA: " + value);
  };
  const watchdog = setTimeout(() => {
    console.error("FAIL: vendor workflow stalled at " + phase);
    browser.close();
  }, 180000);
  watchdog.unref();
  const errors = [],
    run = randomUUID().slice(0, 8),
    password = randomUUID() + "aA9!";
  const section = (p) => p.locator("#vendor-operations");
  const form = (p, title) =>
    section(p).locator('[data-vendor-form="' + title + '"]');
  async function action(page, path, method, click, expected = 200) {
    for (let attempt = 0; attempt < 2; attempt++) {
      const ready = page.waitForResponse(
        (r) =>
          new URL(r.url()).pathname === path && r.request().method() === method,
      );
      await click();
      const response = await ready;
      if (response.status() === 429 && attempt === 0) {
        const seconds = Number(response.headers()["retry-after"] || 60);
        assert(seconds >= 0 && seconds <= 60);
        await page.waitForTimeout((seconds + 1) * 1000);
        continue;
      }
      assert.equal(
        response.status(),
        expected,
        "Unexpected workflow response for " + path,
      );
      return response;
    }
  }
  async function submit(page, title, path, expected = 200, method = "POST") {
    return action(
      page,
      path,
      method,
      () => form(page, title).locator("button[type=submit]").click(),
      expected,
    );
  }
  async function account(name) {
    const context = await browser.newContext();
    context.setDefaultTimeout(15000);
    context.setDefaultNavigationTimeout(20000);
    const page = await context.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(base);
    const email = name + "-" + run + "@example.test";
    await page.locator("#account [name=name]").fill(name);
    await page.locator("#account [name=email]").fill(email);
    await page.locator("#account [name=password]").fill(password);
    await action(
      page,
      "/api/register",
      "POST",
      () => page.locator("button[value=register]").click(),
      201,
    );
    await form(page, "Apply as a company").waitFor();
    return { page, context, email };
  }
  async function open(page, name) {
    const card = section(page)
      .locator("article")
      .filter({ has: page.getByText(name, { exact: true }) })
      .first();
    await card
      .getByRole("button", { name: "Open company", exact: true })
      .click();
    await section(page)
      .locator("[data-company-detail]")
      .getByText(name, { exact: true })
      .waitFor();
  }
  async function refresh(page) {
    await section(page)
      .getByRole("button", { name: "Refresh companies", exact: true })
      .click();
    await section(page)
      .getByRole("heading", { name: "Your companies", exact: true })
      .waitFor();
  }
  try {
    const applicant = await account("applicant"),
      staff = await account("staff"),
      outsider = await account("outsider"),
      admin = await account("reviewer");
    execFileSync("./scripts/php", [
      "artisan",
      "platform:role",
      admin.email,
      "admin",
    ]);
    await admin.page.reload();
    await section(admin.page)
      .getByRole("heading", { name: "Your companies", exact: true })
      .waitFor();
    stage("registered personas");
    const name = "Operator " + run + " <img src=x onerror=alert(1)>",
      license = "SYNTHETIC-LICENSE-" + run;
    const create = form(applicant.page, "Apply as a company");
    await create.locator("[name=legal_name]").fill(name);
    await create.locator("[name=country]").selectOption("AE");
    await create.locator("[name=trade_license_number]").fill(license);
    await create
      .locator("[name=license_expires_on]")
      .fill(new Date(Date.now() + 365 * 86400000).toISOString().slice(0, 10));
    const created = await (
      await submit(
        applicant.page,
        "Apply as a company",
        "/api/vendor-ops/companies",
        201,
      )
    ).json();
    assert.equal(created.trade_license_number, undefined);
    stage("company created");
    const company = created.id,
      path = "/api/vendor-ops/companies/" + company;
    await form(applicant.page, "Upload private evidence").waitFor();
    assert.equal(await section(applicant.page).locator("img").count(), 0);
    await action(
      applicant.page,
      path + "/submit",
      "POST",
      () =>
        section(applicant.page)
          .getByRole("button", {
            name: "Submit for internal review",
            exact: true,
          })
          .click(),
      422,
    );
    stage("missing evidence rejected");
    const content = Buffer.from(
      "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
    );
    await form(applicant.page, "Upload private evidence")
      .locator("[name=document]")
      .setInputFiles({
        name: "synthetic-license.pdf",
        mimeType: "application/pdf",
        buffer: content,
      });
    stage("file selected");
    const document = await (
      await submit(
        applicant.page,
        "Upload private evidence",
        path + "/documents",
        201,
      )
    ).json();
    stage("evidence uploaded");
    const link = section(applicant.page).locator(
      'a[href="' + path + "/documents/" + document.id + '"]',
    );
    await link.waitFor();
    const downloading = applicant.page.waitForEvent("download");
    await link.click();
    const downloaded = await downloading;
    const chunks = [];
    for await (const chunk of await downloaded.createReadStream())
      chunks.push(chunk);
    assert.deepEqual(Buffer.concat(chunks), content);
    assert.equal(
      (
        await outsider.context.request.get(
          base + path + "/documents/" + document.id,
        )
      ).status(),
      404,
    );
    await action(applicant.page, path + "/submit", "POST", () =>
      section(applicant.page)
        .getByRole("button", {
          name: "Submit for internal review",
          exact: true,
        })
        .click(),
    );
    await form(applicant.page, "Upload private evidence").waitFor({
      state: "hidden",
    });
    stage("private evidence verified and submitted");
    await refresh(admin.page);
    await open(admin.page, name);
    await form(admin.page, "Internal review decision")
      .locator("[name=status]")
      .selectOption("approved");
    await form(admin.page, "Internal review decision")
      .locator("[name=reason]")
      .fill("Synthetic internal review only");
    await submit(admin.page, "Internal review decision", path + "/review");
    await section(admin.page)
      .locator("[data-company-detail]")
      .getByText("Company status: Internally approved", { exact: true })
      .waitFor();
    stage("internally approved");
    await action(applicant.page, path, "GET", () =>
      section(applicant.page)
        .getByRole("button", { name: "Refresh company", exact: true })
        .click(),
    );
    await section(applicant.page)
      .locator("[data-company-detail]")
      .getByText("Company status: Internally approved", { exact: true })
      .waitFor();
    await form(applicant.page, "Invite company staff")
      .locator("[name=email]")
      .fill(staff.email);
    for (const permission of [
      "company.read",
      "inventory.read",
      "inventory.write",
    ])
      await form(applicant.page, "Invite company staff")
        .locator('[name=permissions][value="' + permission + '"]')
        .check();
    await submit(applicant.page, "Invite company staff", path + "/staff", 202);
    stage("staff invited");
    await refresh(staff.page);
    staff.page.once("dialog", (d) => d.accept());
    await section(staff.page)
      .getByRole("button", { name: "Accept staff invitation", exact: true })
      .click();
    await section(staff.page).getByText(name, { exact: true }).waitFor();
    assert.equal(
      (await (await staff.context.request.get(base + "/api/me")).json()).role,
      "customer",
    );
    await open(staff.page, name);
    assert.equal(
      await section(staff.page).locator('a[href*="/documents/"]').count(),
      0,
    );
    assert.equal(
      (
        await staff.context.request.get(
          base + path + "/documents/" + document.id,
        )
      ).status(),
      404,
    );
    stage("staff consent and privacy verified");
    // A customer-role staff member uses granted inventory permissions, not global vendor role.
    const site = form(staff.page, "Create site");
    await site.locator("[name=name]").fill("Scoped site");
    await site.locator("[name=address]").fill("Synthetic address");
    await site.locator("[name=latitude]").fill("25.2");
    await site.locator("[name=longitude]").fill("55.3");
    const siteRecord = await (
      await submit(staff.page, "Create site", path + "/sites", 201)
    ).json();
    await section(staff.page)
      .getByRole("button", { name: "Open chargers", exact: true })
      .click();
    const charger = form(staff.page, "Create charger");
    await charger.locator("[name=asset_reference]").fill("ASSET-" + run);
    await charger.locator("[name=manufacturer]").fill("Synthetic manufacturer");
    await charger.locator("[name=model]").fill("Synthetic model");
    await charger.locator("[name=source]").selectOption("simulator");
    const chargerRecord = await (
      await submit(
        staff.page,
        "Create charger",
        path + "/sites/" + siteRecord.id + "/chargers",
        201,
      )
    ).json();
    await section(staff.page)
      .getByRole("button", { name: "Open connectors", exact: true })
      .click();
    const connector = form(staff.page, "Create connector");
    await connector.locator("[name=connector_number]").fill("1");
    await connector.locator("[name=max_power_kw]").fill("150");
    const connectorRecord = await (
      await submit(
        staff.page,
        "Create connector",
        path + "/chargers/" + chargerRecord.id + "/connectors",
        201,
      )
    ).json();
    const maintenance = form(staff.page, "Set maintenance");
    await maintenance
      .locator("[name=maintenance_reason]")
      .fill("Synthetic maintenance metadata");
    await submit(
      staff.page,
      "Set maintenance",
      path +
        "/chargers/" +
        chargerRecord.id +
        "/connectors/" +
        connectorRecord.id +
        "/maintenance",
      200,
      "PATCH",
    );
    await section(staff.page)
      .getByText("Maintenance blocked", { exact: true })
      .waitFor();
    await staff.page.setViewportSize({ width: 390, height: 844 });
    assert(
      await staff.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "Nested inventory must fit a phone.",
    );
    assert.equal(
      (await outsider.context.request.get(base + path + "/sites")).status(),
      404,
    );
    await section(applicant.page)
      .getByRole("button", { name: "Refresh company", exact: true })
      .click();
    await section(applicant.page)
      .getByRole("button", { name: "Revoke access", exact: true })
      .waitFor();
    await section(applicant.page)
      .getByText(/Member #.*active/)
      .waitFor();
    applicant.page.once("dialog", (d) => d.accept());
    await section(applicant.page)
      .getByRole("button", { name: "Revoke access", exact: true })
      .click();
    await section(applicant.page)
      .getByText(/Member #.*revoked/)
      .waitFor();
    assert.equal(
      (await staff.context.request.get(base + path + "/sites")).status(),
      404,
    );
    await refresh(staff.page);
    assert.equal(
      await section(staff.page).getByText(name, { exact: true }).count(),
      0,
    );
    const invite = form(applicant.page, "Invite company staff");
    await invite.locator("[name=email]").fill("unsent@example.test");
    await applicant.page.locator("#language").click();
    assert.equal(
      await invite.locator("[name=email]").inputValue(),
      "unsent@example.test",
    );
    assert.equal(
      await applicant.page.locator("html").getAttribute("dir"),
      "rtl",
    );
    await applicant.page.setViewportSize({ width: 390, height: 844 });
    assert(
      await applicant.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await applicant.page.locator("#logout").click();
    await section(applicant.page).waitFor({ state: "hidden" });
    assert.equal(await section(applicant.page).textContent(), "");
    assert.deepEqual(errors, []);
    console.log(
      "PASS: actual Laravel company application/private encrypted evidence download and tenant privacy; required-evidence submission/internal review; customer-role staff consent, scoped site/charger/connector creation and maintenance; revocation, safe user text, RTL/input preservation, mobile width and logout cleanup. No legal KYC or physical control claimed.",
    );
  } finally {
    clearTimeout(watchdog);
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
