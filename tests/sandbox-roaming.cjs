// Isolated approved TEST contract only. No live CPO, charging or billing claim.
const {
  withCredentialsFixture,
} = require("./helpers/ocpi-credentials-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { randomUUID } = require("node:crypto");
const assert = require("node:assert/strict");
function nativeProof(f) {
  const fs = require("node:fs"),
    { join } = require("node:path"),
    { tmpdir } = require("node:os");
  const directory = fs.mkdtempSync(join(tmpdir(), "ev-native-roaming-"));
  try {
    const input = join(directory, "fixture.json");
    fs.writeFileSync(input, JSON.stringify(f), { mode: 0o600 });
    require("node:child_process").execFileSync(
      "flutter",
      ["test", "test/live_sandbox_roaming_test.dart", "--reporter", "expanded"],
      {
        cwd: "apps/mobile",
        stdio: "inherit",
        env: { ...process.env, EV_NATIVE_SANDBOX_ROAMING_FIXTURE: input },
      },
    );
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}
withCredentialsFixture(
  async (f) => {
    if (process.env.EV_SANDBOX_NATIVE_ONLY === "1") {
      nativeProof(f);
      return;
    }
    const { chromium } = require("playwright");
    const login = async (email) => {
      const c = new BusinessClient({ base: f.base });
      c.csrf = (await c.request("csrf")).token;
      await c.request("login", "POST", { email, password: f.password });
      c.csrf = (await c.request("csrf")).token;
      return c;
    };
    const driver = await login(f.driver);
    const peer = async (
      path,
      method,
      body,
      expected = 200,
      token = f.local_token,
    ) => {
      const response = await fetch(
        f.base + "/api/roaming-protocol/2.2.1/" + path,
        {
          method,
          headers: {
            Authorization: "Token " + Buffer.from(token).toString("base64"),
            "X-Request-ID": randomUUID(),
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: body === undefined ? undefined : JSON.stringify(body),
          signal: AbortSignal.timeout(15000),
        },
      );
      assert.equal(
        response.status,
        expected,
        "Synthetic CPO protocol status mismatch",
      );
      return response.json();
    };
    const browser = await chromium.launch({
      headless: true,
      executablePath: process.env.EV_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    });
    try {
      const context = await browser.newContext();
      await context.addCookies(
        [...driver.cookies].map(([name, value]) => ({
          name,
          value,
          url: f.base,
        })),
      );
      const page = await context.newPage();
      page.on("dialog", (d) => d.accept());
      const errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(f.base);
      const pub = page.locator("#roaming-discovery");
      await pub
        .getByRole("button", { name: "View sandbox offers", exact: true })
        .first()
        .waitFor();
      // Select our fixture's location even when earlier synthetic providers remain.
      await pub
        .locator("article")
        .filter({ hasText: "Synthetic roaming " + f.run })
        .getByRole("button", { name: "View sandbox offers", exact: true })
        .click();
      let panel = pub.locator("[data-roaming-sandbox]");
      const start = panel.getByRole("button", {
        name: "Start sandbox roaming",
        exact: true,
      });
      await start.waitFor();
      await panel.getByRole("checkbox").check();
      await start.click();
      await panel.locator("[data-sandbox-order]").waitFor();
      let source = f.inspect();
      const id = source.order_id,
        token = source.token;
      assert(source.commands.START_SESSION.attempted);
      assert.equal(token.whitelist, "NEVER");
      assert.equal(token.type, "APP_USER");
      assert.equal(source.charging_sessions, 0);
      assert.equal(source.financial_invoices, 0);
      assert(!(await panel.textContent()).includes(token.uid));
      assert.equal(
        await panel
          .getByRole("button", { name: "Request sandbox stop", exact: true })
          .count(),
        0,
      );
      const callback = new URL(
        source.commands.START_SESSION.payload.response_url,
      ).pathname.split("/2.2.1/")[1];
      await peer(callback, "POST", { result: "ACCEPTED" });
      assert.equal(
        (await driver.request("roaming/sandbox-orders/" + id)).state,
        "start_accepted",
      );
      const auth = "tokens/" + token.uid + "/authorize?type=APP_USER";
      assert.equal(
        (await peer(auth, "POST", { location_id: "WRONG", evse_uids: ["E1"] }))
          .data.allowed,
        "NOT_ALLOWED",
      );
      assert.equal(
        (await peer(auth, "POST", { location_id: "L1", evse_uids: ["E1"] }))
          .data.allowed,
        "ALLOWED",
      );
      const at = new Date().toISOString();
      const session = {
        country_code: "NL",
        party_id: "ABC",
        id: "S-" + f.run,
        last_updated: at,
        start_date_time: at,
        currency: "EUR",
        status: "ACTIVE",
        kwh: "0.01",
        location_id: "L1",
        evse_uid: "E1",
        connector_id: "1",
        total_cost: { excl_vat: "0.0025" },
        authorization_reference: token.contract_id,
        cdr_token: Object.fromEntries(
          ["country_code", "party_id", "uid", "type", "contract_id"].map(
            (k) => [k, token[k]],
          ),
        ),
      };
      const sessionPath = "sessions/NL/ABC/" + session.id;
      await peer(sessionPath, "PUT", session, 401, "INVALID-SYNTHETIC-PEER");
      await peer(sessionPath, "PUT", session, 201);
      await panel
        .getByRole("button", { name: "Refresh sandbox source", exact: true })
        .click();
      const stop = panel.getByRole("button", {
        name: "Request sandbox stop",
        exact: true,
      });
      await stop.waitFor();
      await stop.click();
      await page.waitForFunction(() =>
        document
          .querySelector("[data-roaming-sandbox]")
          ?.textContent.includes("STOP_SESSION"),
      );
      source = f.inspect();
      assert.equal(source.commands.STOP_SESSION.payload.session_id, session.id);
      const row = await driver.request("roaming/sandbox-orders/" + id);
      assert.equal(row.state, "source_active");
      assert.equal(row.can_stop, false);
      const end = new Date(Date.now() + 1000).toISOString();
      Object.assign(session, {
        last_updated: end,
        end_date_time: end,
        status: "COMPLETED",
      });
      await peer(sessionPath, "PUT", session);
      const cdr = {
        country_code: "NL",
        party_id: "ABC",
        id: "C-" + f.run,
        session_id: session.id,
        last_updated: end,
        start_date_time: at,
        end_date_time: end,
        currency: "EUR",
        total_energy: "0.01",
        total_time: "0.001",
        total_cost: session.total_cost,
        cdr_location: { id: "L1", evse_uid: "E1", connector_id: "1" },
        cdr_token: session.cdr_token,
        authorization_reference: token.contract_id,
      };
      await peer("cdrs", "POST", cdr, 201);
      await panel
        .getByRole("button", { name: "Refresh sandbox source", exact: true })
        .click();
      await page.waitForFunction(() =>
        document
          .querySelector("[data-roaming-sandbox]")
          ?.textContent.includes("source_consistent"),
      );
      assert((await panel.textContent()).includes("source_completed"));
      assert.equal(f.inspect().financial_invoices, 0);
      await page.setViewportSize({ width: 390, height: 844 });
      await page.locator("#language").click();
      await panel
        .getByRole("heading", { name: "نشاط التجوال التجريبي" })
        .waitFor();
      assert.equal(
        await page.evaluate(
          () => document.documentElement.scrollWidth > innerWidth + 1,
        ),
        false,
      );
      await page.locator("#language").click();
      await panel
        .getByRole("button", { name: "Revoke sandbox authority", exact: true })
        .click();
      await page.waitForFunction(() =>
        document
          .querySelector("[data-roaming-sandbox]")
          ?.textContent.includes("partner_revoked"),
      );
      assert.equal(
        (await peer(auth, "POST", { location_id: "L1", evse_uids: ["E1"] }))
          .data.allowed,
        "NOT_ALLOWED",
      );
      await page.locator("#logout").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-roaming-sandbox]"),
      );
      const owner = await login(f.owner);
      const ops = await owner.request(
        `ocpi/providers/${f.provider_id}/sandbox-orders`,
      );
      assert.equal(ops.data[0].can_stop, false);
      assert.equal(ops.data[0].can_revoke, false);
      assert(!JSON.stringify(ops).includes(token.uid));
      const other = await login(f.other);
      assert.equal((await other.request("roaming/sandbox-orders")).total, 0);
      assert.deepEqual(errors, []);
      if (process.env.EV_NATIVE_SANDBOX === "1") nativeProof(f);
      console.log(
        "PASS sandbox consent/token/START, authenticated CPO session, STOP, completed source/CDR comparison, revocation, operator privacy, Arabic phone and logout; no financial or physical acceptance.",
      );
    } finally {
      await browser.close();
    }
  },
  { roaming: true },
).catch((e) => {
  console.error(
    e.name === "AssertionError" || e.name === "TimeoutError"
      ? e.message
      : "Sandbox roaming integration failed; private fixture details withheld.",
  );
  process.exitCode = 1;
});
