const assert = require("node:assert/strict");
const { chromium } = require("playwright");

(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    for (const language of ["en", "ar"]) {
      const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
      await page.setContent('<main id="recovery"></main>');
      await page.evaluate(() => { window.EV_MESSAGES = {}; });
      for (const script of ["locale", "money-input", "station-time", "payment-recovery"]) {
        await page.addScriptTag({ path: `services/business-api/public/${script}.js` });
      }
      await page.evaluate((lang) => {
        EvLocale.set(lang);
        const root = document.querySelector("#recovery");
        let actor = { id: 10 };
        window.changeActor = () => { actor = { id: 10 }; };
        window.calls = [];
        const api = async (path, method = "GET") => {
          window.calls.push([path, method]);
          if (method === "POST") throw new Error("PRIVATE PROVIDER DIAGNOSTIC");
          return {
            data: path.endsWith("/events")
              ? [{ status: "uncertain", created_at: "2026-11-14T08:00:00+04:00" }]
              : [{ id: 19, status: "uncertain", currency: "AED", amount_minor: 120,
                  minor_units: 2, kind: "capture", scope: "charging" }],
            current_page: 1, last_page: 1,
          };
        };
        window.mountPaymentRecovery({
          container: root, api,
          ui: (tag, key) => EvLocale.bind(document.createElement(tag), key),
          actor: () => actor,
        });
      }, language);
      const ar = language === "ar";
      const load = page.getByRole("button", { name: ar ? "تحميل سجلات استرداد الحالة" : "Load recovery records" });
      const history = page.getByRole("button", { name: ar ? "عرض سجل استرداد الحالة" : "View recovery history" });
      const inspect = page.getByRole("button", { name: ar ? "التحقق من نتيجة مزود الدفع" : "Check provider outcome" });
      await load.click();
      await history.click();
      const clock = await page.locator("[data-recovery-history] li").innerText();
      assert.match(clock, /(?:04:00|٠٤:٠٠).*UTC/);
      assert.doesNotMatch(clock, /2026-11-14T08:00:00/);
      await inspect.evaluate((button) => { window.retained = button.onclick; });
      await load.click();
      await inspect.waitFor();
      await page.evaluate(() => window.retained());
      assert.equal(await page.evaluate(() => window.calls.filter((call) => call[1] === "POST").length), 0);

      await inspect.evaluate((button) => { window.retained = button.onclick; });
      await page.evaluate(() => { window.changeActor(); return window.retained(); });
      assert.equal(await page.evaluate(() => window.calls.filter((call) => call[1] === "POST").length), 0);
      await page.close();
    }

    const page = await browser.newPage();
    await page.setContent('<main id="recovery"></main>');
    await page.evaluate(() => { window.EV_MESSAGES = {}; });
    for (const script of ["locale", "money-input", "station-time", "payment-recovery"]) {
      await page.addScriptTag({ path: `services/business-api/public/${script}.js` });
    }
    await page.evaluate(() => {
      const actor = { id: 10 };
      window.mountPaymentRecovery({
        container: document.querySelector("#recovery"),
        ui: (tag, key) => EvLocale.bind(document.createElement(tag), key),
        actor: () => actor,
        api: async (path, method) => {
          if (method === "POST") throw new Error("PRIVATE PROVIDER DIAGNOSTIC");
          return {
            data: [{ id: 19, status: "uncertain", currency: "AED", amount_minor: 120,
              minor_units: 2, kind: "capture", scope: "charging" }],
            current_page: 1, last_page: 1,
          };
        },
      });
    });
    await page.getByRole("button", { name: "Load recovery records" }).click();
    await page.getByRole("button", { name: "Check provider outcome" }).click();
    await page.getByRole("status").getByText(/Reload the records before continuing/).waitFor();
    assert.equal(await page.locator("article").count(), 0);
    assert.equal(await page.getByText("PRIVATE PROVIDER DIAGNOSTIC").count(), 0);
    console.log("PASS payment recovery clock locale, detached and retired actions, and safe uncertain-response state.");
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
