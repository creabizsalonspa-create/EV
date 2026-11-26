// Local TLS/OAuth test facade over actual pinned CitrineOS. Never production auth.
// Only GetDiagnostics/GetLog and fixture GraphQL/upload traffic can pass.
const assert = require("node:assert/strict"),
  https = require("node:https"),
  http = require("node:http");
const fs = require("node:fs"),
  path = require("node:path"),
  crypto = require("node:crypto");
const { execFileSync } = require("node:child_process");
module.exports = async function withRegisteredDiagnosticRuntime(run) {
  assert.equal(
    process.env.EV_RUN_REGISTERED_DIAGNOSTICS_TESTS,
    "1",
    "Explicit isolated test opt-in required",
  );
  const root = fs.realpathSync(process.cwd());
  const privateParent = path.join(root, "artifacts", "diagnostics-production");
  fs.mkdirSync(privateParent, { recursive: true });
  const folder = fs.mkdtempSync(path.join(privateParent, ".runtime-"));
  fs.chmodSync(folder, 0o700);
  const name = "ev-agent1-diag-" + crypto.randomBytes(4).toString("hex");
  const envFile =
    process.env.EV_CSMS_FIXTURE_ENV || "services/csms/charging/.env";
  const env = Object.fromEntries(
    fs
      .readFileSync(envFile, "utf8")
      .split("\n")
      .filter((x) => x.includes("=") && !x.startsWith("#"))
      .map((x) => [x.slice(0, x.indexOf("=")), x.slice(x.indexOf("=") + 1)]),
  );
  const adminSecret = env.CHARGING_HASURA_ADMIN_SECRET;
  assert(typeof adminSecret === "string" && adminSecret.length >= 32);
  async function metadata(body) {
    const r = await fetch("http://127.0.0.1:8095/v1/metadata", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-hasura-admin-secret": adminSecret,
      },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(10000),
    });
    assert(r.ok, "Local diagnostic source metadata unavailable");
    return r.json();
  }
  const existing = await metadata({ type: "export_metadata", args: {} });
  const source = existing.sources?.find((x) => x.name === "default");
  assert(source, "Existing isolated CSMS metadata source required");
  for (const table of ["ChargingStations", "OCPPMessages"])
    if (!source.tables.some((x) => x.table.name === table))
      await metadata({
        type: "pg_track_table",
        args: { source: "default", table: { schema: "public", name: table } },
      });
  const keyFile = path.join(folder, "tls.key"),
    certFile = path.join(folder, "tls.crt");
  execFileSync(
    "openssl",
    [
      "req",
      "-x509",
      "-newkey",
      "rsa:2048",
      "-nodes",
      "-keyout",
      keyFile,
      "-out",
      certFile,
      "-days",
      "1",
      "-subj",
      "/CN=localhost",
      "-addext",
      "subjectAltName=IP:127.0.0.1,DNS:localhost",
    ],
    { stdio: "pipe" },
  );
  fs.chmodSync(keyFile, 0o600);
  const cert = fs.readFileSync(certFile),
    keys = crypto.generateKeyPairSync("rsa", { modulusLength: 2048 });
  const client = "business-diagnostics",
    secret = crypto.randomBytes(32).toString("hex"),
    issued = new Map();
  let origin,
    opsRequests = 0,
    uploads = 0,
    started = false;
  const appBase = "http://127.0.0.1:8001";
  async function body(req, limit) {
    const chunks = [];
    let size = 0;
    for await (const data of req) {
      size += data.length;
      if (size > limit) throw Error("bounded body");
      chunks.push(data);
    }
    return Buffer.concat(chunks);
  }
  function json(res, status, value) {
    res.writeHead(status, {
      "Content-Type": "application/json",
      "Cache-Control": "no-store",
    });
    res.end(JSON.stringify(value));
  }
  function jwt() {
    const now = Math.floor(Date.now() / 1000),
      encode = (x) => Buffer.from(JSON.stringify(x)).toString("base64url");
    const value =
      encode({ alg: "RS256", typ: "JWT", kid: "fixture" }) +
      "." +
      encode({
        iss: origin,
        aud: origin + "/ops",
        sub: client,
        tenantId: 1,
        roles: ["admin"],
        scope: "csms:operations",
        iat: now,
        exp: now + 300,
      });
    const token =
      value +
      "." +
      crypto
        .sign("RSA-SHA256", Buffer.from(value), keys.privateKey)
        .toString("base64url");
    issued.set(token, now + 300);
    return token;
  }
  const server = https.createServer(
    { key: fs.readFileSync(keyFile), cert },
    async (req, res) => {
      try {
        const url = new URL(req.url, origin);
        if (url.pathname === "/token") {
          assert.equal(req.method, "POST");
          assert.equal(
            req.headers.authorization,
            "Basic " + Buffer.from(client + ":" + secret).toString("base64"),
          );
          const form = new URLSearchParams((await body(req, 4096)).toString());
          assert.equal(form.get("grant_type"), "client_credentials");
          assert.equal(form.get("scope"), "csms:operations");
          return json(res, 200, { token_type: "Bearer", access_token: jwt() });
        }
        if (url.pathname === "/v1/graphql") {
          assert.equal(req.method, "POST");
          assert.equal(req.headers["x-hasura-admin-secret"], adminSecret);
          const wire = await body(req, 524288);
          const reply = await fetch("http://127.0.0.1:8095/v1/graphql", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "x-hasura-admin-secret": adminSecret,
            },
            body: wire,
            signal: AbortSignal.timeout(10000),
          });
          res.writeHead(reply.status, { "Content-Type": "application/json" });
          return res.end(await reply.text());
        }
        if (
          /^\/ops\/ocpp\/(1\.6|2\.0\.1)\/reporting\/(getDiagnostics|getLog)$/.test(
            url.pathname,
          )
        ) {
          assert.equal(req.method, "POST");
          const bearer = req.headers.authorization?.replace(/^Bearer /, "");
          assert(issued.has(bearer) && issued.get(bearer) > Date.now() / 1000);
          assert.equal(url.searchParams.get("tenantId"), "1");
          assert(
            /^ev-test-diag-[a-f0-9]{8}-(16|201)$/.test(
              url.searchParams.get("identifier"),
            ),
          );
          const wire = await body(req, 8192),
            tokenResponse = await fetch("http://127.0.0.1:8080/dev/token", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ roles: ["admin"], tenantId: 1 }),
              signal: AbortSignal.timeout(10000),
            });
          assert(tokenResponse.ok);
          const token = (await tokenResponse.json()).accessToken;
          assert.equal(typeof token, "string");
          opsRequests++;
          const reply = await fetch(
            "http://127.0.0.1:8080" + url.pathname.slice(4) + url.search,
            {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                Authorization: "Bearer " + token,
              },
              body: wire,
              signal: AbortSignal.timeout(15000),
            },
          );
          res.writeHead(reply.status, { "Content-Type": "application/json" });
          return res.end(await reply.text());
        }
        if (
          /^\/api\/internal\/diagnostic-logs\/[1-9][0-9]*(\/[A-Za-z0-9][A-Za-z0-9._-]{0,254})?$/.test(
            url.pathname,
          )
        ) {
          assert.equal(req.method, "PUT");
          const wire = await body(req, 524288);
          uploads++;
          const reply = await fetch(appBase + url.pathname, {
            method: "PUT",
            headers: {
              "Content-Type": "application/octet-stream",
              Authorization: req.headers.authorization,
              "Content-Length": String(wire.length),
              Accept: "application/json",
            },
            body: wire,
            signal: AbortSignal.timeout(15000),
          });
          res.writeHead(reply.status, { "Content-Type": "application/json" });
          return res.end(await reply.text());
        }
        json(res, 404, { message: "No fixture operation" });
      } catch {
        if (!res.headersSent)
          json(res, 503, { message: "Controlled TLS fixture unavailable" });
        else res.end();
      }
    },
  );
  try {
    await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
    origin = "https://127.0.0.1:" + server.address().port;
    const relative = path.relative(root, folder),
      database = path.join(folder, "business.sqlite");
    fs.closeSync(fs.openSync(database, "wx", 0o600));
    fs.writeFileSync(
      path.join(folder, "trust.pem"),
      Buffer.concat([
        fs.readFileSync("/etc/ssl/certs/ca-certificates.crt"),
        Buffer.from("\n"),
        cert,
      ]),
      { mode: 0o600 },
    );
    fs.writeFileSync(
      path.join(folder, "trust.ini"),
      "curl.cainfo=/run/diagnostic-fixture/trust.pem\nopenssl.cafile=/run/diagnostic-fixture/trust.pem\n",
      { mode: 0o600 },
    );
    const cfg = {
      APP_ENV: "local",
      DB_CONNECTION: "sqlite",
      DB_DATABASE: "/app/" + relative + "/business.sqlite",
      DB_URL: "",
      CSMS_SESSION_BRIDGE_ENABLED: "false",
      CSMS_UPSTREAM_REGISTRATION_ENABLED: "true",
      CSMS_UPSTREAM_REGISTRATION_ALLOW_LOCAL_HTTP: "true",
      CSMS_GRAPHQL_URL: origin + "/v1/graphql",
      CSMS_GRAPHQL_SECRET: adminSecret,
      CSMS_PRODUCTION_DIAGNOSTICS_ENABLED: "true",
      CSMS_PRODUCTION_DIAGNOSTIC_UPLOAD_ORIGIN: origin,
      CSMS_DIAGNOSTIC_LOGS_ENABLED: "true",
      CSMS_OPERATIONS_BASE_URL: origin + "/ops",
      CSMS_OPERATIONS_AUTH_MODE: "oauth2",
      CSMS_OPERATIONS_OAUTH_TOKEN_URL: origin + "/token",
      CSMS_OPERATIONS_OAUTH_CLIENT_ID: client,
      CSMS_OPERATIONS_OAUTH_CLIENT_SECRET: secret,
      CSMS_OPERATIONS_OAUTH_ISSUER: origin,
      CSMS_OPERATIONS_OAUTH_AUDIENCE: origin + "/ops",
      CSMS_OPERATIONS_OAUTH_SCOPE: "csms:operations",
      CSMS_OPERATIONS_OAUTH_PUBLIC_KEYS: JSON.stringify({
        fixture: keys.publicKey.export({ type: "spki", format: "pem" }),
      }),
      NO_PROXY: "127.0.0.1,localhost",
      no_proxy: "127.0.0.1,localhost",
      PHP_INI_SCAN_DIR: "/usr/local/etc/php/conf.d:/run/diagnostic-fixture",
      ADMIN_2FA_LOCAL_TEST: "true",
    };
    const config = path.join(folder, "app.env");
    fs.writeFileSync(
      config,
      Object.entries(cfg)
        .map(([k, v]) => k + "=" + v)
        .join("\n") + "\n",
      { mode: 0o600 },
    );
    const image =
      "composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc";
    const docker = [
      "--user",
      process.getuid() + ":" + process.getgid(),
      "--network",
      "host",
      "--env-file",
      config,
      "-v",
      root + ":/app",
      "-v",
      folder + ":/run/diagnostic-fixture:ro",
      "-w",
      "/app/services/business-api",
      "--entrypoint",
      "php",
      image,
    ];
    execFileSync(
      "docker",
      ["run", "--rm", ...docker, "artisan", "migrate", "--force"],
      { stdio: "pipe", timeout: 120000 },
    );
    execFileSync(
      "docker",
      [
        "run",
        "-d",
        "--name",
        name,
        ...docker,
        "artisan",
        "serve",
        "--host=127.0.0.1",
        "--port=8001",
        "--no-reload",
      ],
      { stdio: "pipe" },
    );
    started = true;
    for (let i = 0; i < 40; i++) {
      try {
        const response = await fetch(appBase + "/up", {
          signal: AbortSignal.timeout(1000),
        });
        if (response.ok) break;
      } catch {}
      if (i === 39) throw Error("Owned diagnostic API readiness failed");
      await new Promise((r) => setTimeout(r, 500));
    }
    const fixture = (mode, run, password) =>
      JSON.parse(
        execFileSync(
          "docker",
          [
            "exec",
            "-w",
            "/app/services/business-api",
            name,
            "php",
            "tests/Fixtures/charger-source-registration.php",
            mode,
            run,
            ...(mode === "seed" ? [password, "diagnostic"] : []),
          ],
          { encoding: "utf8", stdio: ["pipe", "pipe", "pipe"] },
        ),
      );
    const transfer = (url, bytes, authorization) =>
      new Promise((resolve, reject) => {
        assert.equal(new URL(url).origin, origin);
        const request = https.request(
          url,
          {
            method: "PUT",
            ca: cert,
            headers: {
              Authorization: authorization,
              "Content-Type": "application/octet-stream",
              "Content-Length": bytes.length,
            },
            timeout: 15000,
          },
          (response) => {
            const parts = [];
            response.on("data", (x) => parts.push(x));
            response.on("end", () =>
              resolve({
                status: response.statusCode,
                body: Buffer.concat(parts),
              }),
            );
          },
        );
        request.on("timeout", () =>
          request.destroy(Error("Bounded TLS upload timed out")),
        );
        request.on("error", reject);
        request.end(bytes);
      });
    await run({
      base: appBase,
      origin,
      folder,
      fixture,
      transfer,
      counts: () => ({ opsRequests, uploads }),
    });
  } finally {
    server.closeAllConnections?.();
    await new Promise((resolve) => server.close(resolve));
    if (started) {
      execFileSync("docker", ["stop", name], { stdio: "pipe" });
      execFileSync("docker", ["rm", name], { stdio: "pipe" });
    }
    // Keep only this fixture's owned DB/receipts in its hidden private folder.
    // No ephemeral TLS/client credential can be swept into public artifacts.
    for (const file of [
      "tls.key",
      "tls.crt",
      "app.env",
      "trust.pem",
      "trust.ini",
      "native-fixture.json",
    ]) {
      const owned = path.join(folder, file);
      if (fs.existsSync(owned)) fs.unlinkSync(owned);
    }
    // Never remove a volume, another journal or an uncertain command.
  }
};
