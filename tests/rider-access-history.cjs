// Browser contract only: source data is synthetic; Laravel scope is tested separately.
const assert = require("node:assert/strict");
const { chromium } = require("playwright");
const path = require("node:path");
async function verifyRiderAccessHistory() {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const page = await browser.newPage({
      viewport: { width: 390, height: 844 },
    });
    await page.route("https://rider-history.example.test/**", (route) =>
      route.fulfill({
        contentType: "text/html",
        body: '<a href="#rider-access"></a><main id="access"></main>',
      }),
    );
    await page.goto("https://rider-history.example.test/");
    await page.addScriptTag({
      path: path.resolve("services/business-api/public/locale-data.js"),
    });
    await page.addScriptTag({
      path: path.resolve("services/business-api/public/rider-access.js"),
    });
    await page.evaluate(async () => {
      window.calls = [];
      window.language = "en";
      window.EvLocale = {
        text: (key) => window.EV_MESSAGES[key]?.[window.language] || key,
        bind: (n, key) => {
          n.textContent = window.EvLocale.text(key);
        },
      };
      const ui = (tag, key) => {
        const node = document.createElement(tag);
        node.textContent = window.EvLocale.text(key);
        return node;
      };
      window.workspace = window.createRiderAccessWorkspace({
        root: document.querySelector("#access"),
        ui,
        expired: () => {},
        notice: (e) => {
          throw e;
        },
        api: async (route, method) => {
          window.calls.push({ route, method });
          const url = new URL(route, "https://fixture.invalid");
          const kind = url.searchParams.get("kind") || "events";
          return {
            read_only: true,
            hardware_verified: false,
            kind,
            current_page: 1,
            last_page: 1,
            data:
              route === "rider-access/tokens?page=1"
                ? [
                    {
                      id: 4,
                      label: "<img src=x onerror=alert(1)>",
                      token_type: "ISO14443",
                      suffix: "3456",
                      expires_at: "2099-01-01",
                      provisioning_state: "unregistered",
                      revoked_at: "2026-11-07",
                    },
                  ]
                : kind === "events"
                  ? [
                      {
                        id: 1,
                        action: "grant.approved",
                        created_at: "2026-11-07",
                      },
                    ]
                  : [
                      {
                        id: 2,
                        state: "awaiting_source",
                        charging_session_id: 8,
                        protocol: "2.0.1",
                        created_at: "2026-11-07",
                      },
                    ],
          };
        },
      });
      await window.workspace.setActor({ id: 1, role: "customer" });
    });
    assert.equal(await page.locator("[data-rider-token] img").count(), 0);
    await page
      .getByRole("button", { name: "Charging access activity", exact: true })
      .click();
    await page.getByText("Operator approved access", { exact: true }).waitFor();
    await page
      .getByRole("button", { name: "Card charging attempts", exact: true })
      .click();
    await page
      .getByText("Awaiting source transaction", { exact: true })
      .waitFor();
    assert.equal(
      await page.getByText("Source start observed", { exact: true }).count(),
      0,
    );
    await page.evaluate(() => {
      window.language = "ar";
    });
    await page
      .getByRole("button", { name: "Access lifecycle", exact: true })
      .click();
    await page.getByText("وافق المشغل على الوصول", { exact: true }).waitFor();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      true,
    );
    await page.evaluate(() => {
      Object.defineProperty(document, "hidden", {
        configurable: true,
        value: true,
      });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    assert.equal(
      await page.getByText("وافق المشغل على الوصول", { exact: true }).count(),
      0,
    );
    await page.evaluate(() => window.workspace.clear());
    assert.equal(await page.locator("#access").textContent(), "");
    assert.equal(
      await page.evaluate(() => window.calls.every((c) => c.method === "GET")),
      true,
    );
    console.log(
      "PASS: synthetic browser history navigation, Arabic phone layout, no inferred charging, private background/logout clearing, GET-only requests",
    );
  } finally {
    await browser.close();
  }
}
module.exports = verifyRiderAccessHistory;
if (require.main === module)
  verifyRiderAccessHistory().catch((e) => {
    console.error(e);
    process.exitCode = 1;
  });
