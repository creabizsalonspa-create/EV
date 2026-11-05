const {
  withCredentialsFixture,
} = require("./helpers/ocpi-credentials-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
withCredentialsFixture(
  async (f) => {
    assert(f.fixture_only && f.incoming);
    const client = new BusinessClient({ base: f.base });
    client.csrf = (await client.request("csrf")).token;
    await client.request("login", "POST", {
      email: f.admin,
      password: f.password,
    });
    const path = `ocpi/providers/${f.provider_id}/credentials`;
    assert.deepEqual((await client.request(path)).available_actions, [
      "prepare_incoming",
    ]);
    const browser = await chromium.launch({
      headless: true,
      executablePath: process.env.EV_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    });
    try {
      const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
      });
      await context.addCookies(
        [...client.cookies].map(([name, value]) => ({
          name,
          value,
          url: f.base,
        })),
      );
      const page = await context.newPage(),
        errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      page.on("dialog", (d) => d.accept());
      await page.goto(f.base);
      const card = page
        .locator("#roaming-operations article")
        .filter({
          has: page.getByRole("heading", { name: f.name, exact: true }),
        });
      await card
        .getByRole("button", { name: "Partner credentials", exact: true })
        .click();
      const detail = page.locator("[data-roaming-detail]");
      await detail
        .locator("textarea")
        .fill("Approved local synthetic partner enrollment");
      await detail
        .getByRole("button", {
          name: "Prepare incoming enrollment",
          exact: true,
        })
        .click();
      await detail
        .getByText("Awaiting approved partner enrollment", { exact: false })
        .first()
        .waitFor();
      await detail
        .getByText("Enrollment expires (UTC)", { exact: false })
        .waitFor();
      assert(!(await detail.textContent()).includes(f.local_token));
      assert.equal(
        await detail
          .getByRole("button", {
            name: "Prepare incoming enrollment",
            exact: true,
          })
          .count(),
        0,
      );
      await page.locator("#language").click();
      await page.waitForFunction(() => document.documentElement.dir === "rtl");
      await page
        .getByRole("button", { name: "بيانات اعتماد الشريك", exact: true })
        .first()
        .click();
      await detail
        .getByText("في انتظار تسجيل الشريك المعتمد", { exact: false })
        .first()
        .waitFor();
      assert(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= window.innerWidth + 1,
        ),
      );
      assert.deepEqual(errors, []);
    } finally {
      await browser.close();
    }
    const headers = {
      Authorization: "Token " + Buffer.from(f.local_token).toString("base64"),
    };
    assert.equal(
      (await fetch(f.base + "/api/roaming-protocol/versions", { headers }))
        .status,
      200,
    );
    assert.equal(
      (
        await fetch(f.base + "/api/roaming-protocol/2.2.1/credentials", {
          headers,
        })
      ).status,
      401,
    );
    const pending = await client.request(path);
    assert.equal(pending.state, "awaiting_registration");
    assert(pending.bootstrap_expires_at);
    console.log(
      "PASS: actual local Admin EN/AR390px incoming approval, bounded bootstrap discovery, credentials access denied before enrollment and native-compatible expiry metadata. No external peer request; activation is covered by controlled protocol contracts.",
    );
  },
  { incoming: true },
).catch(() => {
  console.error(
    "Synthetic incoming enrollment validation failed; private protocol details withheld.",
  );
  process.exitCode = 1;
});
