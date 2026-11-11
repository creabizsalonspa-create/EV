"use strict";

// Test-only, loopback-only readiness probe after the synthetic diagnostic
// setup changes Laravel's .env and may restart artisan serve. Repeated GET
// /up checks are safe; uncertain business mutations are never replayed.
const assert = require("node:assert/strict");

const HEALTH_URL = "http://127.0.0.1:8000/up";

async function waitForDiagnosticServerReady({
  fetchImpl = globalThis.fetch,
  sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  attempts = 30,
  spacingMs = 500,
} = {}) {
  assert(Number.isInteger(attempts) && attempts >= 2 && attempts <= 60);
  assert(Number.isInteger(spacingMs) && spacingMs >= 0 && spacingMs <= 1000);

  let consecutiveOk = 0;
  for (let i = 0; i < attempts; i++) {
    try {
      const response = await fetchImpl(HEALTH_URL, {
        method: "GET",
        redirect: "error",
        headers: { Accept: "text/plain" },
        signal: AbortSignal.timeout(2000),
      });
      consecutiveOk = response.status === 200 ? consecutiveOk + 1 : 0;
      if (consecutiveOk >= 2) return;
    } catch {
      // A request interrupted by the development server restart is not a
      // charging/payment/diagnostic operation. Never log a token or URL from
      // a transport exception; only probe this fixed public health endpoint.
      consecutiveOk = 0;
    }
    if (i < attempts - 1) await sleep(spacingMs);
  }

  throw new Error(
    "Synthetic diagnostic loopback /up did not become stable after local setup; " +
    "no business mutation was replayed",
  );
}

module.exports = { HEALTH_URL, waitForDiagnosticServerReady };
