const { chromium } = require("playwright");
const assert = require("node:assert/strict");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    async function fixture(blocked = false, owner = true) {
      const page = await browser.newPage();
      await page.route("http://127.0.0.1:19582/**", (route) =>
        route.fulfill({
          contentType: "text/html",
          body: '<section id="workspace"></section>',
        }),
      );
      await page.goto("http://127.0.0.1:19582/");
      await page.addScriptTag({
        path: "services/business-api/public/vendor-ops.js",
      });
      await page.evaluate(
        async ({ blocked, owner }) => {
          window.notices = [];
          window.docsRead = false;
          window.actor = {
            id: 1,
            role: "vendor",
            email_verified_at: "2026-11-14T00:00:00Z",
          };
          window.EvLocale = {
            attribute: (node, key, label) => node.setAttribute(key, label),
            bind: (node, text) => {
              node.textContent = text;
              return node;
            },
            text: (text) => text,
          };
          const paged = (data) => ({
            data,
            current_page: 1,
            last_page: 1,
            total: data.length,
          });
          const company = {
            id: 1,
            legal_name: "Scoped company fixture",
            status: "approved",
            capabilities: { owner, company_read: true, private_evidence: true },
          };
          const pause = new Promise((resolve) => (window.release = resolve));
          window.workspace = createVendorWorkspace({
            root: document.querySelector("#workspace"),
            actor: () => window.actor,
            ui: (tag, text) => {
              const node = document.createElement(tag);
              node.textContent = text;
              return node;
            },
            csrf: () => "",
            expired: () => {
              window.actor = null;
              window.workspace.clear();
            },
            notice: (error) => window.notices.push(error.status),
            api: async (path, method = "GET") => {
              if (method !== "GET") throw Error("Fixture must never mutate");
              if (path === "regions") return [];
              if (path.startsWith("vendor-ops/companies?"))
                return paged([company]);
              if (path.includes("/documents?")) {
                window.docsRead = true;
                if (blocked) await pause;
                throw Object.assign(
                  Error("Controlled unavailable evidence read"),
                  { status: 503 },
                );
              }
              return paged([]);
            },
          });
          await window.workspace.setActor(window.actor);
        },
        { blocked, owner },
      );
      await page
        .getByRole("button", { name: "Open company", exact: true })
        .click();
      await page.waitForFunction(() => window.docsRead);
      return page;
    }
    const owner = await fixture();
    await owner.waitForFunction(() => window.notices.length > 0);
    assert.equal(
      await owner.locator('[data-vendor-form="Invite company staff"]').count(),
      1,
      "An unavailable evidence-history read must not remove independent authorized staff controls",
    );
    const revoked = await fixture(true);
    await revoked.evaluate(() => {
      window.actor = null;
      window.workspace.clear();
      window.release();
    });
    await revoked.evaluate(
      () =>
        new Promise((resolve) =>
          requestAnimationFrame(() => requestAnimationFrame(resolve)),
        ),
    );
    assert.equal(await revoked.locator("#workspace").textContent(), "");
    const viewer = await fixture(false, false);
    await viewer.waitForFunction(() => window.notices.length > 0);
    assert.equal(
      await viewer.locator('[data-vendor-form="Invite company staff"]').count(),
      0,
    );
    console.log(
      "PASS: evidence-read failure preserves independent owner controls; revoked and non-owner contexts cannot acquire them. Controlled fixtures only.",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
