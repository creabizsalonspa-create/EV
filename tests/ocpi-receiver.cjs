// Real managed-token HTTP reception and owned operational UI. Synthetic partner only.
const {
  withCredentialsFixture,
} = require("./helpers/ocpi-credentials-local.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
withCredentialsFixture(
  async (f) => {
    const request = async (
      path,
      method,
      body,
      expected,
      token = f.local_token,
    ) => {
      const response = await fetch(
        f.base + "/api/roaming-protocol/2.2.1/" + path,
        {
          method,
          headers: {
            Authorization: "Token " + Buffer.from(token).toString("base64"),
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
        "Managed OCPI receiver status mismatch.",
      );
      return response.json();
    };
    const at = new Date(Date.now() - 60000).toISOString();
    const session = {
      country_code: "NL",
      party_id: "ABC",
      id: "session-" + f.run,
      last_updated: at,
      start_date_time: new Date(Date.now() - 7200000).toISOString(),
      end_date_time: new Date(Date.now() - 3600000).toISOString(),
      currency: "EUR",
      status: "COMPLETED",
      kwh: "12.34",
      location_id: "synthetic-location",
      evse_uid: "evse-1",
      connector_id: "1",
      total_cost: { excl_vat: "6.17" },
    };
    const path = "sessions/NL/ABC/" + session.id;
    await request(path, "PUT", session, 401, "SYNTHETIC-INVALID-TOKEN");
    await request(path, "PUT", { ...session, party_id: "BAD" }, 403);
    await request(path, "PUT", session, 201);
    await request(path, "PUT", session, 200);
    const cdr = {
      country_code: "NL",
      party_id: "ABC",
      id: "cdr-" + f.run,
      session_id: session.id,
      last_updated: at,
      start_date_time: session.start_date_time,
      end_date_time: session.end_date_time,
      currency: session.currency,
      total_energy: "12.3400",
      total_time: "1",
      total_cost: session.total_cost,
      cdr_location: {
        id: session.location_id,
        evse_uid: session.evse_uid,
        connector_id: session.connector_id,
      },
      cdr_token: { uid: "PRIVATE-SOURCE-DRIVER" },
    };
    await request("cdrs", "POST", cdr, 201);
    await request("cdrs", "POST", cdr, 200);
    await request("cdrs", "POST", { ...cdr, total_energy: "99" }, 409);
    const client = new BusinessClient({ base: f.base });
    client.csrf = (await client.request("csrf")).token;
    await client.request("login", "POST", {
      email: f.owner,
      password: f.password,
    });
    client.csrf = (await client.request("csrf")).token;
    const records = await client.request(
      "ocpi/providers/" + f.provider_id + "/records/cdrs",
    );
    assert.equal(records.data.length, 1);
    assert.equal(records.data[0].reconciliation.status, "source_consistent");
    assert.equal(records.data[0].reconciliation.billing_eligible, false);
    assert(!JSON.stringify(records).includes("PRIVATE-SOURCE-DRIVER"));
    const browser = await chromium.launch({
      headless: true,
      executablePath: process.env.EV_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    });
    try {
      const context = await browser.newContext();
      await context.addCookies(
        [...client.cookies].map(([name, value]) => ({
          name,
          value,
          url: f.base,
        })),
      );
      const page = await context.newPage();
      await page.goto(f.base);
      const operations = page.locator("#roaming-operations");
      await operations
        .getByRole("button", { name: "Charge detail records", exact: true })
        .click();
      await operations
        .getByText("Partner session and CDR agree", { exact: true })
        .waitFor();
      await operations
        .getByText("Source comparison does not authorize billing", {
          exact: true,
        })
        .waitFor();
      assert(
        !(await operations.textContent()).includes("PRIVATE-SOURCE-DRIVER"),
      );
      await page.locator("#logout").click();
      await page.waitForFunction(
        () => document.querySelector("#roaming-operations").hidden,
      );
      assert.equal(await operations.textContent(), "");
    } finally {
      await browser.close();
    }
    // Browser privileges cannot replace the sender's active protocol token.
    const admin = new BusinessClient({ base: f.base });
    admin.csrf = (await admin.request("csrf")).token;
    await admin.request("login", "POST", {
      email: f.admin,
      password: f.password,
    });
    admin.csrf = (await admin.request("csrf")).token;
    await admin.request(
      "ocpi/providers/" + f.provider_id + "/authorization",
      "PATCH",
      { authorized: false },
    );
    await request(path, "GET", undefined, 401);
    console.log(
      "PASS managed OCPI session/CDR reception, exact replay/conflict guards, owned web reconciliation, logout privacy and revocation; no charging or billing authority.",
    );
  },
  { receiving: true },
).catch(() => {
  console.error(
    "Managed OCPI receiver integration failed; private peer details withheld.",
  );
  process.exitCode = 1;
});
