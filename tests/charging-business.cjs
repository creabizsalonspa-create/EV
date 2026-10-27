const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
const fs = require("node:fs");
const { randomUUID, createHash, createHmac } = require("node:crypto");
const { promisify } = require("node:util");
const { execFile: execFileCallback, spawn } = require("node:child_process");
const execFile = promisify(execFileCallback);
const { graph, poll, SimulatedCharger } = require("./helpers/csms-local.cjs");
const values = Object.fromEntries(
  fs
    .readFileSync("services/csms/charging/.env", "utf8")
    .split("\n")
    .filter((x) => x.includes("=") && !x.startsWith("#"))
    .map((x) => [x.slice(0, x.indexOf("=")), x.slice(x.indexOf("=") + 1)]),
);
const eventSecret = values.CSMS_EVENT_SECRET?.replace(/^['"]|['"]$/g, "");
assert(eventSecret?.length >= 32, "Run scripts/charging-bridge-setup first.");
const base = "http://127.0.0.1:8000";
const useConsumer = process.env.EV_CSMS_CONSUMER === "1";
const useDaemon = useConsumer && process.env.EV_CSMS_DAEMON === "1";
function startWorker(link) {
  const child = spawn(
    "./scripts/csms-consume",
    ["--interval=1", "--link=" + link.id],
    { stdio: ["ignore", "pipe", "pipe"] },
  );
  child.stdout.on("data", () => {});
  child.stderr.on("data", () => {});
  return { child, done: new Promise((resolve) => child.once("exit", resolve)) };
}
async function stopWorker(worker) {
  if (!worker) return;
  worker.child.kill("SIGTERM");
  await worker.done;
}
async function consume(link) {
  await execFile("./scripts/csms-consume", ["--once", "--link=" + link.id]);
}
async function latestSession(client, id) {
  await new Promise((resolve) => setTimeout(resolve, 250));
  return (await client.request("simulations")).data.find((s) => s.id === id);
}
async function signedEvent(payload, expectedStatus = 200) {
  const raw = JSON.stringify(payload),
    timestamp = String(Math.floor(Date.now() / 1000));
  const signature = createHmac("sha256", eventSecret)
    .update(timestamp + "." + raw)
    .digest("hex");
  const r = await fetch(base + "/api/internal/csms/events", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-EV-Timestamp": timestamp,
      "X-EV-Signature": signature,
    },
    body: raw,
    signal: AbortSignal.timeout(30000),
  });
  const json = await r.json();
  assert.equal(
    r.status,
    expectedStatus,
    "Signed event rejected: " + (json.message || r.status),
  );
  return json;
}
const canonicalTargets = process.env.EV_CANONICAL_TARGETS === "1";
function event(session, station, version, tx, type, sequence, meter, at) {
  return {
    event_id:
      "csms-" +
      createHash("sha256")
        .update([version, station, tx, type, sequence].join("|"))
        .digest("hex"),
    session_id: session,
    identifier: station,
    protocol: version,
    transaction_id: String(tx),
    type,
    sequence,
    meter_wh: meter,
    occurred_at: at,
    ...(canonicalTargets
      ? {
          source_evse_number: version === "1.6" ? 0 : 5,
          source_connector_number: 7,
        }
      : {}),
  };
}
async function sourceTransaction(station, tx) {
  const d = await graph(
    "query($name:String!,$tx:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}}){id} Transactions(where:{tenantId:{_eq:1},transactionId:{_eq:$tx}},order_by:{id:desc}){id transactionId stationId meterStart isActive totalKwh startTime endTime}}",
    { name: station, tx: String(tx) },
  );
  return d.Transactions.find((t) => t.stationId === d.ChargingStations[0]?.id);
}
async function session(version, actors) {
  const { customer, vendor, admin } = actors;
  const name =
    "ev-test-business-" +
    version.replaceAll(".", "") +
    "-" +
    randomUUID().slice(0, 8);
  const station = await vendor.request("stations", "POST", {
    name: "Business protocol " + name,
    country: "AE",
    currency: "AED",
    timezone: "Asia/Dubai",
    latitude: 25,
    longitude: 55,
    source: "simulator",
    connector_type: "CCS2",
    power_kw: 50,
  });
  await admin.request("stations/" + station.id + "/csms-binding", "POST", {
    identifier: name,
    protocol: version,
  });
  await admin.request("stations/" + station.id + "/tariffs", "POST", {
    rate_minor_per_kwh: 125,
    tax_bps: 500,
    commission_bps: 1000,
  });
  const fixture = JSON.parse(
    (
      await execFile("./scripts/php", [
        ...(canonicalTargets
          ? [
              "tests/Fixtures/protocol-canonical.php",
              String(station.id),
              String(customer.user.id),
            ]
          : [
              "artisan",
              "platform:charging-fixture",
              String(customer.user.id),
              String(station.id),
            ]),
      ])
    ).stdout.trim(),
  );
  assert.equal(fixture.fixture_only, true);
  const pending = await customer.request(
    "reservations/" + fixture.reservation_id + "/csms-start",
    "POST",
  );
  assert.equal(pending.session.status, "pending_start");
  assert.equal(pending.evidence, "SIMULATED_CSMS");
  const sessionId = pending.session.id,
    tag = "EV-SIM-" + sessionId;
  const repeatedStart = await customer.request(
    "reservations/" + fixture.reservation_id + "/csms-start",
    "POST",
  );
  assert.equal(repeatedStart.session.id, sessionId);
  assert.equal(repeatedStart.start_command.id, pending.start_command.id);
  const at = new Date().toISOString();
  await graph(
    "mutation($object:Authorizations_insert_input!){insert_Authorizations_one(object:$object){id}}",
    {
      object: {
        tenantId: 1,
        idToken: tag,
        idTokenType: "Central",
        status: "Accepted",
        concurrentTransaction: false,
        isPrepaid: false,
        cacheExpiryDateTime: new Date(Date.now() + 86400000).toISOString(),
        createdAt: at,
        updatedAt: at,
      },
    },
  );
  const charger = new SimulatedCharger(version, name);
  let transactionId,
    worker = useDaemon ? startWorker(pending.link) : null;
  try {
    await charger.connect();
    await charger.boot();
    await charger.status(
      "Available",
      canonicalTargets ? 7 : 1,
      canonicalTargets ? 5 : 1,
    );
    const auth = await charger.call(
      "Authorize",
      version === "1.6"
        ? { idTag: tag }
        : { idToken: { idToken: tag, type: "Central" } },
    );
    assert.equal((auth.idTagInfo ?? auth.idTokenInfo).status, "Accepted");
    await execFile("./scripts/csms-dispatch", [
      String(pending.start_command.id),
    ]);
    const remote = await poll(
      async () =>
        charger.commands.find(
          (c) =>
            c.action ===
            (version === "1.6"
              ? "RemoteStartTransaction"
              : "RequestStartTransaction"),
        ),
      "business remote start delivery",
    );
    assert.equal(
      version === "1.6" ? remote.payload.idTag : remote.payload.idToken.idToken,
      tag,
    );
    assert.equal(
      version === "1.6" ? remote.payload.connectorId : remote.payload.evseId,
      canonicalTargets ? (version === "1.6" ? 7 : 5) : 1,
    );
    const startedAt = new Date().toISOString();
    if (version === "1.6") {
      const response = await charger.call("StartTransaction", {
        connectorId: canonicalTargets ? 7 : 1,
        idTag: tag,
        meterStart: 1000,
        timestamp: startedAt,
      });
      assert.equal(response.idTagInfo.status, "Accepted");
      transactionId = String(response.transactionId);
    } else {
      transactionId = randomUUID();
      const response = await charger.call("TransactionEvent", {
        eventType: "Started",
        timestamp: startedAt,
        triggerReason: "RemoteStart",
        seqNo: 0,
        transactionInfo: {
          transactionId,
          chargingState: "Charging",
          remoteStartId: pending.start_command.id,
        },
        evse: {
          id: canonicalTargets ? 5 : 1,
          connectorId: canonicalTargets ? 7 : 1,
        },
        idToken: { idToken: tag, type: "Central" },
        meterValue: [
          {
            timestamp: startedAt,
            sampledValue: [
              {
                value: 1000,
                measurand: "Energy.Active.Import.Register",
                unitOfMeasure: { unit: "Wh" },
                context: "Transaction.Begin",
              },
            ],
          },
        ],
      });
      assert.equal(response.idTokenInfo.status, "Accepted");
    }
    const tx = await poll(async () => {
      const t = await sourceTransaction(name, transactionId);
      return t && Number(t.meterStart) === 1 ? t : null;
    }, "authoritative transaction start");
    const first = event(
      sessionId,
      name,
      version,
      transactionId,
      "started",
      0,
      Number(tx.meterStart) * 1000,
      tx.startTime,
    );
    const invalid = await fetch(base + "/api/internal/csms/events", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify(first),
    });
    assert.equal(invalid.status, 401);
    if (useDaemon)
      await poll(
        async () =>
          (await latestSession(customer, sessionId))?.status === "charging",
        "continuous source start",
        20000,
      );
    else if (useConsumer) await consume(pending.link);
    assert.equal(
      (await signedEvent(first)).outcome,
      useConsumer ? "duplicate" : "applied",
    );
    await signedEvent({ ...first, meter_wh: first.meter_wh + 1 }, 409);
    const periodicAt = new Date().toISOString();
    if (version === "1.6")
      await charger.call("MeterValues", {
        connectorId: canonicalTargets ? 7 : 1,
        transactionId: Number(transactionId),
        meterValue: [
          {
            timestamp: periodicAt,
            sampledValue: [
              {
                value: "2500",
                measurand: "Energy.Active.Import.Register",
                unit: "Wh",
                context: "Sample.Periodic",
              },
            ],
          },
        ],
      });
    else
      await charger.call("TransactionEvent", {
        eventType: "Updated",
        timestamp: periodicAt,
        triggerReason: "MeterValuePeriodic",
        seqNo: 1,
        transactionInfo: { transactionId, chargingState: "Charging" },
        evse: {
          id: canonicalTargets ? 5 : 1,
          connectorId: canonicalTargets ? 7 : 1,
        },
        meterValue: [
          {
            timestamp: periodicAt,
            sampledValue: [
              {
                value: 2500,
                measurand: "Energy.Active.Import.Register",
                unitOfMeasure: { unit: "Wh" },
                context: "Sample.Periodic",
              },
            ],
          },
        ],
      });
    const middle = await poll(async () => {
      const d = await graph(
        "query($id:Int!){MeterValues(where:{tenantId:{_eq:1},transactionDatabaseId:{_eq:$id}},order_by:{id:desc}){timestamp sampledValue}}",
        { id: tx.id },
      );
      return d.MeterValues.find((m) =>
        m.sampledValue.some(
          (s) => s.context === "Sample.Periodic" && Number(s.value) === 2500,
        ),
      );
    }, "persisted periodic meter");
    const reading = middle.sampledValue.find(
      (s) => s.measurand === "Energy.Active.Import.Register",
    );
    assert.equal(reading.unitOfMeasure.unit, "Wh");
    const meterEvent = event(
      sessionId,
      name,
      version,
      transactionId,
      "meter",
      1,
      Number(reading.value),
      middle.timestamp,
    );
    if (useConsumer) {
      if (useDaemon) {
        await poll(
          async () =>
            (await latestSession(customer, sessionId))?.energy_wh === 1500,
          "continuous source meter",
          20000,
        );
        await stopWorker(worker);
        worker = startWorker(pending.link); // Actual daemon restart keeps its durable cursor.
      } else {
        await consume(pending.link);
        await consume(pending.link);
      }
      assert.equal((await latestSession(customer, sessionId)).energy_wh, 1500);
    } else {
      const applied = await signedEvent(meterEvent);
      assert.equal(applied.energy_wh, 1500);
      assert.equal((await signedEvent(meterEvent)).outcome, "duplicate");
      await signedEvent({ ...meterEvent, meter_wh: 2600 }, 409);
    }
    const stop = await customer.request(
      "charging-sessions/" + sessionId + "/csms-stop",
      "POST",
    );
    assert.equal(stop.session.status, "stopping");
    assert(stop.stop_command?.id);
    const repeatedStop = await customer.request(
      "charging-sessions/" + sessionId + "/csms-stop",
      "POST",
    );
    assert.equal(repeatedStop.stop_command.id, stop.stop_command.id);
    await execFile("./scripts/csms-dispatch", [String(stop.stop_command.id)]);
    await poll(
      async () =>
        charger.commands.some(
          (c) =>
            c.action ===
            (version === "1.6"
              ? "RemoteStopTransaction"
              : "RequestStopTransaction"),
        ),
      "business remote stop delivery",
    );
    const endedAt = new Date().toISOString();
    if (version === "1.6")
      await charger.call("StopTransaction", {
        transactionId: Number(transactionId),
        idTag: tag,
        meterStop: 3500,
        timestamp: endedAt,
        reason: "Remote",
        transactionData: [
          {
            timestamp: endedAt,
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
      });
    else
      await charger.call("TransactionEvent", {
        eventType: "Ended",
        timestamp: endedAt,
        triggerReason: "RemoteStop",
        seqNo: 2,
        transactionInfo: {
          transactionId,
          chargingState: "Idle",
          stoppedReason: "Remote",
        },
        evse: {
          id: canonicalTargets ? 5 : 1,
          connectorId: canonicalTargets ? 7 : 1,
        },
        meterValue: [
          {
            timestamp: endedAt,
            sampledValue: [
              {
                value: 3500,
                measurand: "Energy.Active.Import.Register",
                unitOfMeasure: { unit: "Wh" },
                context: "Transaction.End",
              },
            ],
          },
        ],
      });
    const final = await poll(async () => {
      const t = await sourceTransaction(name, transactionId);
      return t && !t.isActive && t.endTime && Number(t.totalKwh) === 2.5
        ? t
        : null;
    }, "reconciled CSMS transaction");
    const endMeters = await poll(async () => {
      const d = await graph(
        "query($id:Int!){MeterValues(where:{tenantId:{_eq:1},transactionDatabaseId:{_eq:$id}},order_by:{id:desc}){timestamp sampledValue}}",
        { id: tx.id },
      );
      return d.MeterValues.find((m) =>
        m.sampledValue.some(
          (s) => s.context === "Transaction.End" && Number(s.value) === 3500,
        ),
      );
    }, "persisted transaction-end meter");
    const endReading = endMeters.sampledValue.find(
      (s) => s.measurand === "Energy.Active.Import.Register",
    );
    const ending = event(
      sessionId,
      name,
      version,
      transactionId,
      "ended",
      2,
      Number(endReading.value),
      final.endTime,
    );
    if (useConsumer) {
      if (useDaemon)
        await poll(
          async () =>
            (await latestSession(customer, sessionId))?.status === "stopped",
          "continuous source end",
          20000,
        );
      else {
        await consume(pending.link);
        await consume(pending.link);
      }
      const result = await latestSession(customer, sessionId);
      assert.equal(result.status, "stopped");
      assert.equal(result.energy_wh, 2500);
    } else {
      const ended = await signedEvent(ending);
      assert.equal(ended.status, "stopped");
      assert.equal(ended.energy_wh, 2500);
      assert.equal((await signedEvent(ending)).outcome, "duplicate");
    }
    const invoices = await customer.request("finance/invoices");
    const matches = invoices.data.filter(
      (i) => i.charging_session_id === sessionId,
    );
    assert.equal(matches.length, 1);
    const summary = await customer.request("finance/invoices/" + matches[0].id);
    assert.equal(summary.invoice.subtotal_minor, 313);
    assert.equal(summary.invoice.tax_minor, 16);
    assert.equal(summary.invoice.total_minor, 329);
    assert.equal(summary.invoice.energy_wh, 2500);
    assert.equal(summary.ledger_balanced, true);
    assert.equal(summary.real_payment_processed, false);
    const paymentKey = randomUUID(),
      refundKey = randomUUID();
    const payment = await customer.request(
      "finance/invoices/" + matches[0].id + "/simulate-payment",
      "POST",
      { amount_minor: 329, idempotency_key: paymentKey },
    );
    const paymentReplay = await customer.request(
      "finance/invoices/" + matches[0].id + "/simulate-payment",
      "POST",
      { amount_minor: 329, idempotency_key: paymentKey },
    );
    assert.equal(payment.id, paymentReplay.id);
    const paid = await customer.request("finance/invoices/" + matches[0].id);
    assert.equal(paid.state, "paid");
    assert.equal(paid.paid_minor, 329);
    assert.equal(paid.amount_due_minor, 0);
    assert.equal(paid.ledger_balanced, true);
    const refund = await vendor.request(
      "finance/invoices/" + matches[0].id + "/simulate-refund",
      "POST",
      {
        amount_minor: 329,
        idempotency_key: refundKey,
        reason: "Local protocol QA full simulated refund",
      },
    );
    const refundReplay = await vendor.request(
      "finance/invoices/" + matches[0].id + "/simulate-refund",
      "POST",
      {
        amount_minor: 329,
        idempotency_key: refundKey,
        reason: "Local protocol QA full simulated refund",
      },
    );
    assert.equal(refund.id, refundReplay.id);
    const credited = await customer.request(
      "finance/invoices/" + matches[0].id,
    );
    assert.equal(credited.state, "refunded");
    assert.equal(credited.net_collected_minor, 0);
    assert.equal(credited.amount_due_minor, 0);
    assert.equal(credited.ledger_balanced, true);
    assert.equal(credited.payments.length, 1);
    assert.equal(credited.refunds.length, 1);
    assert.equal(credited.real_payment_processed, false);
    const vendorSessions = await vendor.request("simulations");
    const adminSessions = await admin.request("simulations");
    for (const results of [vendorSessions, adminSessions]) {
      const row = results.data.find((s) => s.id === sessionId);
      assert(row);
      assert.equal(row.status, "stopped");
      assert.equal(row.energy_wh, 2500);
      assert.equal(row.total_minor, 313);
    }
    console.log(
      JSON.stringify({
        evidence: "SIMULATED_CSMS_BUSINESS",
        canonical_targets: canonicalTargets,
        continuous_consumer: useConsumer,
        daemon_restart_verified: useDaemon,
        protocol: version,
        station: name,
        session_id: sessionId,
        source_transaction_id: transactionId,
        current_slot_reservation: "operator_test_fixture",
        authenticated_event_inbox: true,
        source: "CitrineOS GraphQL transaction and persisted meters",
        replay_deduplicated: true,
        changed_replay_rejected: true,
        energy_wh: 2500,
        subtotal_minor: 313,
        tax_minor: 16,
        invoice_total_minor: 329,
        currency: "AED",
        one_invoice: true,
        simulated_payment_and_refund_reconciled: true,
        payment_and_refund_replays_deduplicated: true,
        ledger_balanced: true,
        customer_vendor_admin_consistent: true,
        real_payment_processed: false,
      }),
    );
  } finally {
    await stopWorker(worker);
    await charger.close();
  }
}
(async () => {
  const customer = await new BusinessClient().register("customer"),
    vendor = await new BusinessClient().register("vendor"),
    admin = await new BusinessClient().register("admin");
  await session("1.6", { customer, vendor, admin });
  await session("2.0.1", { customer, vendor, admin });
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
