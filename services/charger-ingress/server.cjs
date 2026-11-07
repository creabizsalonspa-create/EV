"use strict";
// TLS admission/reverse proxy only. CitrineOS remains the OCPP protocol server.
const https = require("node:https"),
  http = require("node:http");
const { readFileSync } = require("node:fs");
const { createHmac, createHash, randomUUID } = require("node:crypto");
function createIngress(config) {
  const auth = new URL(config.authUrl),
    upstream = new URL(config.upstreamUrl);
  const loopback = (u) =>
    ["127.0.0.1", "localhost", "[::1]"].includes(u.hostname);
  if (
    typeof config.secret !== "string" ||
    config.secret.length < 32 ||
    auth.username ||
    auth.password ||
    upstream.username ||
    upstream.password ||
    auth.search ||
    upstream.search ||
    upstream.pathname !== "/"
  )
    throw Error("Invalid ingress configuration");
  if (
    auth.protocol !== "https:" &&
    !(config.localTest && loopback(auth) && auth.protocol === "http:")
  )
    throw Error("Authentication requires HTTPS");
  if (
    upstream.protocol !== "https:" &&
    !(loopback(upstream) && upstream.protocol === "http:")
  )
    throw Error("Upstream requires TLS or loopback");
  const server = https.createServer(
    {
      key: config.key,
      cert: config.cert,
      ca: config.clientCa,
      minVersion: "TLSv1.2",
      requestCert: !!config.clientCa,
      rejectUnauthorized: !!config.clientCa,
    },
    (_, res) => {
      res.writeHead(404);
      res.end();
    },
  );
  server.maxConnections = 512;
  server.headersTimeout = 10000;
  server.requestTimeout = 15000;
  server.on("upgrade", async (request, socket, head) => {
    let proxy,
      remote,
      timer,
      authPending = false;
    const close = () => {
      clearInterval(timer);
      proxy?.destroy();
      remote?.destroy();
      socket.destroy();
    };
    socket.on("error", close);
    socket.on("close", () => {
      clearInterval(timer);
      proxy?.destroy();
      remote?.destroy();
    });
    const deny = (code = 401) => {
      if (!socket.destroyed)
        socket.end(
          `HTTP/1.1 ${code} Rejected\r\nConnection: close\r\nContent-Length: 0\r\n\r\n`,
        );
    };
    const path = request.url,
      authorization = request.headers.authorization || "";
    const protocol = request.headers["sec-websocket-protocol"];
    // Require one explicit protocol, not an ambiguous client-selected fallback.
    if (
      request.method !== "GET" ||
      !/^\/[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/.test(path) ||
      !["ocpp1.6", "ocpp2.0.1"].includes(protocol) ||
      !authorization.startsWith("Basic ") ||
      authorization.length > 512 ||
      request.headers["sec-websocket-version"] !== "13" ||
      !/^[A-Za-z0-9+/]{22}==$/.test(request.headers["sec-websocket-key"] || "")
    )
      return deny();
    const identity = path.slice(1);
    async function admitted() {
      const fingerprint = request.socket
        .getPeerCertificate()
        ?.fingerprint256?.replaceAll(":", "")
        .toLowerCase();
      const body = JSON.stringify({
        identity,
        protocol: protocol.slice(4),
        tls: true,
        certificate_sha256: fingerprint || null,
      });
      const ts = String(Math.floor(Date.now() / 1000)),
        nonce = randomUUID();
      const signature = createHmac("sha256", config.secret)
        .update(`${ts}\n${nonce}\n${authorization}\n${body}`)
        .digest("hex");
      try {
        const response = await fetch(auth, {
          method: "POST",
          redirect: "error",
          signal: AbortSignal.timeout(5000),
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            Authorization: authorization,
            "X-EV-Ingress-Time": ts,
            "X-EV-Ingress-Nonce": nonce,
            "X-EV-Ingress-Signature": signature,
          },
          body,
        });
        config.onAuthStatus?.(response.status);
        await response.body?.cancel();
        return response.status === 204;
      } catch (e) {
        config.onAuthStatus?.(0);
        return false;
      }
    }
    if (!(await admitted())) return deny();
    if (socket.destroyed) return;
    const transport = upstream.protocol === "https:" ? https : http;
    proxy = transport.request(upstream, {
      method: "GET",
      path,
      headers: {
        Host: upstream.host,
        Upgrade: "websocket",
        Connection: "Upgrade",
        "Sec-WebSocket-Key": request.headers["sec-websocket-key"],
        "Sec-WebSocket-Version": "13",
        "Sec-WebSocket-Protocol": protocol,
      },
      timeout: 10000,
    });
    proxy.on("timeout", close);
    proxy.on("error", close);
    proxy.on("response", (r) => {
      config.onUpstreamStatus?.(r.statusCode);
      r.resume();
      deny(502);
    });
    proxy.on("upgrade", (response, source, sourceHead) => {
      remote = source;
      remote.on("error", close);
      remote.on("close", () => socket.destroy());
      if (
        response.headers["sec-websocket-protocol"] !== protocol ||
        response.headers["sec-websocket-accept"] !==
          createHash("sha1")
            .update(
              request.headers["sec-websocket-key"] +
                "258EAFA5-E914-47DA-95CA-C5AB0DC85B11",
            )
            .digest("base64")
      ) {
        close();
        return;
      }
      socket.write(
        "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " +
          response.headers["sec-websocket-accept"] +
          "\r\nSec-WebSocket-Protocol: " +
          protocol +
          "\r\n\r\n",
      );
      if (head.length) remote.write(head);
      if (sourceHead.length) socket.write(sourceHead);
      socket.pipe(remote);
      remote.pipe(socket);
      // Revocation closes existing transport; never invent a remote stop or bill.
      timer = setInterval(async () => {
        if (authPending) return;
        authPending = true;
        try {
          if (!(await admitted())) close();
        } finally {
          authPending = false;
        }
      }, config.recheckMs || 30000);
      timer.unref();
    });
    proxy.end();
  });
  return server;
}
module.exports = { createIngress };
if (require.main === module) {
  const secret = readFileSync(
    process.env.EV_INGRESS_SECRET_FILE,
    "utf8",
  ).trim();
  const server = createIngress({
    secret,
    authUrl: process.env.EV_INGRESS_AUTH_URL,
    upstreamUrl: process.env.EV_INGRESS_UPSTREAM_URL,
    key: readFileSync(process.env.EV_INGRESS_TLS_KEY_FILE),
    cert: readFileSync(process.env.EV_INGRESS_TLS_CERT_FILE),
    clientCa: process.env.EV_INGRESS_CLIENT_CA_FILE
      ? readFileSync(process.env.EV_INGRESS_CLIENT_CA_FILE)
      : undefined,
    localTest: process.env.EV_INGRESS_LOCAL_TEST === "1",
  });
  server.listen(
    Number(process.env.EV_INGRESS_PORT || 8443),
    process.env.EV_INGRESS_HOST || "127.0.0.1",
    () =>
      process.stdout.write(
        "TLS charger admission ready; credentials are never logged.\n",
      ),
  );
}
