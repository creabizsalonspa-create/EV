// Owned disposable PHP/SQLite and synthetic PKI. No normal database or .env changes.
const fs = require("node:fs"),
  path = require("node:path"),
  net = require("node:net");
const { execFileSync } = require("node:child_process");
const { randomBytes, X509Certificate } = require("node:crypto");
module.exports = async (runTest) => {
  const run = randomBytes(6).toString("hex"),
    name = "ev-cert-fixture-" + run;
  const relative = `services/business-api/storage/app/private/charger-certificates/test-${run}`;
  const directory = path.resolve(relative),
    oldMask = process.umask(0o077);
  fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
  const file = (name) => path.join(directory, name);
  const openssl = (args) => execFileSync("openssl", args, { stdio: "ignore" });
  let started = false;
  try {
    fs.writeFileSync(
      file("extensions.cnf"),
      "[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1\n[issuer]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n",
      { mode: 0o600 },
    );
    openssl([
      "req",
      "-x509",
      "-newkey",
      "rsa:2048",
      "-nodes",
      "-keyout",
      file("root.key"),
      "-out",
      file("root.pem"),
      "-days",
      "10",
      "-sha256",
      "-subj",
      "/CN=Synthetic test root",
      "-addext",
      "basicConstraints=critical,CA:TRUE",
      "-addext",
      "keyUsage=critical,keyCertSign,cRLSign",
    ]);
    const issue = (identity, label, purpose, signer = "root") => {
      openssl([
        "req",
        "-newkey",
        "rsa:2048",
        "-nodes",
        "-keyout",
        file(label + ".key"),
        "-out",
        file(label + ".csr"),
        "-subj",
        "/CN=" + identity,
      ]);
      openssl([
        "x509",
        "-req",
        "-in",
        file(label + ".csr"),
        "-CA",
        file(signer + ".pem"),
        "-CAkey",
        file(signer + ".key"),
        "-set_serial",
        "0x" + randomBytes(12).toString("hex"),
        "-out",
        file(label + ".pem"),
        "-days",
        purpose === "issuer" ? "7" : "3",
        "-sha256",
        "-extfile",
        file("extensions.cnf"),
        "-extensions",
        purpose,
      ]);
      return {
        pem: fs.readFileSync(file(label + ".pem"), "utf8"),
        key: fs.readFileSync(file(label + ".key")),
      };
    };
    const server = issue("localhost", "server", "server"),
      password = randomBytes(24).toString("hex") + "aA9!",
      secret = randomBytes(32).toString("hex");
    const intermediate =
      process.env.EV_CERTIFICATE_INTERMEDIATE_CHAIN === "1"
        ? issue("Synthetic model issuer", "issuer", "issuer")
        : null;
    const env = {
      APP_ENV: "local",
      APP_DEBUG: "false",
      APP_KEY: "base64:" + randomBytes(32).toString("base64"),
      DB_CONNECTION: "sqlite",
      DB_URL: "",
      DB_DATABASE: "/app/" + relative + "/certificate_review.sqlite",
      SESSION_DRIVER: "database",
      CACHE_STORE: "database",
      QUEUE_CONNECTION: "sync",
      MAIL_MAILER: "array",
      LOG_CHANNEL: "stderr",
      CSMS_CERTIFICATE_MANAGEMENT_ENABLED: "true",
      CSMS_CERTIFICATE_ISSUER_BUNDLE: "/app/" + relative + "/root.pem",
      CSMS_CERTIFICATE_ISSUER_PINS: new X509Certificate(
        fs.readFileSync(file("root.pem")),
      ).fingerprint256
        .replaceAll(":", "")
        .toLowerCase(),
      CSMS_INGRESS_AUTH_ENABLED: "true",
      CSMS_INGRESS_ALLOW_LOCAL_HTTP: "true",
      CSMS_INGRESS_AUTH_SECRET: secret,
      EV_CERT_FIXTURE_PASSWORD: password,
    };
    fs.writeFileSync(
      file("runtime.env"),
      Object.entries(env)
        .map(([k, v]) => k + "=" + v + "\n")
        .join(""),
      { mode: 0o600 },
    );
    fs.writeFileSync(file("certificate_review.sqlite"), "", { mode: 0o600 });
    const args = [
      "--network",
      "host",
      "--user",
      `${process.getuid()}:${process.getgid()}`,
      "--mount",
      `type=bind,src=${process.cwd()},dst=/app`,
      "--env-file",
      file("runtime.env"),
      "-w",
      "/app/services/business-api",
    ];
    if (process.env.EV_CERTIFICATE_VENDOR_PATH) {
      const vendor = fs.realpathSync(process.env.EV_CERTIFICATE_VENDOR_PATH);
      if (
        !vendor.startsWith("/workspace/") ||
        !vendor.endsWith("/services/business-api/vendor")
      )
        throw Error("Invalid local vendor fixture path");
      args.push(
        "--mount",
        `type=bind,src=${vendor},dst=/app/services/business-api/vendor,readonly`,
      );
    }
    args.push(
      "--entrypoint",
      "php",
      "composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc",
    );
    const php = (command) =>
      execFileSync("docker", ["run", "--rm", ...args, ...command], {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "pipe"],
      });
    php(["artisan", "migrate", "--force", "--no-interaction"]);
    const fixture = JSON.parse(
      php(["tests/Fixtures/charger-certificates.php", run]),
    );
    const probe = net.createServer();
    await new Promise((r) => probe.listen(0, "127.0.0.1", r));
    const port = probe.address().port;
    await new Promise((r) => probe.close(r));
    execFileSync(
      "docker",
      [
        "run",
        "-d",
        "--rm",
        "--name",
        name,
        ...args,
        "-S",
        "127.0.0.1:" + port,
        "-t",
        "public",
        "tests/Fixtures/certificate-router.php",
      ],
      { stdio: "ignore" },
    );
    started = true;
    const base = "http://127.0.0.1:" + port;
    let ready = false;
    for (let n = 0; n < 60; n++) {
      try {
        if (
          (await fetch(base + "/up", { signal: AbortSignal.timeout(1500) })).ok
        ) {
          ready = true;
          break;
        }
      } catch {}
      await new Promise((r) => setTimeout(r, 100));
    }
    if (!ready)
      throw Error("Owned certificate HTTP fixture did not become ready");
    for (const [index, charger] of fixture.chargers.entries())
      Object.assign(
        charger,
        issue(
          charger.identity,
          "client" + index,
          "client",
          intermediate ? "issuer" : "root",
        ),
        { issuer_chain: intermediate?.pem || "" },
      );
    await runTest({
      ...fixture,
      base,
      password,
      secret,
      server,
      root: fs.readFileSync(file("root.pem")),
      directory,
      run,
      inspect: () =>
        JSON.parse(
          php(["tests/Fixtures/charger-certificates.php", run, "counts"]),
        ),
    });
  } finally {
    if (started) {
      try {
        execFileSync("docker", ["rm", "-f", name], { stdio: "ignore" });
      } catch {}
    }
    fs.rmSync(directory, { recursive: true, force: true });
    process.umask(oldMask);
  }
};
