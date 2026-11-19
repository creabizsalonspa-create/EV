"use strict";

// Synthetic current-slot fixtures need enough real time for their complete
// protocol/authentication workflow. Never move the clock or extend a booking.
async function waitForReservationFixtureWindow({
  now = Date.now,
  wait = (milliseconds) =>
    new Promise((resolve) => setTimeout(resolve, milliseconds)),
  log = console.log,
} = {}) {
  const slot = 30 * 60 * 1000;
  for (;;) {
    const current = now();
    if (!Number.isSafeInteger(current) || current < 0)
      throw new Error(
        "Invalid synthetic reservation clock; no fixture created.",
      );
    const remaining = slot - (current % slot);
    if (remaining >= 3 * 60 * 1000) return;
    log(
      "Waiting for a complete synthetic reservation window before creating a fresh fixture.",
    );
    await wait(Math.min(30000, remaining + 1));
  }
}

module.exports = { waitForReservationFixtureWindow };
