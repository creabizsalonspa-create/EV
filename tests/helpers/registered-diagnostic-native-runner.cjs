"use strict";
const { spawn } = require("node:child_process");
const fs = require("node:fs");

// Keep the TLS facade's event loop alive while the connected client runs.
// A synchronous child blocks local source and upload responses.
async function runNativeDiagnostics({ file, marker, command = "flutter", args = [
  "test", "--no-pub", "test/live_authenticated_charger_logs_test.dart", "--reporter", "expanded",
], cwd = "apps/mobile", timeout = 300000 }) {
  if (fs.existsSync(marker)) throw new Error("Unexpected preexisting native diagnostic marker");
  await new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd, stdio: "inherit", timeout, killSignal: "SIGKILL",
      env: { ...process.env, EV_NATIVE_AUTHENTICATED_LOGS_FIXTURE: file },
    });
    child.once("error", () => reject(new Error("Native diagnostic client failed to start")));
    child.once("close", (code, signal) => {
      if (code !== 0 || signal) reject(new Error("Connected native diagnostic client failed"));
      else resolve();
    });
  });
  if (!fs.existsSync(marker)) throw new Error("Connected native diagnostic acceptance marker missing");
}
module.exports = { runNativeDiagnostics };
