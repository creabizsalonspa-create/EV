// Isolated owned OCPI TEST invoice and mock funds, never a live partner or PSP.
const withSource = require("./ocpi-test-invoice-local.cjs");
const { randomUUID } = require("node:crypto");
const assert = require("node:assert/strict");
module.exports = (run, { paid = true } = {}) =>
  withSource(async (f) => {
    const preview = await f.driverClient.request(
      `roaming/sandbox-orders/${f.order_id}/test-invoice-preview`,
    );
    const consent = {
      request_key: randomUUID(),
      preview_token: preview.preview_token,
      terms_version: preview.terms_version,
      consent: true,
      expected_total_minor: preview.total_minor,
      expected_currency: preview.currency,
      expected_minor_units: preview.minor_units,
      expected_energy_wh: preview.energy_wh,
    };
    const invoice = (
      await f.driverClient.request(
        `roaming/sandbox-orders/${f.order_id}/test-invoice`,
        "POST",
        consent,
      )
    ).invoice;
    const session = f.sourceSession;
    const external = "CREDIT-" + f.run;
    await f.peer(
      "cdrs",
      "POST",
      {
        country_code: "NL",
        party_id: "ABC",
        id: external,
        credit: true,
        credit_reference_id: "CDR-" + f.run,
        session_id: session.id,
        last_updated: session.last_updated,
        start_date_time: session.start_date_time,
        end_date_time: session.end_date_time,
        currency: "EUR",
        total_energy: "1.0",
        total_time: "0.001",
        total_cost: session.total_cost,
        cdr_location: { id: "L1", evse_uid: "E1", connector_id: "1" },
        cdr_token: session.cdr_token,
        authorization_reference: session.authorization_reference,
      },
      201,
    );
    const optionsPath = `finance/invoices/${invoice.id}/source-credits`;
    const options = await f.driverClient.request(optionsPath);
    assert.equal(options.data.length, 1);
    const credit = options.data[0];
    assert.equal(credit.external_id, external);
    if (paid) {
      const payment = await f.driverClient.request(
        `finance/invoices/${invoice.id}/simulate-payment`,
        "POST",
        { amount_minor: 25, idempotency_key: randomUUID() },
      );
      assert.equal(payment.evidence, "SIMULATED");
    }
    await run({ ...f, invoice, credit, sourceCreditsPath: optionsPath });
  });
