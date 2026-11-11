// Actual Chromium rendering with a synthetic API; not server Admin acceptance.
const { chromium } = require("playwright");
const fs = require("node:fs"),
  assert = require("node:assert/strict");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const page = await browser.newPage({
      viewport: { width: 390, height: 844 },
    });
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.name));
    await page.setContent(
      '<!doctype html><html><body><section id="account-privacy"></section><section id="admin-privacy"></section></body></html>',
    );
    await page.addScriptTag({
      content: fs.readFileSync(
        "services/business-api/public/account-privacy.js",
        "utf8",
      ),
    });
    await page.evaluate(() => {
      window.fixture = { releases: [], patches: 0, owner: 2 };
      const api = async (path, method = "GET", body) => {
        if (method === "PATCH") {
          window.fixture.patches++;
          return new Promise((resolve) =>
            window.fixture.releases.push(() =>
              resolve({ id: 22, status: body.status }),
            ),
          );
        }
        return {
          data: [
            {
              id: 22,
              kind: "erasure",
              status: "pending",
              details: "<img src=x onerror=alert(1)> private fixture",
              owner: {
                id: window.fixture.owner,
                name: "Fixture",
                email: "fixture@example.invalid",
              },
            },
          ],
          current_page: 1,
          last_page: 1,
          total: 1,
        };
      };
      window.fixture.api = api;
      window.EvPrivacyRequests.setAdminActor({ id: 1, role: "admin" }, api);
    });
    const root = page.locator("#admin-privacy");
    await root
      .getByText("<img src=x onerror=alert(1)> private fixture", {
        exact: true,
      })
      .waitFor();
    assert.equal(await root.locator("img").count(), 0);
    const form = root.getByRole("form", { name: "Privacy review decision 22" });
    await form
      .locator("textarea")
      .fill("Independent synthetic review explanation.");
    await form.locator("button").click();
    await page.waitForFunction(() => window.fixture.patches === 1);
    await page.evaluate(() => {
      window.EvPrivacyRequests.setAdminActor(null, window.fixture.api);
      window.fixture.releases[0]();
    });
    assert.equal(await root.innerText(), "");
    await page.evaluate(() => {
      window.fixture.owner = 1;
      window.EvPrivacyRequests.setAdminActor(
        { id: 1, role: "admin" },
        window.fixture.api,
      );
    });
    await root.locator("article").waitFor();
    assert.equal(
      await root
        .getByRole("form", { name: "Privacy review decision 22" })
        .count(),
      0,
    );
    assert.deepEqual(errors, []);
    console.log(
      "PASS canonical private Admin fixture: text-only content, delayed receipt isolation and independent reviewer controls. Synthetic API only.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
