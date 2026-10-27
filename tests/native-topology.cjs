const { execFileSync } = require("node:child_process"),
  { randomBytes } = require("node:crypto"),
  { mkdtempSync, writeFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path");
const directory = mkdtempSync(join(tmpdir(), "ev-native-topology-"));
try {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/canonical-topology.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const input = join(directory, "fixture.json");
  writeFileSync(
    input,
    JSON.stringify({
      ...fixture,
      owner: `canonical-owner-${run}@example.test`,
      customer: `canonical-customer-${run}@example.test`,
      password,
      fixture_only: true,
    }),
    { mode: 0o600 },
  );
  execFileSync(
    "flutter",
    ["test", "test/live_topology_test.dart", "--reporter", "expanded"],
    {
      cwd: "apps/mobile",
      stdio: "inherit",
      env: { ...process.env, EV_NATIVE_TOPOLOGY_FIXTURE: input },
    },
  );
} finally {
  rmSync(directory, { recursive: true, force: true });
}
