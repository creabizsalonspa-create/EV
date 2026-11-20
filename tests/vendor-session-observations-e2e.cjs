"use strict";
const assert = require("node:assert/strict"),
  { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  { mkdirSync } = require("node:fs"),
  { chromium } = require("playwright"),
  { BusinessClient } = require("./helpers/business-client.cjs");
let stage = "fixture";
(async () => {
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000",
    target = new URL(base);
  assert(
    target.protocol === "http:" &&
      ["127.0.0.1", "localhost"].includes(target.hostname),
  );
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "Aa9!",
    runner = process.env.EV_TEST_PHP_RUNNER || "./scripts/php";
  const invoke = (mode, ...args) =>
    JSON.parse(
      execFileSync(
        runner,
        ["tests/Fixtures/vendor-session-observations.php", mode, run, ...args],
        { encoding: "utf8" },
      ),
    );
  const f = invoke("seed", password, "meters"),
    before = invoke("snapshot");
  assert.equal(before.rows, 27);
  async function login(email) {
    const c = new BusinessClient({ base });
    c.csrf = (await c.request("csrf")).token;
    await c.request("login", "POST", { email, password });
    c.csrf = (await c.request("csrf")).token;
    return c;
  }
  const owner = await login(f.owner_email),
    staff = await login(f.staff_email),
    path = `vendor-ops/companies/${f.company_id}/charging-sessions`;
  stage = "versioned-consent";
  await owner.request(
    `vendor-ops/companies/${f.company_id}/staff/${f.membership_id}/permissions`,
    "PATCH",
    { expected_version: 1, permissions: ["charging.read"] },
  );
  await assert.rejects(() => staff.request(path), /404/);
  await assert.rejects(
    () =>
      staff.request(
        `vendor-ops/staff-invitations/${f.membership_id}/accept`,
        "POST",
        { expected_version: 1, consent: true },
      ),
    /409/,
  );
  await staff.request(
    `vendor-ops/staff-invitations/${f.membership_id}/accept`,
    "POST",
    { expected_version: 2, consent: true },
  );
  const batch = await staff.request(path);
  assert.equal(batch.total, 27);
  assert.equal(batch.data.length, 25);
  assert.equal(batch.financial_fields_included, false);
  assert(!JSON.stringify(batch).includes("PRIVATE"));
  assert(!JSON.stringify(batch).includes("total_minor"));
  stage = "browser";
  const browser = await chromium.launch({
    executablePath: process.env.EV_CHROMIUM_PATH || "/usr/bin/chromium",
    headless: true,
    args: ["--no-sandbox"],
  });
  mkdirSync("artifacts/vendor-session-observations", { recursive: true });
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    await context.addCookies(
      [...staff.cookies].map(([name, value]) => ({ name, value, url: base })),
    );
    const p = await context.newPage();
    p.setDefaultTimeout(15000);
    await p.goto(base);
    await p.waitForLoadState("networkidle");
    for (const lang of ["en", "ar"]) {
      if (lang === "ar") {
        await p.locator("#language").click();
        await p.waitForFunction(() => document.documentElement.dir === "rtl");
      }
      const button = p.getByRole("button", {
        name:
          lang === "ar" ? "جلسات الشحن المسجلة" : "Recorded charging sessions",
        exact: true,
      });
      stage = lang + "-open";
      await button.click();
      const panel = p.locator("[data-company-session-observations]");
      await panel.locator("article").first().waitFor();
      assert.equal(await panel.locator("article").count(), 25);
      assert(!(await panel.textContent()).includes("PRIVATE"));
      stage = lang + "-next";
      await panel
        .getByRole("button", {
          name: lang === "ar" ? "التالي" : "Next",
          exact: true,
        })
        .click();
      await panel
        .getByText(
          lang === "ar"
            ? "الصفحة 2 / 2 · 27 جلسات مسجلة"
            : "Page 2 / 2 · 27 recorded sessions",
          { exact: true },
        )
        .waitFor();
      assert.equal(await panel.locator("article").count(), 2);
      stage = lang + "-empty-filter";
      await panel.locator("select").selectOption("charging");
      await panel
        .getByText(
          lang === "ar"
            ? "لا توجد سجلات ضمن النطاق المرشح."
            : "No records in this filtered scope.",
          { exact: true },
        )
        .waitFor();
      assert.equal(await panel.locator("article").count(), 0);
      stage = lang + "-stopped-filter";
      await panel.locator("select").selectOption("stopped");
      await panel.locator("article").first().waitFor();
      stage = lang + "-meter-history";
      await panel
        .locator("article")
        .first()
        .getByRole("button", {
          name:
            lang === "ar" ? "سجل العدادات المسجل" : "Recorded meter history",
          exact: true,
        })
        .click();
      await panel.locator("article").first().waitFor();
      assert.equal(await panel.locator("article").count(), 25);
      assert((await panel.textContent()).includes("1002500 Wh"));
      assert(!(await panel.textContent()).includes("PRIVATE"));
      await panel
        .getByRole("button", {
          name: lang === "ar" ? "التالي" : "Next",
          exact: true,
        })
        .click();
      await panel
        .getByText(
          lang === "ar"
            ? "الصفحة 2 / 2 · 27 أحداث مصدر مسجلة"
            : "Page 2 / 2 · 27 recorded source events",
          { exact: true },
        )
        .waitFor();
      assert.equal(await panel.locator("article").count(), 2);
      await panel
        .locator("h3")
        .evaluate((node) => node.scrollIntoView({ block: "start" }));
      await p.screenshot({
        path: `artifacts/vendor-session-observations/${lang}-meter-390.png`,
      });
      await panel
        .getByRole("button", {
          name:
            lang === "ar"
              ? "العودة إلى الجلسات المسجلة"
              : "Back to recorded sessions",
          exact: true,
        })
        .click();
      await panel.locator("select").waitFor();
      await panel.locator("article").first().waitFor();
      assert(
        await p.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
      await panel
        .locator("h3")
        .evaluate((node) => node.scrollIntoView({ block: "start" }));
      await p.screenshot({
        path: `artifacts/vendor-session-observations/${lang}-390.png`,
      });
    }
    await p.waitForLoadState("networkidle");
    stage = "revocation";
    invoke("revoke");
    const panel = p.locator("[data-company-session-observations]");
    await panel
      .getByRole("button", { name: "قراءة الصفحة الأولى", exact: true })
      .click();
    await panel
      .getByText(
        "ملاحظات الجلسات غير متاحة. أعد هذه القراءة صراحة؛ لم يُرسل أمر.",
        { exact: true },
      )
      .waitFor();
    assert.equal(await panel.locator("article").count(), 0);
    await context.close();
  } finally {
    await browser.close();
  }
  const after = invoke("snapshot");
  assert.deepEqual(after, before);
  console.log(
    "PASS: actual Laravel versioned charging-read consent, private company scope, EN/AR390 pagination/filter, revocation and unchanged session/command/finance storage. Synthetic records only; no charger or financial action.",
  );
})().catch(() => {
  console.error(
    "FAIL: vendor session observations stage " +
      stage +
      " (private details omitted)",
  );
  process.exitCode = 1;
});
