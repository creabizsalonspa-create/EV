const { execFileSync } = require("node:child_process");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs");
const { tmpdir } = require("node:os");
const { join } = require("node:path");
// Actual native HTTP client -> local Laravel; no hardware, CSMS or finance writes.
const directory = mkdtempSync(join(tmpdir(), "ev-native-inventory-"));
try {
  const fixture = execFileSync(
    "./scripts/php",
    ["tests/Fixtures/native-inventory.php"],
    { encoding: "utf8" },
  );
  const input = join(directory, "fixture.json");
  writeFileSync(input, fixture, { mode: 0o600 });
  execFileSync(
    "flutter",
    ["test", "test/live_inventory_test.dart", "--reporter", "expanded"],
    {
      cwd: "apps/mobile",
      stdio: "inherit",
      env: { ...process.env, EV_NATIVE_INVENTORY_FIXTURE: input },
    },
  );
} finally {
  rmSync(directory, { recursive: true, force: true });
}
