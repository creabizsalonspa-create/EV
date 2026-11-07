const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
const { promisify } = require("node:util");
const execFile = promisify(require("node:child_process").execFile);
const { BusinessClient } = require("./helpers/business-client.cjs");
async function businessAccount(role) {
  const client = await new BusinessClient().register(role);
  return client.request.bind(client);
}
async function protocol(version) {
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
            chargePointVendor: "CreaBiz simulator",
            chargePointModel: "Protocol test",
          }
        : {
            reason: "PowerUp",
            chargingStation: {
              vendorName: "CreaBiz simulator",
              model: "Protocol test",
            },
          },
    );
    assert(["Accepted", "Pending"].includes(boot.status), JSON.stringify(boot));
    assert(Number.isInteger(boot.interval));
    assert(Number.isFinite(Date.parse(boot.currentTime)));
    if (boot.status === "Accepted") {
      const heartbeat = await call("Heartbeat", {});
      assert(Number.isFinite(Date.parse(heartbeat.currentTime)));
      const status = await call(
        "StatusNotification",
        version === "ocpp1.6"
          ? {
              connectorId: 1,
              errorCode: "NoError",
              status: "Available",
              timestamp: new Date().toISOString(),
            }
          : {
              timestamp: new Date().toISOString(),
              connectorStatus: "Available",
              evseId: 1,
              connectorId: 1,
            },
      );
      assert.deepEqual(status, {});
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
    const vendor = await businessAccount("vendor"),
      admin = await businessAccount("admin");
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
      }),
    );
  } finally {
    ws.close();
    for (const p of pending.values()) p.reject(Error("Socket closed"));
  }
}
(async () => {
  await protocol("ocpp1.6");
  await protocol("ocpp2.0.1");
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
