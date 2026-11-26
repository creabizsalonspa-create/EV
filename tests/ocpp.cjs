const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
const { promisify } = require("node:util");
const execFile = promisify(require("node:child_process").execFile);
const { BusinessClient } = require("./helpers/business-client.cjs");
const { graph, poll } = require("./helpers/csms-local.cjs");
const { SimulatedCharger } = require("./helpers/csms-local.cjs");
async function businessAccount(role) {
  const client = await new BusinessClient().register(role);
  if (
    role === "admin" &&
    (client.user.admin_second_factor_required === true ||
      process.env.EV_OCPP_VERIFY_MFA === "1")
  ) {
    // Real enrollment/challenge when the existing policy requires it; no
    // seeded browser session, disabled gate or direct factor-table mutation.
    const factor = await client.request("admin/second-factor/begin", "POST", {
      password: client.testCredentials.password,
    });
    const container = process.env.EV_OCPP_API_CONTAINER || "ev-business-api";
    assert(["ev-business-api", "ev-agent1-charging"].includes(container));
    const code = require("node:child_process")
      .execFileSync(
        "docker",
        [
          "exec",
          "-i",
          "-w",
          "/app/services/business-api",
          container,
          "php",
          "-r",
          'require "vendor/autoload.php"; echo App\\Security\\AdminTotp::codeAtStep(trim(stream_get_contents(STDIN)), intdiv(time(), 30));',
        ],
        { input: factor.secret, encoding: "utf8", timeout: 10000 },
      )
      .trim();
    assert(/^\d{6}$/.test(code));
    const confirmation = await client.request(
      "admin/second-factor/confirm",
      "POST",
      { code },
    );
    assert.equal(confirmation.verified, true);
    client.csrf = (await client.request("csrf")).token;
  }
  return client.request.bind(client);
}
async function protocol(version, profile, actors) {
  const station =
    "ev-test-" + version.replaceAll(".", "") + "-" + randomUUID().slice(0, 8);
  const ws = new WebSocket("ws://127.0.0.1:8081/" + station, version);
  const pending = new Map();
  let incoming = 0;
  let commandResolve;
  let commandReceived = new Promise((resolve) => (commandResolve = resolve));
  ws.addEventListener("message", (e) => {
    let m = JSON.parse(e.data);
    if (m[0] === 3 || m[0] === 4) {
      let p = pending.get(m[1]);
      if (p) {
        pending.delete(m[1]);
        m[0] === 3 ? p.resolve(m[2]) : p.reject(Error(JSON.stringify(m)));
      }
    } else if (m[0] === 2) {
      incoming++;
      if (
        ["RemoteStartTransaction", "RequestStartTransaction"].includes(m[2])
      ) {
        ws.send(JSON.stringify([3, m[1], { status: "Accepted" }]));
        commandResolve(m[2]);
      } else
        ws.send(
          JSON.stringify([
            4,
            m[1],
            "NotImplemented",
            "Test simulator supports only declared inbound calls",
            {},
          ]),
        );
    }
  });
  function call(action, payload) {
    return new Promise((resolve, reject) => {
      let id = randomUUID();
      let timer = setTimeout(() => {
        pending.delete(id);
        reject(Error(action + " timed out"));
      }, 25000);
      pending.set(id, {
        resolve: (x) => {
          clearTimeout(timer);
          resolve(x);
        },
        reject: (e) => {
          clearTimeout(timer);
          reject(e);
        },
      });
      ws.send(JSON.stringify([2, id, action, payload]));
    });
  }
  await new Promise((resolve, reject) => {
    let t = setTimeout(() => reject(Error("WebSocket connect timeout")), 10000);
    ws.onopen = () => {
      clearTimeout(t);
      resolve();
    };
    ws.onerror = (e) => {
      clearTimeout(t);
      reject(Error("WebSocket connect failed"));
    };
  });
  try {
    const boot = await call(
      "BootNotification",
      version === "ocpp1.6"
        ? {
            chargePointVendor: profile.vendor,
            chargePointModel: profile.model,
            firmwareVersion: profile.firmware,
          }
        : {
            reason: "PowerUp",
            chargingStation: {
              vendorName: profile.vendor,
              model: profile.model,
              firmwareVersion: profile.firmware,
            },
          },
    );
    assert.equal(boot.status, "Accepted",
      "Only an accepted local simulator Boot can prove the full status/model test matrix: " + JSON.stringify(boot));
    assert(Number.isInteger(boot.interval));
    assert(Number.isFinite(Date.parse(boot.currentTime)));
    if (boot.status === "Accepted") {
      const heartbeat = await call("Heartbeat", {});
      assert(Number.isFinite(Date.parse(heartbeat.currentTime)));
      // Fault, outage and recovery are simulator-status events only:
      // never use a physical charger or infer an actual current rating.
      const states = [
        ["Available", "NoError"],
        ["Faulted", "GroundFailure"],
        ["Unavailable", "NoError"],
        ["Available", "NoError"],
      ];
      const observedBefore = Date.now() - 10000;
      for (let i = 0; i < states.length; i++) {
        const [state, error] = states[i];
        const timestamp = new Date(observedBefore + i * 1000).toISOString();
        const result = await call(
          "StatusNotification",
          version === "ocpp1.6"
            ? {
                connectorId: 1,
                errorCode: error,
                status: state,
                timestamp,
              }
            : {
                timestamp,
                connectorStatus: state,
                evseId: 1,
                connectorId: 1,
              },
        );
        assert.deepEqual(result, {});
      }
      const source = await poll(async () => {
        const rows = await graph(
          "query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id chargePointVendor chargePointModel firmwareVersion protocol}}",
          { name: station },
        );
        if (!Array.isArray(rows.ChargingStations) ||
            rows.ChargingStations.length !== 1) return null;
        const row = rows.ChargingStations[0];
        return row.chargePointVendor === profile.vendor &&
            row.chargePointModel === profile.model &&
            row.firmwareVersion === profile.firmware ? row : null;
      }, "Typed OCPP manufacturer/model/firmware lineage");
      assert(Number.isInteger(source.id) && source.id > 0);
      assert([version.replace("ocpp", ""), version].includes(source.protocol));
      const notifications = await poll(async () => {
        const rows = await graph(
          "query($id:Int!){StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$id}},order_by:{id:desc},limit:8){id evseId connectorId connectorStatus errorCode timestamp}}",
          { id: source.id },
        );
        if (!Array.isArray(rows.StatusNotifications)) return null;
        const latest = rows.StatusNotifications.filter(
          (entry) => entry.connectorId === 1 &&
            // OCPP 1.6 identifies the physical connector directly and does
            // not transmit an EVSE ID. A CSMS may normalize its EVSE field
            // differently; do not infer a charger-side EVSE identity from it.
            (version === "ocpp1.6" || entry.evseId === 1),
        ).slice(0, states.length);
        if (latest.map((e) => e.connectorStatus).join(",") !==
            states.map((row) => row[0]).reverse().join(",")) return null;
        return latest;
      }, "OCPP outage/fault/recovery source-history sequence");
      assert.equal(notifications.length, states.length);
      if (version === "ocpp1.6") {
        assert.equal(notifications[2].errorCode, "GroundFailure");
      }
      // Bounded concurrent read-only source load: every independent read
      // must resolve the same typed authenticated station, not an adjacent
      // model, protocol or firmware from another simulator.
      const identityQuery =
        "query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id chargePointVendor chargePointModel firmwareVersion protocol}}";
      const reads = await Promise.all(
        Array.from({length: 8}, () => graph(identityQuery, {name: station})),
      );
      for (const read of reads) {
        assert.equal(read.ChargingStations.length, 1);
        assert.equal(read.ChargingStations[0].id, source.id);
        assert.equal(read.ChargingStations[0].chargePointVendor, profile.vendor);
        assert.equal(read.ChargingStations[0].chargePointModel, profile.model);
        assert.equal(read.ChargingStations[0].firmwareVersion, profile.firmware);
      }
      const tokenResponse = await fetch("http://127.0.0.1:8080/dev/token", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ roles: ["admin"], tenantId: 1 }),
      });
      assert.equal(tokenResponse.status, 200);
      const { accessToken } = await tokenResponse.json();
      const path =
        version === "ocpp1.6"
          ? "1.6/evdriver/remoteStartTransaction"
          : "2.0.1/evdriver/requestStartTransaction";
      const payload =
        version === "ocpp1.6"
          ? { idTag: "EV-SIMULATOR", connectorId: 1 }
          : {
              idToken: { idToken: "EV-SIMULATOR", type: "Central" },
              remoteStartId: 1,
              evseId: 1,
            };
      const url =
        "http://127.0.0.1:8080/ocpp/" +
        path +
        "?identifier=" +
        station +
        "&tenantId=1";
      const denied = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      assert.equal(denied.status, 401);
      const command = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + accessToken,
        },
        body: JSON.stringify(payload),
      });
      assert.equal(command.status, 200, await command.text());
      let timer;
      try {
        const action = await Promise.race([
          commandReceived,
          new Promise((_, reject) => {
            timer = setTimeout(
              () => reject(Error("Remote command not received")),
              10000,
            );
          }),
        ]);
        assert.equal(
          action,
          version === "ocpp1.6"
            ? "RemoteStartTransaction"
            : "RequestStartTransaction",
        );
      } finally {
        clearTimeout(timer);
      }
    }
    const { vendor, admin } = actors;
    const location = await vendor("stations", "POST", {
      name: "Protocol adapter " + station,
      country: "AE",
      currency: "AED",
      timezone: "Asia/Dubai",
      latitude: 25,
      longitude: 55,
      source: "simulator",
      connector_type: "CCS2",
      power_kw: 50,
    });
    await admin("stations/" + location.id + "/csms-binding", "POST", {
      identifier: station,
      protocol: version === "ocpp1.6" ? "1.6" : "2.0.1",
    });
    const queued = await vendor(
      "stations/" + location.id + "/commands",
      "POST",
      { action: "start", idempotency_key: randomUUID() },
    );
    assert.equal(queued.status, "pending");
    await execFile("./scripts/csms-dispatch", [String(queued.id)]);
    const commands = await vendor("charger-commands");
    assert.equal(
      commands.data.find((c) => c.id === queued.id).status,
      "submitted",
    );
    const until = Date.now() + 10000;
    while (incoming < 2 && Date.now() < until)
      await new Promise((r) => setTimeout(r, 50));
    assert.equal(incoming, 2);
    console.log(
      JSON.stringify({
        business_outbox: "submitted",
        protocol: version,
        evidence: "SIMULATED_PROTOCOL_TEST",
        station,
        boot: boot.status,
        heartbeat_status_tested: boot.status === "Accepted",
        incoming_calls: incoming,
        synthetic_profile: profile,
        source_status_matrix: ["Available", "Faulted", "Unavailable", "Available"],
        physical_hardware_accepted: false,
      }),
    );
  } finally {
    ws.close();
    for (const p of pending.values()) p.reject(Error("Socket closed"));
  }
}
async function mixedProtocolReconnect() {
  // Four independent local clients, no extra business accounts or remote
  // commands. Keep both protocols active while one of each disconnects.
  const clients = ["1.6", "2.0.1", "1.6", "2.0.1"].map(
    (version) => new SimulatedCharger(version, "ev-test-load-" + version.replaceAll(".", "") + "-" + randomUUID().replaceAll("-", "").slice(0, 12)),
  );
  const lookup = async (client) => {
    const data = await graph(
      "query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol}}",
      { name: client.name },
    );
    assert(Array.isArray(data.ChargingStations));
    if (!data.ChargingStations.length) return null;
    assert.equal(data.ChargingStations.length, 1);
    const row = data.ChargingStations[0];
    assert(Number.isInteger(row.id) && row.id > 0);
    assert([client.version, "ocpp" + client.version].includes(row.protocol));
    assert.equal(typeof row.isOnline, "boolean");
    return row;
  };
  const history = async (id) => {
    const data = await graph(
      "query($id:Int!){StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$id}},order_by:{id:asc},limit:20){id evseId connectorId connectorStatus}}",
      { id },
    );
    assert(Array.isArray(data.StatusNotifications));
    return data.StatusNotifications;
  };
  const status = async (client, state) => {
    assert.deepEqual(await client.status(state), {});
  };
  const prove = async (client, id, online) => poll(async () => {
    const row = await lookup(client);
    if (row) assert.equal(row.id, id, "Reconnect changed durable station identity");
    return row?.isOnline === online ? row : null;
  }, "Mixed protocol online state");
  try {
    // Enroll each unique fixture once, using the same short station identity
    // shape as the passing protocol/session suite. Keep every socket open:
    // the load test starts with all four durable stations active, not with
    // simultaneous first-time database registration. Never retry a Boot RPC.
    for (const c of clients) {
      try {
        await c.connect();
        await c.boot();
        await poll(async () => {
          const row = await lookup(c);
          return row?.isOnline === true ? row : null;
        }, "Initial durable station registration");
      } catch (error) {
        throw Error("Mixed protocol initial enrollment (" + c.version + ", " + c.name + "): " + error.message);
      }
    }
    const rows = await Promise.all(clients.map((c) => poll(async () => {
      const row = await lookup(c);
      return row?.isOnline === true ? row : null;
    }, "Initial mixed protocol station")));
    const ids = rows.map((row) => row.id);
    assert.equal(new Set(ids).size, clients.length);
    await Promise.all(clients.map((c) => status(c, "Available")));
    const initial = await Promise.all(ids.map((id) => poll(async () => {
      const list = await history(id);
      return list.some((e) => e.connectorId === 1 && e.connectorStatus === "Available") ? list : null;
    }, "Initial durable connector status")));
    await Promise.all([status(clients[0], "Faulted"), status(clients[1], "Faulted")]);
    const faults = await Promise.all(ids.slice(0, 2).map((id) => poll(async () => {
      const list = await history(id);
      return list.find((e) => e.connectorId === 1 && e.connectorStatus === "Faulted");
    }, "Fault persisted before outage")));
    await Promise.all(clients.slice(0, 2).map((c) => c.close()));
    await Promise.all(clients.slice(0, 2).map((c, i) => prove(c, ids[i], false)));

    // The unaffected clients must continue to serve correlated RPCs and
    // retain their own source histories during the other devices' outage.
    const live = clients.slice(2);
    await Promise.all(live.map(async (c) => {
      const heartbeat = await c.call("Heartbeat", {});
      assert(Number.isFinite(Date.parse(heartbeat.currentTime)));
      await status(c, "Unavailable");
    }));
    await Promise.all(live.map((c, i) => prove(c, ids[i + 2], true)));
    // Re-admit each previously disconnected client while its unaffected
    // peers remain connected; never retry an uncertain BootNotification.
    for (const [i, c] of clients.slice(0, 2).entries()) {
      try {
        await c.reconnect();
        await c.boot();
        await prove(c, ids[i], true);
      } catch (error) {
        throw Error("Mixed protocol reconnect (" + c.version + ", " + c.name + "): " + error.message);
      }
    }
    await Promise.all(clients.map((c) => status(c, "Available")));

    await Promise.all(clients.map((c, i) => poll(async () => {
      const list = await history(ids[i]);
      const target = list.filter((e) => e.connectorId === 1 &&
        (c.version === "1.6" || e.evseId === 1));
      const boundary = i < 2 ? faults[i].id : initial[i].at(-1).id;
      const expected = i < 2 ? "Faulted" : "Unavailable";
      const intermediate = target.find((e) => e.connectorStatus === expected &&
        (i < 2 ? e.id === boundary : e.id > boundary));
      if (!intermediate || !target.some((e) => e.id > intermediate.id && e.connectorStatus === "Available")) return null;
      assert(!target.some((e) => e.connectorStatus === (i < 2 ? "Unavailable" : "Faulted")));
      assert(target.every((e, index) => Number.isInteger(e.id) &&
        (index === 0 || e.id > target[index - 1].id)));
      return true;
    }, "Per-station durable outage/recovery isolation")));
    assert(clients.every((c) => c.commands.length === 0));
    console.log(JSON.stringify({
      evidence: "SIMULATED_MIXED_PROTOCOL_RECONNECT",
      concurrent_clients: clients.length,
      reconnects: 2,
      unaffected_clients: 2,
      durable_identity_preserved: true,
      remote_commands: 0,
      physical_hardware_accepted: false,
    }));
  } finally {
    await Promise.allSettled(clients.map((c) => c.close()));
  }
}

(async () => {
  // Two bounded identity/firmware variants per protocol. Each uses a unique
  // generated local simulator station; no production CSMS or live hardware.
  const profiles = [
    {vendor: "CreaBiz simulator", model: "Protocol test", firmware: "sim-fw-v1"},
    {vendor: "Synthetic Labs", model: "Protocol Edge", firmware: "sim-fw-v2"},
  ];
  for (const version of ["ocpp1.6", "ocpp2.0.1"]) {
    // Two authenticated actors per protocol, reused only within this local
    // test family. Do not double the shared sign-in budget for model samples.
    const actors = {
      vendor: await businessAccount("vendor"),
      admin: await businessAccount("admin"),
    };
    for (const profile of profiles) await protocol(version, profile, actors);
  }
  await mixedProtocolReconnect();
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
