const withSource = require("./helpers/ocpi-credit-local.cjs");
const { mkdtempSync, writeFileSync, rmSync } = require("node:fs"),
  { tmpdir } = require("node:os"),
  { join } = require("node:path"),
  { spawn } = require("node:child_process");
withSource(async (f) => {
  const dir = mkdtempSync(join(tmpdir(), "ev-ocpi-credit-native-"));
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
        invoice_id: f.invoice.id,
        credit_id: f.credit.id,
        owner: f.owner,
      }),
      { mode: 0o600 },
    );
    await new Promise((resolve, reject) => {
      const child = spawn(
        "flutter",
        [
          "test",
          "test/live_ocpi_credit_picker_test.dart",
          "--reporter",
          "expanded",
        ],
        {
          cwd: "apps/mobile",
          stdio: "inherit",
          env: { ...process.env, EV_NATIVE_OCPI_CREDIT_FIXTURE: input },
        },
      );
      child.once("error", reject);
      child.once("exit", (code) =>
        code === 0
          ? resolve()
          : reject(Error("Native scoped TEST credit source validation failed")),
      );
    });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}).catch(() => {
  console.error(
    "Native scoped TEST credit proof failed; private fixture details withheld.",
  );
  process.exitCode = 1;
});
