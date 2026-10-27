# Local charging protocol integration

This module exercises **simulated OCPP chargers against the separately running CitrineOS v2.1.0 service**. It does not operate physical equipment, prove hardware compatibility, or establish live charging/payment readiness.

## Reproduce

From the repository root:

```sh
./scripts/csms-setup
./scripts/csms-start
./scripts/charging-setup
node tests/charging-session.cjs
```

Node 24 supplies the WebSocket client. The additional charging overlay starts digest-pinned Hasura v2.40.3 on **host loopback port 8095 only**, using the existing CSMS PostGIS service. It does not connect to the business database. `scripts/charging-setup` generates a random local Hasura administrator secret only when its ignored `.env` is absent, preserves existing credentials, verifies health, and registers the selected CSMS tables through Hasura's metadata API. Tests provision local synthetic authorizations and inspect results through documented GraphQL APIs; they do not issue SQL or import CSMS tables into Laravel.

The CSMS test configuration uses localDev API tokens and OCPP security profile 0 on loopback. These are local simulator settings, **not staging or production configuration**. Real rollout requires appropriate service authentication, token/tenant scopes, TLS/mTLS and explicitly authorized hardware provisioning. Local administrator secrets must remain out of Git and logs. The setup does not delete named volumes or existing station/transaction records.

## Verified protocol subset

`tests/charging-session.cjs` performs the following independently for OCPP 1.6 and OCPP 2.0.1:

1. Provision a generated local test authorization through the CSMS GraphQL API.
2. Connect a generated `ev-test-*` simulator, send BootNotification and StatusNotification, and require Accepted authorization.
3. Submit an authenticated CSMS remote-start command, verify that the simulated charger receives it, and return the supported Accepted protocol result.
4. Start a protocol transaction with a 1,000 Wh cumulative baseline, then send a 2,500 Wh periodic reading.
5. Disconnect, verify the station's recorded offline state, reconnect and verify boot/heartbeat processing.
6. Submit remote stop, verify receipt by the same simulator, and end the protocol transaction at 3,500 Wh.
7. Poll for the combined authoritative final state: inactive transaction, end timestamp and **2.5 kWh** reconciled cumulative delta. Transaction completion and final-meter processing are asynchronous, so an inactive flag alone is insufficient.
8. Send Faulted and recovery Available status, then verify both states are persisted for that station.
9. Require persisted protocol meter evidence rather than only successful message acknowledgments.

Only generated local simulator identifiers and the two declared protocol versions are accepted by the helper. All HTTP/WebSocket endpoints are fixed to loopback. Timeouts and bounded convergence checks fail the test if the expected state does not arrive.

The test repeats the identical OCPP 2.0.1 periodic sequence event. **Observed upstream behavior:** the transaction's cumulative final energy remains 2.5 kWh, but an additional periodic MeterValues row is retained. Output reports `duplicate_periodic_meter_rows_observed` explicitly. This does not prove full upstream event deduplication. Downstream processing must use a stable source identity/sequence and cumulative-meter semantics; it must never sum duplicate cumulative readings or bill per stored meter row.

## Evidence and limits

The successful protocol-run output is retained in `docs/evidence/charging-protocol-sessions.txt` when the combined check completes successfully. Each output row labels its evidence `SIMULATED_PROTOCOL_SESSION` and records the protocol, generated station/transaction, start/stop receipt, reconnect/fault checks, final energy and persisted-meter count. An empty or incomplete evidence file is not a passed run.

These protocol tests do not by themselves establish Laravel-to-CSMS transaction correlation, event-inbox authenticity/deduplication, final business invoice/payment reconciliation, physical charger safety, complete OCPP conformance, diagnostics/firmware controls, or production operational resilience. The Laravel business bridge and its separate end-to-end evidence must be verified independently before claiming the entire customer/vendor charging-to-invoice workflow.

## Correlated business-to-CSMS-to-invoice test

With the current local business service and CSMS running:

```sh
./scripts/charging-bridge-setup
node tests/charging-business.cjs
```

`tests/charging-business.cjs` is a controlled integration driver for both protocols. It creates synthetic customer/vendor/admin accounts using the real web authentication/CSRF flow, provisions simulator station/binding/tariff records through authorized APIs, then uses the development-only `platform:charging-fixture` command for a current-slot reservation. The fixture avoids waiting up to 30 minutes for a future public slot; **it is not evidence that the public booking UI and elapsed-time gate were exercised in this same run**. The existing independent browser tests cover public reservation creation/cancellation.

For each protocol the driver requests a canonical business session, confirms start-command idempotency, provisions the resulting `EV-SIM-<session-id>` authorization through local GraphQL, connects the simulated charger and dispatches the actual Laravel outbox command. It requires the received token to match the requested business session. It then starts the real CSMS protocol transaction, reads its persisted start/time fields, reads persisted periodic/end meter observations, and forwards those observations as signed canonical events through the business service's authenticated inbox. CitrineOS `Transactions.meterStart` is expressed in kWh and is explicitly normalized to integer Wh; stored sampled meter values declare their Wh unit.

The test verifies unsigned-event rejection, exact event replay deduplication and changed-payload replay conflict. It queues/dispatches one idempotent remote-stop command and waits for the actual CSMS transaction's inactive/end-time/2.5 kWh state before forwarding the source end event. The resulting business session is stopped at 2,500 Wh and the captured 125-minor-unit/kWh tariff produces a half-up rounded subtotal of **313**, test tax **16**, and exactly one **329 AED-minor-unit SIMULATED invoice**. Customer/vendor/admin session views agree. One simulated payment and full simulated refund, including retries, reconcile to zero net collection/due and a balanced immutable ledger. No PSP, card capture, payout or real refund is involved.

The passed output is retained in `docs/evidence/charging-business-sessions.txt`. This demonstrates the controlled backend orchestration and source-to-business event boundary. The Node test driver is not a deployed continuous event consumer. Production polling/subscription delivery, hardware source trust/provisioning, replay recovery after outages, complete metering policies, final PSP/partner reconciliation and client UI routing to the new CSMS session endpoints still require implementation/acceptance. Legacy independent application-simulator buttons must not be treated as the new correlated CSMS control flow.

## Diagnosed test/environment failures

- An early test asserted energy immediately when the transaction first became inactive. Actual GraphQL observations showed final energy converging asynchronously afterward. The bounded final-state poll now requires inactive + end timestamp + reconciled energy together; the energy assertion remains enforced.
- During this continuation disk exhaustion prevented command startup and interrupted protocol requests (including a boot timeout). Root retained/moved only task-owned regenerable toolchain/cache content; no CSMS data or credentials were removed. After filesystem recovery, RabbitMQ diagnostics reported no local/cluster alarms and fresh protocol and correlated business runs passed. Disk/resource monitoring and interrupted-session recovery remain operational release requirements.
- A first correlated-test attempt incorrectly interpreted upstream `meterStart` as Wh; actual source observations showed kWh. The explicit unit conversion was corrected before a successful business run. Source units must never be inferred from a field name alone.
