const withSource = require("./helpers/ocpi-test-invoice-local.cjs");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path"),
  { spawn } = require("node:child_process");
withSource(async (f) => {
  const dir = mkdtempSync(join(tmpdir(), "ev-ocpi-invoice-native-"));
  try {
    const input = join(dir, "fixture.json");
    writeFileSync(
      input,
      JSON.stringify({
        fixture_only: true,
        base: f.base,
        driver: f.driver,
        other: f.other,
        password: f.password,
        order_id: f.order_id,
      }),
      { mode: 0o600 },
    );
    await new Promise((resolve, reject) => {
      const child = spawn(
        "flutter",
        [
          "test",
          "test/live_ocpi_test_invoice_test.dart",
          "--reporter",
          "expanded",
        ],
        {
          cwd: "apps/mobile",
          stdio: "inherit",
          env: { ...process.env, EV_NATIVE_OCPI_INVOICE_FIXTURE: input },
        },
      );
      child.once("error", reject);
      child.once("exit", (code) =>
        code === 0
          ? resolve()
          : reject(
              Error("Native approved TEST invoice source validation failed"),
            ),
      );
    });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}).catch(() => {
  console.error(
    "Native approved TEST invoice proof failed; private fixture details withheld.",
  );
  process.exitCode = 1;
});
