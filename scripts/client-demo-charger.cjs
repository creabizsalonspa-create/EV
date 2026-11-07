#!/usr/bin/env node
// Persistent local device simulator. Source events go through CitrineOS, never Laravel billing writes.
const fs = require("node:fs");
const path = require("node:path");
const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
const root = path.resolve(__dirname, "..");
process.chdir(root);
const { SimulatedCharger, graph } = require("../tests/helpers/csms-local.cjs");
const {
  operatingSettings,
  HEARTBEAT_SECONDS,
  METER_SECONDS,
} = require("../tests/helpers/operating-settings.cjs");
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
function store(file, value) {
  const tmp = file + "." + process.pid + ".tmp";
  fs.writeFileSync(tmp, JSON.stringify(value, null, 2) + "\n", { mode: 0o600 });
  fs.renameSync(tmp, file);
}
async function main() {
  assert(
    Number(process.versions.node.split(".")[0]) >= 24,
    "Node24 or later is required.",
  );
  const manifest = fs.realpathSync(process.argv[2] || "");
  assert(manifest.startsWith(path.join(root, "artifacts/client-demo/")));
  assert(
    (fs.statSync(manifest).mode & 0o077) === 0,
    "Private manifest permissions required.",
  );
  const f = JSON.parse(fs.readFileSync(manifest, "utf8"));
  assert(f.fixture_only && !f.real_money && f.base === "http://127.0.0.1:8000");
  const station = f.stations.find((s) => s.protocol === process.argv[3]);
  assert(
    station && /^ev-test-demo-[a-f0-9]{8}-(16|201)$/.test(station.identity),
  );
  const version = station.protocol,
    dir = path.dirname(manifest),
    suffix = version.replaceAll(".", "");
  const journalFile = path.join(dir, "device-" + suffix + ".json"),
    statusFile = path.join(dir, "status-" + suffix + ".json"),
    swipeFile = path.join(dir, "swipe-" + suffix + ".json");
  let journal = fs.existsSync(journalFile)
    ? JSON.parse(fs.readFileSync(journalFile, "utf8"))
    : { phase: "idle", meter_wh: 1000 };
  if (journal.phase !== "idle") {
    store(statusFile, {
      state: "requires_operator_review",
      protocol: version,
      commands_replayed: false,
    });
    throw Error("Review required");
  }
  assert(Number.isSafeInteger(journal.meter_wh) && journal.meter_wh >= 0);
  let stopping = false,
    active = null,
    charger = new SimulatedCharger(version, station.identity),
    lastBeat = 0;
  charger.configurationHandler = operatingSettings(version);
  for (const signal of ["SIGINT", "SIGTERM"])
    process.once(signal, () => {
      stopping = true;
    });
  function mark(phase, extra = {}) {
    journal = { ...journal, ...extra, phase };
    store(journalFile, journal);
  }
  function status(state, event = null) {
    store(statusFile, {
      state,
      event,
      pid: process.pid,
      protocol: version,
      station_id: station.station_id,
      evidence: "SIMULATED_CITRINEOS",
      physical_charging_verified: false,
      observed_at: new Date().toISOString(),
    });
  }
  async function owned(tag, type, issuer) {
    const data = await graph(
      "query($tag:citext!){Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id idTokenType additionalInfo realTimeAuth concurrentTransaction isPrepaid}}",
      { tag },
    );
    const rows = data.Authorizations;
    return (
      rows.length === 1 &&
      rows[0].idTokenType === type &&
      rows[0].realTimeAuth === "Never" &&
      rows[0].concurrentTransaction === false &&
      rows[0].isPrepaid === false &&
      rows[0].additionalInfo?.[0]?.type === issuer
    );
  }
  function sample(context) {
    return [
      {
        timestamp: new Date().toISOString(),
        sampledValue: [
          version === "1.6"
            ? {
                value: String(journal.meter_wh),
                measurand: "Energy.Active.Import.Register",
                unit: "Wh",
                context,
              }
            : {
                value: journal.meter_wh,
                measurand: "Energy.Active.Import.Register",
                unitOfMeasure: { unit: "Wh" },
                context,
              },
        ],
      },
    ];
  }
  async function begin(tag, type, remoteId = null) {
    assert(!active && journal.phase === "idle");
    assert(
      typeof tag === "string" &&
        tag.length > 0 &&
        tag.length <= (version === "1.6" ? 20 : 36),
    );
    const managed = await owned(
      tag,
      type,
      remoteId === null ? "CREABIZ_RIDER" : "CREABIZ_REMOTE",
    );
    if (!managed && remoteId === null) {
      status("idle", "authorization_denied");
      return;
    }
    assert(managed, "Managed online authority required.");
    mark("authorizing");
    status("authorizing");
    const auth = await charger.call(
      "Authorize",
      version === "1.6" ? { idTag: tag } : { idToken: { idToken: tag, type } },
    );
    if ((auth.idTagInfo ?? auth.idTokenInfo)?.status !== "Accepted") {
      mark("idle");
      status("idle", "authorization_denied");
      return;
    }
    mark("starting");
    let transactionId;
    if (version === "1.6") {
      const reply = await charger.call("StartTransaction", {
        connectorId: 1,
        idTag: tag,
        meterStart: journal.meter_wh,
        timestamp: new Date().toISOString(),
      });
      assert(reply.idTagInfo?.status === "Accepted");
      transactionId = String(reply.transactionId);
    } else {
      transactionId = randomUUID();
      const info = {
        transactionId,
        chargingState: "Charging",
        ...(remoteId === null ? {} : { remoteStartId: remoteId }),
      };
      const reply = await charger.call("TransactionEvent", {
        eventType: "Started",
        timestamp: new Date().toISOString(),
        triggerReason: remoteId === null ? "Authorized" : "RemoteStart",
        seqNo: 0,
        transactionInfo: info,
        evse: { id: 1, connectorId: 1 },
        idToken: { idToken: tag, type },
        meterValue: sample("Transaction.Begin"),
      });
      assert(reply.idTokenInfo?.status === "Accepted");
    }
    active = { transactionId, tag, type, seq: 0, lastSample: Date.now() };
    mark("charging", { transaction_id: transactionId });
    await charger.status(version === "1.6" ? "Charging" : "Occupied");
    status("charging");
  }
  async function meter(final = false) {
    const wh = Math.floor((Date.now() - active.lastSample) / 1000) * 5;
    if (wh > 0) {
      journal.meter_wh += wh;
      active.lastSample +=
        Math.floor((Date.now() - active.lastSample) / 1000) * 1000;
    }
    // Persist before every transaction write. A crash/timeout must never replay it.
    mark(final ? "stopping" : "charging");
    if (final && version === "1.6")
      await charger.call("StopTransaction", {
        transactionId: Number(active.transactionId),
        idTag: active.tag,
        meterStop: journal.meter_wh,
        timestamp: new Date().toISOString(),
        reason: "Remote",
        transactionData: sample("Transaction.End"),
      });
    else if (version === "1.6")
      await charger.call("MeterValues", {
        connectorId: 1,
        transactionId: Number(active.transactionId),
        meterValue: sample("Sample.Periodic"),
      });
    else
      await charger.call("TransactionEvent", {
        eventType: final ? "Ended" : "Updated",
        timestamp: new Date().toISOString(),
        triggerReason: final ? "RemoteStop" : "MeterValuePeriodic",
        seqNo: ++active.seq,
        transactionInfo: {
          transactionId: active.transactionId,
          chargingState: final ? "Idle" : "Charging",
          ...(final ? { stoppedReason: "Remote" } : {}),
        },
        evse: { id: 1, connectorId: 1 },
        meterValue: sample(final ? "Transaction.End" : "Sample.Periodic"),
      });
    if (final) {
      active = null;
      mark("idle");
      await charger.status("Available");
      status("idle");
    } else status("charging");
  }
  try {
    while (!stopping) {
      if (charger.socket?.readyState !== 1) {
        assert(
          !active && journal.phase === "idle",
          "An interrupted transaction requires review.",
        );
        status("connecting");
        try {
          await charger.connect();
          await charger.boot();
          await charger.status("Available");
          status("idle");
          lastBeat = Date.now();
        } catch {
          await charger.close();
          await sleep(2000);
          continue;
        }
      }
      const command = charger.commands.shift();
      if (
        command &&
        ["RemoteStartTransaction", "RequestStartTransaction"].includes(
          command.action,
        )
      ) {
        assert(
          !active &&
            (version === "1.6"
              ? command.payload.connectorId === 1
              : command.payload.evseId === 1),
        );
        const tag =
          version === "1.6"
            ? command.payload.idTag
            : command.payload.idToken?.idToken;
        assert(/^EV-[A-F0-9]{16}$/.test(tag));
        await begin(
          tag,
          "Central",
          version === "1.6" ? 0 : command.payload.remoteStartId,
        );
      } else if (
        command &&
        ["RemoteStopTransaction", "RequestStopTransaction"].includes(
          command.action,
        )
      ) {
        assert(
          active &&
            String(command.payload.transactionId) === active.transactionId,
        );
        await meter(true);
      }
      if (fs.existsSync(swipeFile)) {
        assert((fs.statSync(swipeFile).mode & 0o077) === 0);
        const swipe = JSON.parse(fs.readFileSync(swipeFile, "utf8"));
        fs.unlinkSync(swipeFile); // one-shot control, never auto-repeat a swipe
        assert(["ISO14443", "ISO15693", "Central"].includes(swipe.type));
        if (
          !active &&
          Number.isSafeInteger(swipe.queued_at) &&
          Date.now() - swipe.queued_at >= 0 &&
          Date.now() - swipe.queued_at <= 10000
        )
          await begin(swipe.token, swipe.type);
        else status(active ? "charging" : "idle", "swipe_expired_or_busy");
      }
      if (active && Date.now() - active.lastSample >= METER_SECONDS * 1000)
        await meter();
      if (Date.now() - lastBeat >= HEARTBEAT_SECONDS * 1000) {
        await charger.call("Heartbeat", {});
        await charger.status(
          active ? (version === "1.6" ? "Charging" : "Occupied") : "Available",
        );
        lastBeat = Date.now();
        status(active ? "charging" : "idle");
      }
      await sleep(250);
    }
  } catch {
    mark("requires_operator_review");
    status("requires_operator_review");
    console.error(
      "Simulator requires source/operator reconciliation; no transaction or command was replayed.",
    );
    process.exitCode = 1;
  } finally {
    if (active || journal.phase !== "idle") {
      mark("requires_operator_review");
      status("requires_operator_review");
    } else status("stopped");
    await charger.close();
  }
}
main().catch(() => {
  console.error(
    "Private local simulator setup or reconciliation required; no replay performed.",
  );
  process.exitCode = 1;
});
