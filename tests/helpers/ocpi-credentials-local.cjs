// Disposable loopback application with server-only synthetic partner metadata.
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const net = require("node:net");
const assert = require("node:assert/strict");
async function withCredentialsFixture(runTest) {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const probe = net.createServer();
  await new Promise((resolve) => probe.listen(0, "127.0.0.1", resolve));
  const port = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  const name = "ev-ocpi-credentials-" + run;
  const metadata = JSON.stringify({
    ["managed_" + run]: {
      managed_credentials: true,
      origin: "https://partner.example",
      token_env: "OCPI_SYNTHETIC_TOKEN",
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
    "--entrypoint",
    "php",
    "composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc",
  ];
  try {
    const fixture = JSON.parse(
      execFileSync(
        "docker",
        [
          "run",
          "--rm",
          ...args,
          "tests/Fixtures/ocpi-credentials.php",
          run,
          password,
        ],
        { encoding: "utf8" },
      ),
    );
    execFileSync(
      "docker",
      [
        "run",
        "-d",
        "--rm",
        "--name",
        name,
        ...args,
        "artisan",
        "serve",
        "--no-reload",
        "--host=127.0.0.1",
        "--port=" + port,
      ],
      { stdio: "pipe" },
    );
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
    await runTest({ ...fixture, run, password, base });
  } finally {
    try {
      execFileSync("docker", ["rm", "-f", name], { stdio: "pipe" });
    } catch {}
  }
}
module.exports = { withCredentialsFixture };
