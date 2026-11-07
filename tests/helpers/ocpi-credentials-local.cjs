// Disposable loopback application with server-only synthetic partner metadata.
const { execFileSync, spawn } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const net = require("node:net");
const assert = require("node:assert/strict");
async function withCredentialsFixture(
  runTest,
  {
    incoming = false,
    receiving = false,
    roaming = false,
    billing = false,
  } = {},
) {
  if (billing) roaming = true;
  const reuse = process.env.EV_OCPI_REUSE_PHP === "1";
  assert(
    !reuse || roaming,
    "Existing PHP reuse is only supported for guarded roaming fixtures.",
  );
  assert(
    !(incoming && (receiving || roaming)),
    "Receiver fixture requires active synthetic credentials.",
  );
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const probe = net.createServer();
  await new Promise((resolve) => probe.listen(0, "127.0.0.1", resolve));
  const port = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  const name = "ev-ocpi-credentials-" + run;
  let forwarder;
  const channels = new Set();
  const metadata = JSON.stringify({
    ["managed_" + run]: {
      managed_credentials: true,
      ...(receiving || roaming
        ? { incoming_modules: ["sessions", "cdrs"] }
        : {}),
      ...(roaming
        ? {
            test_commands: true,
            test_command_contract: "synthetic_roaming_" + run,
          }
        : {}),
      ...(billing
        ? {
            test_billing_contract: {
              reference: "synthetic_billing_" + run,
              revision: 1,
              command_contract: "synthetic_roaming_" + run,
              company_id: 0,
              country: "NL",
              currency: "EUR",
              minor_units: 2,
              rate_minor_per_kwh: 25,
              tax_bps: 0,
              commission_bps: 1000,
            },
          }
        : {}),
      origin: "https://partner.example",
      ...(incoming
        ? {
            registration_direction: "incoming",
            inbound_token_env: "OCPI_SYNTHETIC_INBOUND_TOKEN",
          }
        : { token_env: "OCPI_SYNTHETIC_TOKEN" }),
      environment: "sandbox",
      party_country: "NL",
      party_id: "ABC",
      party_role: "CPO",
    },
  });
  const local = JSON.stringify({
    url: "https://platform.example/api/roaming-protocol/versions",
    roles: [
      {
        role: "EMSP",
        country_code: "AE",
        party_id: "EVX",
        business_details: { name: "Synthetic Crea-Biz" },
      },
    ],
  });
  const bootstrap = Buffer.from(randomBytes(24).toString("hex")).toString(
    "base64",
  );
  const args = [
    "--network",
    "host",
    "--user",
    `${process.getuid()}:${process.getgid()}`,
    "-v",
    process.cwd() + ":/app",
    "-w",
    "/app/services/business-api",
    "-e",
    "OCPI_CONNECTIONS_JSON=" + metadata,
    "-e",
    "OCPI_LOCAL_CREDENTIALS_JSON=" + local,
    "-e",
    "OCPI_SYNTHETIC_TOKEN=" + bootstrap,
    "-e",
    "OCPI_SYNTHETIC_INBOUND_TOKEN=" + bootstrap,
    "-e",
    "OCPI_TEST_COMMANDS_ENABLED=" + (roaming ? "true" : "false"),
    "-e",
    "OCPI_TEST_BILLING_ENABLED=" + (billing ? "true" : "false"),
    "--entrypoint",
    "php",
    "composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc",
  ];
  const execArgs = () => {
    const env = [];
    for (let i = 0; i < args.length; i++)
      if (args[i] === "-e") env.push("-e", args[++i]);
    return [
      "exec",
      "-u",
      `${process.getuid()}:${process.getgid()}`,
      "-w",
      "/app/services/business-api",
      ...env,
    ];
  };
  try {
    const seed = [
      "tests/Fixtures/ocpi-credentials.php",
      run,
      password,
      incoming
        ? "incoming"
        : billing
          ? "roaming-billing"
          : roaming
            ? "roaming"
            : receiving
              ? "receiver"
              : "active",
    ];
    const fixture = JSON.parse(
      execFileSync(
        "docker",
        reuse
          ? [...execArgs(), "ev-business-api", "php", ...seed]
          : ["run", "--rm", ...args, ...seed],
        { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
      ),
    );
    if (billing) {
      const configured = JSON.parse(metadata);
      configured["managed_" + run].test_billing_contract.company_id =
        fixture.company_id;
      args[
        args.findIndex((value) => value.startsWith("OCPI_CONNECTIONS_JSON="))
      ] = "OCPI_CONNECTIONS_JSON=" + JSON.stringify(configured);
    }
    if (reuse) {
      execFileSync(
        "docker",
        [
          ...execArgs().slice(0, 1),
          "-d",
          ...execArgs().slice(1),
          "ev-business-api",
          "sh",
          "-c",
          `echo $$ > /tmp/ev-roaming-${run}.pid; exec php -S 127.0.0.1:${port} -t public tests/Fixtures/roaming-router.php`,
        ],
        { stdio: "pipe" },
      );
    } else {
      execFileSync(
        "docker",
        [
          "run",
          "-d",
          "--rm",
          "--name",
          name,
          ...args,
          ...(roaming
            ? [
                "-S",
                "127.0.0.1:" + port,
                "-t",
                "public",
                "tests/Fixtures/roaming-router.php",
              ]
            : [
                "artisan",
                "serve",
                "--no-reload",
                "--host=127.0.0.1",
                "--port=" + port,
              ]),
        ],
        { stdio: "pipe" },
      );
    }
    if (reuse) {
      // The existing application uses Docker bridge networking. An owned raw TCP
      // relay keeps the fixture loopback-only without cloning another VFS image,
      // changing the application listener, or logging request credentials.
      forwarder = net.createServer((socket) => {
        const relay = spawn(
          "docker",
          ["exec", "-i", "ev-business-api", "php", "tests/Fixtures/roaming-relay.php", String(port)],
          { stdio: ["pipe", "pipe", "ignore"] },
        );
        const channel = { socket, relay };
        channels.add(channel);
        socket.on("error", () => {});
        relay.stdin.on("error", () => {});
        relay.on("error", () => socket.destroy());
        socket.pipe(relay.stdin);
        relay.stdout.pipe(socket);
        socket.on("close", () => {
          relay.stdin.end();
          relay.kill();
          channels.delete(channel);
        });
        relay.on("close", () => {
          socket.destroy();
          channels.delete(channel);
        });
      });
      await new Promise((resolve, reject) => {
        forwarder.once("error", reject);
        forwarder.listen(port, "127.0.0.1", resolve);
      });
    }
    const base = "http://127.0.0.1:" + port;
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt++) {
      try {
        if ((await fetch(base + "/up")).ok) {
          ready = true;
          break;
        }
      } catch {}
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
    assert(ready, "Synthetic credentials server did not become ready.");
    const inspect = roaming
      ? (orderId) =>
          JSON.parse(
            execFileSync(
              "docker",
              [
                ...(reuse
                  ? [...execArgs(), "ev-business-api"]
                  : ["exec", name]),
                "php",
                "tests/Fixtures/roaming-source.php",
                run,
                ...(orderId ? [String(orderId)] : []),
              ],
              { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
            ),
          )
      : undefined;
    await runTest({ ...fixture, run, password, base, incoming, inspect });
  } catch (error) {
    if (error?.spawnargs || error?.cmd || error?.stderr)
      throw new Error(
        "Synthetic fixture process failed; credential details withheld.",
      );
    throw error;
  } finally {
    for (const { socket, relay } of channels) {
      socket.destroy();
      relay.kill();
    }
    if (forwarder) await new Promise((resolve) => forwarder.close(resolve));
    try {
      if (reuse)
        execFileSync(
          "docker",
          [
            "exec",
            "ev-business-api",
            "php",
            "tests/Fixtures/stop-roaming-server.php",
            run,
            String(port),
          ],
          { stdio: "pipe" },
        );
      else execFileSync("docker", ["rm", "-f", name], { stdio: "pipe" });
    } catch {}
  }
}
module.exports = { withCredentialsFixture };
