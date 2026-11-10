// Isolated source authorization contract: real local CitrineOS, no hardware.
const assert = require("node:assert/strict");
const { randomBytes, randomUUID } = require("node:crypto");
const { execFileSync } = require("node:child_process");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { SimulatedCharger, graph, poll } = require("./helpers/csms-local.cjs");
(async () => {
  await require("./rider-access-history.cjs")();
  execFileSync("./scripts/rider-auth-setup", { stdio: "pipe" });
  for (const prefix of ["16", "20"]) {
    const run = prefix + randomBytes(3).toString("hex"),
      password = randomBytes(24).toString("hex") + "aA9!";
    const f = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/rider-access.php", run, password],
        { encoding: "utf8" },
      ),
    );
    const login = async (role) => {
      const c = new BusinessClient();
      c.csrf = (await c.request("csrf")).token;
      await c.request("login", "POST", {
        email: `canonical-${role}-${run}@example.test`,
        password,
      });
      c.csrf = (await c.request("csrf")).token;
      return c;
    };
    const owner = await login("owner"),
      customer = await login("customer");
    const card = await customer.request("rider-access/tokens", "POST", {
      label: "Source protocol fixture",
      token_type: prefix === "16" ? "ISO14443" : "Central",
      ...(prefix === "16"
        ? { value: randomBytes(8).toString("hex").toUpperCase() }
        : {}),
      expires_at: new Date(Date.now() + 86400000)
        .toISOString()
        .replace(/\.\d+Z$/, "Z"),
      idempotency_key: randomUUID(),
    });
    const grant = await customer.request(
      `rider-access/tokens/${card.token.id}/grants`,
      "POST",
      { station_id: f.station_id },
    );
    await owner.request(
      `vendor-ops/companies/${f.company_id}/rider-grants/${grant.id}/review`,
      "POST",
      {
        state: "approved",
        expires_at: new Date(Date.now() + 3600000)
          .toISOString()
          .replace(/\.\d+Z$/, "Z"),
        note: "Synthetic source authorization approval",
      },
    );
    await owner.request(
      `vendor-ops/companies/${f.company_id}/rider-tokens/${card.token.id}/provision`,
      "POST",
      {},
    );
    if (prefix === "20")
      await require("./helpers/rider-browser.cjs")({ customer, owner, f, run });
    const charger = new SimulatedCharger(f.protocol, f.identity);
    try {
      await charger.connect();
      await charger.boot();
      await charger.status();
      const auth = () =>
        charger.call(
          "Authorize",
          f.protocol === "1.6"
            ? { idTag: card.value_once }
            : {
                idToken: {
                  idToken: card.value_once,
                  type: card.token.token_type,
                },
              },
        );
      assert.notEqual(
        (
          await (async () => {
            const r = await auth();
            return r.idTagInfo ?? r.idTokenInfo;
          })()
        ).status,
        "Accepted",
        "No booking must not authorize",
      );
      const booking = JSON.parse(
        execFileSync(
          "./scripts/php",
          ["tests/Fixtures/rider-booking.php", run],
          { encoding: "utf8" },
        ),
      );
      const result = await auth();
      assert.equal(
        (result.idTagInfo ?? result.idTokenInfo).status,
        "Accepted",
        `${f.protocol} current approved booking`,
      );
      assert.equal(
        charger.commands.length,
        0,
        "RFID must not synthesize remote-start commands",
      );
      const summary = await customer.request(
        `reservations/${booking.reservation_id}/csms-start`,
        "POST",
      );
      assert.equal(summary.start_command, null);
      const awaiting = (await customer.request("simulations")).data.find(
        (s) => s.id === summary.session.id,
      );
      assert.equal(awaiting.authorization_method, "rider_token");
      assert.equal(awaiting.awaiting_rider_transaction, true);
      assert.equal(awaiting.can_cancel_before_dispatch, false);
      const activityPath = `rider-access/tokens/${card.token.id}/activity`;
      const activity = await customer.request(activityPath);
      assert.equal(
        activity.data.some((e) => e.action === "source.authorization.allowed"),
        true,
      );
      assert.equal(activity.hardware_verified, false);
      const admittedHistory = await customer.request(
        activityPath + "?kind=sessions",
      );
      assert.equal(admittedHistory.data[0].state, "awaiting_source");
      assert.equal(admittedHistory.data[0].source_start_observed, false);
      const operatorActivity = await owner.request(
        `vendor-ops/companies/${f.company_id}/rider-grants/${grant.id}/activity`,
      );
      assert.equal(
        operatorActivity.data.some(
          (e) => e.action === "source.authorization.allowed",
        ),
        true,
      );
      const startAt = new Date().toISOString();
      let transactionId;
      const meter = (value, context, at) => [
        {
          timestamp: at,
          sampledValue: [
            {
              value,
              measurand: "Energy.Active.Import.Register",
              unitOfMeasure: { unit: "Wh" },
              context,
            },
          ],
        },
      ];
      if (f.protocol === "1.6") {
        const started = await charger.call("StartTransaction", {
          connectorId: 1,
          idTag: card.value_once,
          meterStart: 1000,
          timestamp: startAt,
        });
        assert.equal(started.idTagInfo.status, "Accepted");
        transactionId = started.transactionId;
      } else {
        transactionId = randomUUID();
        const started = await charger.call("TransactionEvent", {
          eventType: "Started",
          timestamp: startAt,
          triggerReason: "Authorized",
          seqNo: 0,
          transactionInfo: { transactionId, chargingState: "Charging" },
          evse: { id: 1, connectorId: 1 },
          idToken: { idToken: card.value_once, type: card.token.token_type },
          meterValue: meter(1000, "Transaction.Begin", startAt),
        });
        assert.equal(started.idTokenInfo?.status, "Accepted");
      }
      await poll(async () => {
        const d = await graph(
          "query($tx:String!){Transactions(where:{tenantId:{_eq:1},transactionId:{_eq:$tx}}){id meterStart}}",
          { tx: String(transactionId) },
        );
        return d.Transactions.some((t) => t.meterStart !== null);
      }, "source start");
      execFileSync(
        "./scripts/csms-consume",
        ["--once", `--link=${summary.link.id}`],
        { encoding: "utf8" },
      );
      assert.equal(
        (await customer.request("simulations")).data.find(
          (s) => s.id === summary.session.id,
        ).status,
        "charging",
      );
      await customer.request(`rider-access/tokens/${card.token.id}`, "DELETE");
      const denied = await auth();
      assert.notEqual(
        (denied.idTagInfo ?? denied.idTokenInfo).status,
        "Accepted",
        "Revoked token must deny subsequent authorization",
      );
      const endAt = new Date().toISOString();
      if (f.protocol === "1.6")
        await charger.call("StopTransaction", {
          transactionId,
          meterStop: 3500,
          transactionData: [
            {
              timestamp: endAt,
              sampledValue: [
                {
                  value: "3500",
                  measurand: "Energy.Active.Import.Register",
                  unit: "Wh",
                  context: "Transaction.End",
                },
              ],
            },
          ],
          timestamp: endAt,
          reason: "Local",
        });
      else
        await charger.call("TransactionEvent", {
          eventType: "Ended",
          timestamp: endAt,
          triggerReason: "StopAuthorized",
          seqNo: 1,
          transactionInfo: {
            transactionId,
            chargingState: "Idle",
            stoppedReason: "Local",
          },
          evse: { id: 1, connectorId: 1 },
          meterValue: meter(3500, "Transaction.End", endAt),
        });
      await poll(async () => {
        const d = await graph(
          "query($tx:String!){Transactions(where:{tenantId:{_eq:1},transactionId:{_eq:$tx}}){endTime}}",
          { tx: String(transactionId) },
        );
        return d.Transactions.some((t) => t.endTime);
      }, "source end");
      execFileSync(
        "./scripts/csms-consume",
        ["--once", `--link=${summary.link.id}`],
        { encoding: "utf8" },
      );
      const finished = (await customer.request("simulations")).data.find(
        (s) => s.id === summary.session.id,
      );
      assert.equal(finished.status, "stopped");
      assert.equal(finished.awaiting_rider_transaction, false);
      const endedHistory = await customer.request(
        activityPath + "?kind=sessions",
      );
      assert.equal(endedHistory.data[0].state, "source_ended");
      assert.equal(endedHistory.data[0].source_end_observed, true);
      assert.equal(endedHistory.hardware_verified, false);
      assert.equal(Number(finished.energy_wh), 2500);
      const invoices = await customer.request("finance/invoices");
      assert.equal(
        invoices.data.filter(
          (i) => i.charging_session_id === summary.session.id,
        ).length,
        1,
      );
      console.log(
        `${f.protocol}: source provisioning, no-booking denial, reserved authorization, metered transaction, post-revocation finalization and invoice passed (simulator only)`,
      );
    } finally {
      await charger.close();
    }
  }
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
