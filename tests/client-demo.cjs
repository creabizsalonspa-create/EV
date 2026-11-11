// Focused persistent simulator proof. Credentials remain in the private local manifest.
const fs = require("node:fs");
const path = require("node:path");
const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { randomBytes, randomUUID } = require("node:crypto");
const { execFileSync } = require("node:child_process");
const rfid = process.argv.includes("--rfid");
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function waitFor(fn, message) {
  const deadline = Date.now() + 120000;
  do {
    const result = await fn();
    if (result) return result;
    await sleep(3000);
  } while (Date.now() < deadline);
  throw Error(message);
}
let activeFixture;
let activeStation;
async function main() {
  const base = path.resolve("artifacts/client-demo");
  const file = fs.realpathSync(
    process.env.EV_CLIENT_DEMO_MANIFEST ||
      JSON.parse(fs.readFileSync(path.join(base, "current.json"))).manifest,
  );
  assert(
    file.startsWith(base + path.sep) && (fs.statSync(file).mode & 0o077) === 0,
  );
  const fixture = JSON.parse(fs.readFileSync(file));
  assert(
    fixture.fixture_only && !fixture.real_money && fixture.qa_current_bookings,
  );
  const clients = {};
  for (const role of ["customer", "vendor", "admin"]) {
    const client = new BusinessClient();
    client.csrf = (await client.request("csrf")).token;
    await client.request("login", "POST", fixture.accounts[role]);
    client.csrf = (await client.request("csrf")).token;
    clients[role] = client;
  }
  const customer = clients.customer;
  activeFixture = file;
  for (const station of fixture.stations) {
    activeStation = station;
    const device = JSON.parse(
      fs.readFileSync(
        path.join(
          path.dirname(file),
          "status-" + station.protocol.replaceAll(".", "") + ".json",
        ),
      ),
    );
    assert.equal(device.state, "idle");
    let card;
    if (rfid) {
      const type = station.protocol === "1.6" ? "ISO14443" : "Central";
      card = await customer.request("rider-access/tokens", "POST", {
        label: "Private persistent simulator card",
        token_type: type,
        ...(type === "ISO14443"
          ? { value: randomBytes(8).toString("hex").toUpperCase() }
          : {}),
        expires_at: new Date(Date.now() + 86400000)
          .toISOString()
          .replace(/\.\d+Z$/, "Z"),
        idempotency_key: randomUUID(),
      });
      const grant = await customer.request(
        "rider-access/tokens/" + card.token.id + "/grants",
        "POST",
        { station_id: station.station_id },
      );
      await clients.vendor.request(
        "vendor-ops/companies/" +
          fixture.company_id +
          "/rider-grants/" +
          grant.id +
          "/review",
        "POST",
        {
          state: "approved",
          expires_at: new Date(Date.now() + 3600000)
            .toISOString()
            .replace(/\.\d+Z$/, "Z"),
          note: "Synthetic local charging proof",
        },
      );
      await clients.vendor.request(
        "vendor-ops/companies/" +
          fixture.company_id +
          "/rider-tokens/" +
          card.token.id +
          "/provision",
        "POST",
        {},
      );
      execFileSync("./scripts/client-demo", ["swipe", station.protocol, type], {
        input: card.value_once,
        stdio: ["pipe", "ignore", "pipe"],
        env: { ...process.env, EV_CLIENT_DEMO_MANIFEST: file },
      });
      await waitFor(
        async () =>
          (await customer.request("simulations")).data.some(
            (row) => row.reservation_id === station.reservation_id,
          ),
        "Online rider authorization did not create a scoped session.",
      );
    }
    const pending = await customer.request(
      "reservations/" + station.reservation_id + "/csms-start",
      "POST",
    );
    if (rfid) assert.equal(pending.start_command, null);
    else assert.equal(pending.session.status, "pending_start");
    const id = pending.session.id;
    const session = async () =>
      (await customer.request("simulations")).data.find((row) => row.id === id);
    await waitFor(async () => {
      const row = await session();
      return row?.status === "charging" && row.energy_wh > 0 && row;
    }, "Live source meter did not converge.");
    if (rfid)
      await customer.request("rider-access/tokens/" + card.token.id, "DELETE");
    const stopping = await customer.request(
      "charging-sessions/" + id + "/csms-stop",
      "POST",
    );
    assert.equal(stopping.session.status, "stopping");
    const stopped = await waitFor(async () => {
      const row = await session();
      return row?.status === "stopped" && row;
    }, "Source stop did not converge.");
    assert(stopped.energy_wh > 0);
    assert.equal(stopped.start_command_status, rfid ? null : "source_observed");
    assert.equal(
      stopped.authorization_method,
      rfid ? "rider_token" : "remote_start",
    );
    assert.equal(stopped.stop_command_status, "source_observed");
    const invoices = (await customer.request("finance/invoices")).data.filter(
      (row) => row.charging_session_id === id,
    );
    assert.equal(invoices.length, 1);
    const summary = await customer.request(
      "finance/invoices/" + invoices[0].id,
    );
    assert.equal(summary.invoice.energy_wh, stopped.energy_wh);
    assert.equal(summary.ledger_balanced, true);
    assert.equal(summary.real_payment_processed, false);
    for (const role of ["vendor", "admin"]) {
      const row = (await clients[role].request("simulations")).data.find(
        (row) => row.id === id,
      );
      assert(
        row && row.status === "stopped" && row.energy_wh === stopped.energy_wh,
      );
    }
    const journal = JSON.parse(
      fs.readFileSync(
        path.join(
          path.dirname(file),
          "device-" + station.protocol.replaceAll(".", "") + ".json",
        ),
      ),
    );
    assert.equal(journal.phase, "idle");
    console.log(
      JSON.stringify({
        protocol: station.protocol,
        authorization: rfid ? "managed_rfid" : "managed_remote",
        source_meter_stop_invoice: true,
        scoped_visibility: true,
        physical_charging_verified: false,
        real_payment_processed: false,
      }),
    );
  }
  await require("./helpers/operational-report-proof.cjs")(fixture, clients);
  // Same completed sessions and identities; do not repeat charging for feedback proof.
  await require("./helpers/charging-review-source-proof.cjs")(fixture, clients);
}
main().catch((error) => {
  if (activeFixture && activeStation) {
    try {
      console.error(
        JSON.stringify(
          require("./helpers/client-demo-failure.cjs").capture(
            activeFixture,
            activeStation,
          ),
        ),
      );
    } catch {
      console.error(
        "Owned failure diagnostic unavailable; private source state retained.",
      );
    }
  }
  console.error(error.message);
  console.error(
    "Persistent local charging proof failed; inspect private source/operator state without replaying unknown commands.",
  );
  process.exitCode = 1;
});
