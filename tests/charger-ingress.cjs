const assert = require("node:assert/strict"),
  https = require("node:https");
const { execFileSync } = require("node:child_process"),
  { randomBytes, X509Certificate } = require("node:crypto");
const { mkdtempSync, readFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path");
const { createIngress } = require("../services/charger-ingress/server.cjs");
(async () => {
  execFileSync("./scripts/charger-ingress-setup", { stdio: "pipe" });
  const dir = mkdtempSync(join(tmpdir(), "ev-ingress-"));
  try {
    execFileSync(
      "openssl",
      [
        "req",
        "-x509",
        "-newkey",
        "rsa:2048",
        "-nodes",
        "-keyout",
        join(dir, "key.pem"),
        "-out",
        join(dir, "cert.pem"),
        "-days",
        "1",
        "-subj",
        "/CN=localhost",
        "-addext",
        "subjectAltName=DNS:localhost,IP:127.0.0.1",
      ],
      { stdio: "pipe" },
    );
    const key = readFileSync(join(dir, "key.pem")),
      cert = readFileSync(join(dir, "cert.pem"));
    let server = createIngress({
      key,
      cert,
      secret: readFileSync("services/charger-ingress/.env", "utf8").trim(),
      authUrl:
        "http://127.0.0.1:8000/api/internal/charger-ingress/authenticate",
      upstreamUrl: "http://127.0.0.1:8081",
      localTest: true,
      recheckMs: 1000,
    });
    await new Promise((r) => server.listen(0, "127.0.0.1", r));
    const sockets = new Set();
    try {
      function connect(f, protocol, secret, clientCertificate = false) {
        return new Promise((resolve, reject) => {
          const request = https.request({
            hostname: "127.0.0.1",
            port: server.address().port,
            path: "/" + f.identity,
            ca: cert,
            ...(clientCertificate ? { key, cert } : {}),
            headers: {
              Connection: "Upgrade",
              Upgrade: "websocket",
              "Sec-WebSocket-Key": randomBytes(16).toString("base64"),
              "Sec-WebSocket-Version": "13",
              "Sec-WebSocket-Protocol": "ocpp" + protocol,
              Authorization:
                "Basic " +
                Buffer.from(f.identity + ":" + secret).toString("base64"),
            },
          });
          request.setTimeout(15000, () =>
            request.destroy(Error("TLS handshake timed out")),
          );
          request.on("response", (r) => {
            r.resume();
            resolve({ status: r.statusCode });
          });
          request.on("error", reject);
          request.on("upgrade", (r, s, head) => {
            sockets.add(s);
            s.on("error", () => {});
            s.on("close", () => sockets.delete(s));
            resolve({ status: r.statusCode, socket: s, head });
          });
          request.end();
        });
      }
      function boot(socket, protocol) {
        return new Promise((resolve, reject) => {
          let buffer = Buffer.alloc(0);
          const timeout = setTimeout(
            () => reject(Error("No upstream OCPP boot response")),
            15000,
          );
          const callback = (data) => {
            buffer = Buffer.concat([buffer, data]);
            if (buffer.length < 2) return;
            let length = buffer[1] & 127,
              offset = 2;
            if (length === 126) {
              if (buffer.length < 4) return;
              length = buffer.readUInt16BE(2);
              offset = 4;
            }
            if (length === 127) {
              reject(Error("Unexpected oversized fixture frame"));
              return;
            }
            if (buffer.length < offset + length) return;
            clearTimeout(timeout);
            socket.off("data", callback);
            try {
              resolve(
                JSON.parse(buffer.subarray(offset, offset + length).toString()),
              );
            } catch (e) {
              reject(e);
            }
          };
          socket.on("data", callback);
          const body =
            protocol === "1.6"
              ? {
                  chargePointVendor: "Synthetic",
                  chargePointModel: "Admission test",
                }
              : {
                  reason: "PowerUp",
                  chargingStation: {
                    vendorName: "Synthetic",
                    model: "Admission test",
                  },
                };
          const bytes = Buffer.from(
              JSON.stringify([2, "admission-boot", "BootNotification", body]),
            ),
            mask = randomBytes(4),
            header =
              bytes.length < 126
                ? Buffer.from([0x81, 0x80 | bytes.length])
                : Buffer.from([
                    0x81,
                    0xfe,
                    bytes.length >> 8,
                    bytes.length & 255,
                  ]);
          const payload = Buffer.from(bytes);
          for (let i = 0; i < payload.length; i++) payload[i] ^= mask[i % 4];
          socket.write(Buffer.concat([header, mask, payload]));
        });
      }
      for (const protocol of ["1.6", "2.0.1"]) {
        const run = randomBytes(4).toString("hex"),
          password = randomBytes(24).toString("hex") + "aA9!";
        const f = JSON.parse(
          execFileSync(
            "./scripts/php",
            ["tests/Fixtures/charger-admission.php", run, password, protocol],
            { encoding: "utf8" },
          ),
        );
        assert.equal((await connect(f, protocol, "0".repeat(40))).status, 401);
        assert.equal(
          (await connect(f, protocol === "1.6" ? "2.0.1" : "1.6", f.secret))
            .status,
          401,
        );
        const accepted = await connect(f, protocol, f.secret);
        assert.equal(accepted.status, 101);
        const reply = await boot(accepted.socket, protocol);
        assert.equal(reply[0], 3);
        assert.equal(reply[2].status, "Accepted");
        const rotated = JSON.parse(
          execFileSync(
            "./scripts/php",
            [
              "tests/Fixtures/charger-admission-change.php",
              String(f.charger_id),
              "rotate",
              "1",
            ],
            { encoding: "utf8" },
          ),
        );
        await new Promise((resolve, reject) => {
          if (accepted.socket.destroyed) return resolve();
          const timeout = setTimeout(
            () => reject(Error("Old authenticated connection not revoked")),
            7000,
          );
          accepted.socket.once("close", () => {
            clearTimeout(timeout);
            resolve();
          });
        });
        assert.equal((await connect(f, protocol, f.secret)).status, 401);
        // CitrineOS may still be releasing its station-identity lock after
        // the revoked transport closes. Retry only this new TLS upgrade,
        // never a BootNotification or charging command, and never a 401/429.
        let next;
        for (const delay of [0, 100, 250, 500, 1000]) {
          if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
          next = await connect(f, protocol, rotated.secret);
          if (next.status !== 502) break;
        }
        assert.equal(
          next.status,
          101,
          "Rotated credential reconnect must finish upstream identity cleanup",
        );
        const reconnectedBoot = await boot(next.socket, protocol);
        assert.equal(reconnectedBoot[2].status, "Accepted");
        execFileSync(
          "./scripts/php",
          [
            "tests/Fixtures/charger-admission-change.php",
            String(f.charger_id),
            "revoke",
            "2",
          ],
          { stdio: "pipe" },
        );
        assert.equal((await connect(f, protocol, rotated.secret)).status, 401);
        next.socket.destroy();
        console.log(
          JSON.stringify({
            protocol,
            tls_verified: true,
            credential_rotation: true,
            existing_transport_revoked: true,
            unknown_secret_rejected: true,
            source:
              "actual loopback CitrineOS BootNotification via signed Laravel admission",
            physical_charging_verified: false,
          }),
        );
      }
      for (const socket of sockets) socket.destroy();
      server.closeAllConnections();
      await new Promise((r) => server.close(r));
      server = createIngress({
        key,
        cert,
        clientCa: cert,
        secret: readFileSync("services/charger-ingress/.env", "utf8").trim(),
        authUrl:
          "http://127.0.0.1:8000/api/internal/charger-ingress/authenticate",
        upstreamUrl: "http://127.0.0.1:8081",
        localTest: true,
        recheckMs: 1000,
      });
      await new Promise((r) => server.listen(0, "127.0.0.1", r));
      const run = randomBytes(4).toString("hex"),
        password = randomBytes(24).toString("hex") + "aA9!";
      const fingerprint = new X509Certificate(cert).fingerprint256
        .replaceAll(":", "")
        .toLowerCase();
      const f = JSON.parse(
        execFileSync(
          "./scripts/php",
          [
            "tests/Fixtures/charger-admission.php",
            run,
            password,
            "2.0.1",
            fingerprint,
          ],
          { encoding: "utf8" },
        ),
      );
      assert.equal(f.certificate_sha256, fingerprint);
      await assert.rejects(
        connect(f, "2.0.1", f.secret),
        /certificate|tls|socket/i,
      );
      const secure = await connect(f, "2.0.1", f.secret, true);
      assert.equal(secure.status, 101);
      assert.equal((await boot(secure.socket, "2.0.1"))[2].status, "Accepted");
      secure.socket.destroy();
      console.log(
        JSON.stringify({
          protocol: "2.0.1",
          mutual_tls_verified: true,
          certificate_pin_verified: true,
          missing_client_certificate_rejected: true,
          source: "synthetic trusted CA and actual loopback CitrineOS",
          physical_charging_verified: false,
        }),
      );
    } finally {
      for (const socket of sockets) socket.destroy();
      server.closeAllConnections();
      await new Promise((r) => server.close(r));
    }
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
})().catch((e) => {
  console.error(e.stack);
  process.exitCode = 1;
});
