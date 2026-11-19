// Local synthetic OCPI contract only; never a live reservation or billing claim.
const {
  withCredentialsFixture,
} = require("./helpers/ocpi-credentials-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const assert = require("node:assert/strict");
async function native(f) {
  const fs = require("node:fs"),
    path = require("node:path");
  const dir = fs.mkdtempSync(
    path.join(require("node:os").tmpdir(), "ev-native-reservation-"),
  );
  try {
    const input = path.join(dir, "fixture.json");
    fs.writeFileSync(input, JSON.stringify(f), { mode: 0o600 });
    await new Promise((resolve, reject) => {
      const child = require("node:child_process").spawn(
        "flutter",
        [
          "test",
          "--no-pub",
          "test/live_sandbox_reservation_test.dart",
          "--reporter",
          "expanded",
        ],
        {
          cwd: "apps/mobile",
          stdio: "inherit",
          env: { ...process.env, EV_NATIVE_RESERVATION_FIXTURE: input },
        },
      );
      child.once("error", () =>
        reject(Error("Native reservation proof could not start.")),
      );
      child.once("close", (code) =>
        code === 0
          ? resolve()
          : reject(Error("Native reservation proof failed.")),
      );
    });
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}
withCredentialsFixture(
  async (f) => {
    if (process.env.EV_RESERVATION_NATIVE_ONLY === "1") {
      await native(f);
      return;
    }
    const login = async (email) => {
      const c = new BusinessClient({ base: f.base });
      c.csrf = (await c.request("csrf")).token;
      await c.request("login", "POST", { email, password: f.password });
      c.csrf = (await c.request("csrf")).token;
      return c;
    };
    const driver = await login(f.driver),
      { chromium } = require("playwright");
    const browser = await chromium.launch({
      headless: true,
      executablePath: process.env.EV_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    });
    const errors = [];
    try {
      const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
      });
      await context.addCookies(
        [...driver.cookies].map(([name, value]) => ({
          name,
          value,
          url: f.base,
        })),
      );
      const page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      page.on("dialog", (d) => d.accept());
      await page.goto(f.base);
      const pub = page.locator("#roaming-discovery");
      await pub
        .locator("article")
        .filter({ hasText: "Synthetic roaming " + f.run })
        .getByRole("button", { name: "View sandbox offers", exact: true })
        .click();
      let panel = pub.locator("[data-roaming-sandbox]");
      const form = panel.locator("[data-sandbox-reservation]");
      await form.waitFor();
      assert.match(
        await form.textContent(),
        /preauthorizes.*without a separate start request/,
      );
      await form.getByRole("combobox").selectOption("10");
      await form.getByRole("checkbox").check();
      await form
        .getByRole("button", { name: "Reserve sandbox EVSE", exact: true })
        .click();
      await panel.locator("[data-sandbox-order]").waitFor();
      const source = f.inspect(),
        reserve = source.commands.RESERVE_NOW;
      assert.equal(reserve.attempted, true);
      assert.equal(reserve.payload.token.valid, true);
      assert.equal(reserve.payload.token.whitelist, "NEVER");
      assert(!("connector_id" in reserve.payload));
      assert.equal(Object.keys(source.commands).length, 1);
      assert.equal(source.financial_invoices, 0);
      assert.equal(source.charging_sessions, 0);
      const order = await driver.request(
        "roaming/sandbox-orders/" + source.order_id,
      );
      assert.equal(order.intent_kind, "reservation");
      assert.equal(order.can_cancel_reservation, true);
      assert.equal(order.can_stop, false);
      assert.equal(order.reservation_closed, false);
      assert(!(await panel.textContent()).includes(source.token.uid));
      await page.locator("#language").click();
      await panel
        .getByRole("button", { name: "إلغاء الحجز التجريبي", exact: true })
        .waitFor();
      assert.match(await panel.textContent(), /تظل السعة محجوزة/);
      await page.locator("#language").click();
      await panel
        .getByRole("button", {
          name: "Cancel sandbox reservation",
          exact: true,
        })
        .click();
      await panel
        .getByText("reservation_cancelling", { exact: false })
        .waitFor();
      const cancelled = f.inspect();
      assert.equal(cancelled.commands.TOKEN_REVOKE.payload.valid, false);
      assert.equal(
        cancelled.commands.CANCEL_RESERVATION.payload.reservation_id,
        reserve.payload.reservation_id,
      );
      assert.equal(cancelled.commands.STOP_SESSION, undefined);
      assert.equal(cancelled.financial_invoices, 0);
      const stillHeld = await driver.request(
        "roaming/sandbox-orders/" + source.order_id + "/reconcile",
        "POST",
        {},
      );
      assert.equal(stillHeld.reservation_closed, false);
      assert.equal(stillHeld.physical_charging_verified, false);
      for (const reviewer of [await login(f.owner), await login(f.admin)]) {
        const review = await browser.newContext({
          viewport: { width: 390, height: 844 },
        });
        await review.addCookies(
          [...reviewer.cookies].map(([name, value]) => ({
            name,
            value,
            url: f.base,
          })),
        );
        const rp = await review.newPage();
        rp.on("pageerror", (e) => errors.push(e.message));
        await rp.goto(f.base);
        const ops = rp.locator("#roaming-operations");
        await ops
          .locator("article")
          .filter({ hasText: f.name })
          .first()
          .getByRole("button", {
            name: "Sandbox roaming activity",
            exact: true,
          })
          .click();
        const p = ops.locator("[data-roaming-sandbox]");
        await p
          .getByText("Reservation preauthorization notice", { exact: false })
          .count();
        await p.getByText("Reservation expires", { exact: false }).waitFor();
        assert.equal(
          await p
            .getByRole("button", {
              name: "Cancel sandbox reservation",
              exact: true,
            })
            .count(),
          0,
        );
        assert(!(await p.textContent()).includes(source.token.uid));
        await rp.locator("#language").click();
        await p.getByText("انتهاء الحجز", { exact: false }).waitFor();
        await review.close();
      }
      assert.deepEqual(errors, []);
      console.log(
        "PASS actual Laravel/web EN/AR reservation preauthorization, fixed cancellation, capacity hold, vendor/Admin privacy; synthetic only.",
      );
    } finally {
      await browser.close();
    }
  },
  { reservations: true },
).catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
