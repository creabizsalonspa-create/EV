// Reuse the already completed dual-protocol source sessions. Never start charging.
const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
module.exports = async (fixture, clients) => {
  const eligible = await clients.customer.request("charging-reviews/eligible");
  for (const station of fixture.stations) {
    const source = eligible.data.find(
      (r) => r.station_id === station.station_id || r.station_id === station.id,
    );
    assert(
      source && source.can_review,
      "Completed source session must qualify once",
    );
    assert.equal(source.evidence, "SIMULATED_SOURCE_OBSERVED");
    const body = {
      request_key: randomUUID(),
      rating: 4,
      body: "Observed simulator charging feedback; physical operation remains unverified.",
      consent: true,
      terms_version: eligible.terms_version,
    };
    const row = await clients.customer.request(
      `charging-reviews/sessions/${source.session_id}`,
      "POST",
      body,
    );
    assert.equal(row.evidence, "SIMULATED_SOURCE_OBSERVED");
    const published = await clients.admin.request(
      `charging-reviews/${row.id}/moderate`,
      "POST",
      {
        request_key: randomUUID(),
        expected_version: row.version,
        action: "publish",
        reason: "Independent review of controlled simulator feedback",
      },
    );
    const visible = await clients.customer.request(
      `stations/${source.station_id}/reviews`,
    );
    assert.equal(visible.groups[0].count, 1);
    assert.equal(visible.groups[1].count, 0);
    assert.equal(visible.physical_charging_verified, false);
    assert(!JSON.stringify(visible).includes("session_id"));
    assert(!JSON.stringify(visible).includes("user_id"));
    await clients.customer.request(`charging-reviews/${row.id}`, "DELETE", {
      request_key: randomUUID(),
      expected_version: published.version,
    });
    assert.equal(
      (await clients.customer.request(`stations/${source.station_id}/reviews`))
        .total,
      0,
    );
  }
  console.log(
    "PASS already-completed OCPP1.6/2.0.1 canonical source -> consented feedback, separate anonymous source-rating groups, independent moderation and withdrawal. No charging replay or financial mutation.",
  );
};
