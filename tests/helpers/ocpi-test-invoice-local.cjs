const { withCredentialsFixture } = require("./ocpi-credentials-local.cjs");
const { BusinessClient } = require("./business-client.cjs");
const { randomUUID } = require("node:crypto");
const assert = require("node:assert/strict");
module.exports = (run) =>
  withCredentialsFixture(
    async (f) => {
      const driver = new BusinessClient({ base: f.base });
      driver.csrf = (await driver.request("csrf")).token;
      await driver.request("login", "POST", {
        email: f.driver,
        password: f.password,
      });
      driver.csrf = (await driver.request("csrf")).token;
      const offer = (
        await driver.request(
          `roaming/locations/${f.location_id}/sandbox-offers`,
        )
      ).offers[0];
      assert(offer);
      const order = await driver.request(
        `roaming/locations/${f.location_id}/sandbox-orders`,
        "POST",
        {
          request_key: randomUUID(),
          evse_uid: offer.evse_uid,
          connector_id: offer.connector_id,
          tariff_id: offer.tariff_id,
          offer_hash: offer.offer_hash,
          terms_version: offer.terms_version,
          consent: true,
        },
      );
      const captured = f.inspect(order.id),
        token = captured.token;
      const peer = async (path, method, body, expected = 200) => {
        const response = await fetch(
          f.base + "/api/roaming-protocol/2.2.1/" + path,
          {
            method,
            headers: {
              Authorization:
                "Token " + Buffer.from(f.local_token).toString("base64"),
              "X-Request-ID": randomUUID(),
              "Content-Type": "application/json",
              Accept: "application/json",
            },
            body: JSON.stringify(body),
            signal: AbortSignal.timeout(15000),
          },
        );
        assert.equal(
          response.status,
          expected,
          "Synthetic CPO protocol receipt failed",
        );
        return response.json();
      };
      const start = new Date(Date.now() - 1000).toISOString(),
        end = new Date().toISOString();
      const session = {
        country_code: "NL",
        party_id: "ABC",
        id: "BILL-" + f.run,
        last_updated: end,
        start_date_time: start,
        end_date_time: end,
        status: "COMPLETED",
        kwh: "1.0",
        currency: "EUR",
        location_id: "L1",
        evse_uid: "E1",
        connector_id: "1",
        total_cost: { excl_vat: "0.25" },
        authorization_reference: token.contract_id,
        cdr_token: Object.fromEntries(
          ["country_code", "party_id", "uid", "type", "contract_id"].map(
            (k) => [k, token[k]],
          ),
        ),
      };
      await peer("sessions/NL/ABC/" + session.id, "PUT", session, 201);
      await peer(
        "cdrs",
        "POST",
        {
          country_code: "NL",
          party_id: "ABC",
          id: "CDR-" + f.run,
          session_id: session.id,
          last_updated: end,
          start_date_time: start,
          end_date_time: end,
          currency: "EUR",
          total_energy: "1.0",
          total_time: "0.001",
          total_cost: session.total_cost,
          cdr_location: { id: "L1", evse_uid: "E1", connector_id: "1" },
          authorization_reference: token.contract_id,
          cdr_token: session.cdr_token,
        },
        201,
      );
      const complete = await driver.request(
        `roaming/sandbox-orders/${order.id}/reconcile`,
        "POST",
        {},
      );
      assert.equal(complete.state, "source_completed");
      assert.equal(complete.reconciliation_state, "source_consistent");
      assert.equal(complete.local_invoice_created, false);
      assert.equal(f.inspect(order.id).financial_invoices, 0);
      await run({
        ...f,
        order_id: order.id,
        driverClient: driver,
        peer,
        sourceSession: session,
      });
    },
    { billing: true },
  );
