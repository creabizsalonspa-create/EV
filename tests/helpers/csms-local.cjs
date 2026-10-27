const fs = require("node:fs");
const { randomUUID } = require("node:crypto");
const assert = require("node:assert/strict");
const env = fs.readFileSync("services/csms/charging/.env", "utf8");
const secret = env
  .split("\n")
  .find((l) => l.startsWith("CHARGING_HASURA_ADMIN_SECRET="))
  .split("=")[1];
async function graph(query, variables = {}) {
  const response = await fetch("http://127.0.0.1:8095/v1/graphql", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "x-hasura-admin-secret": secret,
    },
    body: JSON.stringify({ query, variables }),
    signal: AbortSignal.timeout(15000),
  });
  const data = await response.json();
  assert(response.ok, "GraphQL transport rejected");
  if (data.errors) throw Error(JSON.stringify(data.errors));
  return data.data;
}
async function coreToken() {
  const r = await fetch("http://127.0.0.1:8080/dev/token", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ roles: ["admin"], tenantId: 1 }),
    signal: AbortSignal.timeout(10000),
  });
  assert.equal(r.status, 200);
  return (await r.json()).accessToken;
}
async function coreCommand(token, protocol, station, action, payload) {
  assert(
    /^ev-test-[a-zA-Z0-9-]+$/.test(station),
    "Only generated local simulator identifiers are permitted",
  );
  assert(["1.6", "2.0.1"].includes(protocol), "Unsupported simulator protocol");
  const r = await fetch(
    `http://127.0.0.1:8080/ocpp/${protocol}/evdriver/${action}?identifier=${encodeURIComponent(station)}&tenantId=1`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: "Bearer " + token,
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(30000),
    },
  );
  assert.equal(r.status, 200);
  const j = await r.json();
  assert.equal(j[0]?.success, true);
  return j;
}
async function poll(fn, description, timeout = 12000) {
  const deadline = Date.now() + timeout;
  let last;
  while (Date.now() < deadline) {
    last = await fn();
    if (last) return last;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw Error(description + " did not converge");
}
class SimulatedCharger {
  constructor(version, name) {
    assert(
      ["1.6", "2.0.1"].includes(version),
      "Unsupported simulator protocol",
    );
    assert(
      /^ev-test-[a-zA-Z0-9-]+$/.test(name),
      "Only generated local simulator identifiers are permitted",
    );
    this.version = version;
    this.name = name;
    this.pending = new Map();
    this.commands = [];
  }
  async connect() {
    this.socket = new WebSocket(
      "ws://127.0.0.1:8081/" + this.name,
      "ocpp" + this.version,
    );
    this.socket.addEventListener("message", (event) => {
      const m = JSON.parse(event.data);
      if (m[0] === 2) {
        this.commands.push({ action: m[2], payload: m[3] });
        if (
          [
            "RemoteStartTransaction",
            "RequestStartTransaction",
            "RemoteStopTransaction",
            "RequestStopTransaction",
          ].includes(m[2])
        )
          this.socket.send(JSON.stringify([3, m[1], { status: "Accepted" }]));
        else
          this.socket.send(
            JSON.stringify([
              4,
              m[1],
              "NotImplemented",
              "Unsupported simulator command",
              {},
            ]),
          );
      } else if (m[0] === 3 || m[0] === 4) {
        const p = this.pending.get(m[1]);
        if (p) {
          this.pending.delete(m[1]);
          m[0] === 3 ? p.resolve(m[2]) : p.reject(Error(JSON.stringify(m)));
        }
      }
    });
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(Error("Connect timeout")), 10000);
      this.socket.addEventListener(
        "open",
        () => {
          clearTimeout(timer);
          resolve();
        },
        { once: true },
      );
      this.socket.addEventListener(
        "error",
        (event) => {
          clearTimeout(timer);
          reject(
            Error(
              "WebSocket failed: " +
                (event.error?.message || event.message || "handshake rejected"),
            ),
          );
        },
        { once: true },
      );
    });
  }
  call(action, payload, id = randomUUID()) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(Error(action + " timed out"));
      }, 25000);
      this.pending.set(id, {
        resolve: (x) => {
          clearTimeout(timer);
          resolve(x);
        },
        reject: (e) => {
          clearTimeout(timer);
          reject(e);
        },
      });
      this.socket.send(JSON.stringify([2, id, action, payload]));
    });
  }
  async boot() {
    const result = await this.call(
      "BootNotification",
      this.version === "1.6"
        ? {
            chargePointVendor: "CreaBiz Simulator",
            chargePointModel: "Session QA",
          }
        : {
            reason: "PowerUp",
            chargingStation: {
              vendorName: "CreaBiz Simulator",
              model: "Session QA",
            },
          },
    );
    assert.equal(result.status, "Accepted");
    return result;
  }
  status(status = "Available") {
    return this.call(
      "StatusNotification",
      this.version === "1.6"
        ? {
            connectorId: 1,
            errorCode: status === "Faulted" ? "PowerSwitchFailure" : "NoError",
            status,
            timestamp: new Date().toISOString(),
          }
        : {
            timestamp: new Date().toISOString(),
            connectorStatus: status,
            evseId: 1,
            connectorId: 1,
          },
    );
  }
  async close() {
    if (!this.socket || this.socket.readyState === 3) return;
    await new Promise((resolve) => {
      const t = setTimeout(resolve, 3000);
      this.socket.addEventListener(
        "close",
        () => {
          clearTimeout(t);
          resolve();
        },
        { once: true },
      );
      this.socket.close();
    });
  }
}
module.exports = { graph, coreToken, coreCommand, poll, SimulatedCharger };
