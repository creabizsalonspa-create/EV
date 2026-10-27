const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
const {
  graph,
  coreToken,
  coreCommand,
  poll,
  SimulatedCharger,
} = require("./helpers/csms-local.cjs");
async function session(version) {
  const station =
      "ev-test-session-" +
      version.replaceAll(".", "") +
      "-" +
      randomUUID().slice(0, 8),
    tag = "QA-" + randomUUID().slice(0, 12);
  const charger = new SimulatedCharger(version, station);
  let transactionId;
  const now = new Date().toISOString();
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
        createdAt: now,
        updatedAt: now,
      },
    },
  );
  const token = await coreToken();
  try {
    await charger.connect().catch((e) => {
      throw new Error(version + " initial connect: " + e.message);
    });
    await charger.boot();
    await charger.status();
    const stationRecord = await graph(
      "query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}}){id}}",
      { name: station },
    );
    const internalStationId = stationRecord.ChargingStations[0].id;
    const auth = await charger.call(
      "Authorize",
      version === "1.6"
        ? { idTag: tag }
        : { idToken: { idToken: tag, type: "Central" } },
    );
    assert.equal((auth.idTagInfo ?? auth.idTokenInfo).status, "Accepted");
    await coreCommand(
      token,
      version,
      station,
      version === "1.6" ? "remoteStartTransaction" : "requestStartTransaction",
      version === "1.6"
        ? { idTag: tag, connectorId: 1 }
        : {
            idToken: { idToken: tag, type: "Central" },
            remoteStartId: 1,
            evseId: 1,
          },
    );
    await poll(
      async () =>
        charger.commands.some(
          (c) =>
            c.action ===
            (version === "1.6"
              ? "RemoteStartTransaction"
              : "RequestStartTransaction"),
        ),
      "remote start receipt",
    );
    const startAt = new Date().toISOString();
    if (version === "1.6") {
      const start = await charger.call("StartTransaction", {
        connectorId: 1,
        idTag: tag,
        meterStart: 1000,
        timestamp: startAt,
      });
      assert.equal(start.idTagInfo.status, "Accepted");
      assert(start.transactionId > 0);
      transactionId = String(start.transactionId);
      await charger.status("Charging");
      await charger.call("MeterValues", {
        connectorId: 1,
        transactionId: Number(transactionId),
        meterValue: [
          {
            timestamp: new Date().toISOString(),
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
    } else {
      transactionId = randomUUID();
      const event = {
        eventType: "Started",
        timestamp: startAt,
        triggerReason: "RemoteStart",
        seqNo: 0,
        transactionInfo: {
          transactionId,
          chargingState: "Charging",
          remoteStartId: 1,
        },
        evse: { id: 1, connectorId: 1 },
        idToken: { idToken: tag, type: "Central" },
        meterValue: [
          {
            timestamp: startAt,
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
      };
      const started = await charger.call("TransactionEvent", event);
      assert.equal(started.idTokenInfo?.status, "Accepted");
      await charger.status("Occupied");
      const updateAt = new Date().toISOString();
      const update = {
        eventType: "Updated",
        timestamp: updateAt,
        triggerReason: "MeterValuePeriodic",
        seqNo: 1,
        transactionInfo: { transactionId, chargingState: "Charging" },
        evse: { id: 1, connectorId: 1 },
        meterValue: [
          {
            timestamp: updateAt,
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
      };
      await charger.call("TransactionEvent", update);
      // Replay an identical sequence event: the authoritative transaction must not double-count energy.
      await charger.call("TransactionEvent", update);
    }
    await charger.close();
    await poll(async () => {
      const d = await graph(
        "query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}}){isOnline}}",
        { name: station },
      );
      return (
        d.ChargingStations.length === 1 &&
        d.ChargingStations[0].isOnline === false
      );
    }, "disconnected station state");
    await charger.connect().catch((e) => {
      throw new Error(version + " reconnect: " + e.message);
    });
    await charger.boot();
    const heartbeat = await charger.call("Heartbeat", {});
    assert(Number.isFinite(Date.parse(heartbeat.currentTime)));
    await coreCommand(
      token,
      version,
      station,
      version === "1.6" ? "remoteStopTransaction" : "requestStopTransaction",
      {
        transactionId:
          version === "1.6" ? Number(transactionId) : transactionId,
      },
    );
    await poll(
      async () =>
        charger.commands.some(
          (c) =>
            c.action ===
            (version === "1.6"
              ? "RemoteStopTransaction"
              : "RequestStopTransaction"),
        ),
      "remote stop receipt",
    );
    const endAt = new Date().toISOString();
    if (version === "1.6")
      await charger.call("StopTransaction", {
        transactionId: Number(transactionId),
        idTag: tag,
        meterStop: 3500,
        timestamp: endAt,
        reason: "Remote",
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
      });
    else
      await charger.call("TransactionEvent", {
        eventType: "Ended",
        timestamp: endAt,
        triggerReason: "RemoteStop",
        seqNo: 2,
        transactionInfo: {
          transactionId,
          chargingState: "Idle",
          stoppedReason: "Remote",
        },
        evse: { id: 1, connectorId: 1 },
        meterValue: [
          {
            timestamp: endAt,
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
    const tx = await poll(async () => {
      const d = await graph(
        "query($id:String!,$station:Int!){Transactions(where:{tenantId:{_eq:1},transactionId:{_eq:$id},stationId:{_eq:$station}}){id transactionId isActive totalKwh startTime endTime stationId}}",
        { id: transactionId, station: internalStationId },
      );
      const t = d.Transactions[0];
      return t && !t.isActive && t.endTime && Number(t.totalKwh) === 2.5
        ? t
        : null;
    }, "closed transaction with reconciled final energy");
    assert.equal(
      Number(tx.totalKwh),
      2.5,
      "Cumulative meter delta must reconcile to 2.5 kWh",
    );
    await charger.status("Faulted");
    await charger.status("Available");
    await poll(async () => {
      const d = await graph(
        "query($station:Int!){StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:20){connectorStatus}}",
        { station: internalStationId },
      );
      return (
        d.StatusNotifications[0]?.connectorStatus === "Available" &&
        d.StatusNotifications.some((s) => s.connectorStatus === "Faulted")
      );
    }, "persisted fault and recovery history");
    const meters = await graph(
      "query($id:Int!){MeterValues(where:{tenantId:{_eq:1},transactionDatabaseId:{_eq:$id}}){id timestamp sampledValue}}",
      { id: tx.id },
    );
    assert(
      meters.MeterValues.length >= 2,
      "Persisted protocol meter evidence required",
    );
    const periodic = meters.MeterValues.filter((m) =>
      (Array.isArray(m.sampledValue) ? m.sampledValue : []).some(
        (s) => s.context === "Sample.Periodic" && Number(s.value) === 2500,
      ),
    );
    console.log(
      JSON.stringify({
        evidence: "SIMULATED_PROTOCOL_SESSION",
        protocol: version,
        station,
        transaction_id: transactionId,
        authorization: "Accepted",
        remote_start_received: true,
        remote_stop_received: true,
        reconnected: true,
        fault_and_recovery_acknowledged: true,
        active: tx.isActive,
        energy_kwh: Number(tx.totalKwh),
        persisted_meter_records: meters.MeterValues.length,
        duplicate_sequence_energy_reconciled: version === "2.0.1",
        duplicate_periodic_meter_rows_observed: Math.max(
          0,
          periodic.length - 1,
        ),
        fault_and_recovery_persisted: true,
      }),
    );
  } finally {
    await charger.close();
  }
}
(async () => {
  await session("1.6");
  await session("2.0.1");
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
