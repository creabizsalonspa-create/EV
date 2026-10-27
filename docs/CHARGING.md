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

## Continuous local simulator source consumer

The canonical Laravel `SourceConsumer` now reads the authenticated, documented Hasura projection and forwards observations through the HMAC-signed event inbox. It never reads CSMS database tables directly or dispatches/replays commands. Run `scripts/charging-setup`, then `scripts/charging-bridge-setup` (preserves existing secrets and binds the private GraphQL key), then `scripts/csms-consume --interval=2`. Use `--once --link=<business-link-id>` for a bounded reconciliation pass. The wrapper uses host loopback networking and Docker init for reliable signal forwarding; PHP in the pinned image lacks pcntl. A local file lock permits one observer per shared checkout. A service manager must restart it after outages; processes are not snapshot state.

Only provisioned ev-test identifiers and non-production simulator mode are accepted. Tenant/station/unique EV-SIM session authorization and OCPP 2.0.1 remote-start identity correlate source transactions. Ambiguity, unsupported precision/units, changed correlation and inbox conflicts fail without inventing energy. Source meter IDs and a separate durable scan cursor prevent replay and starvation on non-energy records. Wh/kWh conversion is exact; unsupported multipliers/phase ambiguity require reconciliation. End is forwarded only when inactive/end-time/final-register and source total energy agree. Lost acknowledgement after commit is safely retried using the same event identity; invoice uniqueness/immutability remains authoritative.

Executed both protocols with `EV_CSMS_CONSUMER=1 EV_CSMS_DAEMON=1 node tests/charging-business.cjs`: actual continuous process, restart during charging, source meters/end to exactly one 329-minor-unit simulated invoice, payment/refund and balanced ledger. The protocol fixtures still explicitly provision synthetic authorization and dispatch authorized outbox commands. The observer does not automate those steps. Production source identity/JWT/mTLS, distributed scheduling/locks, terminal reconciliation/admin recovery, command provisioning/dispatch daemon and physical metering policies remain unfinished. Never auto-retry unknown start outcomes.

Shared clients now route bound reservations to csms-start, linked stops to csms-stop, hide manual meter overrides and label source-wait states. Stopped sessions can open a read-only simulation invoice. No physical charging, PSP or legal tax-document acceptance is implied.

## Managed-company admission and safe cancellation

Existing vendor_sites.station_id links now enforce company approval/local-license validity and one matching simulator connector for new bookings and starts. Maintenance or ambiguous/mismatched/unverified inventory blocks admission; multiple connector targets require an explicit future mapping. CSMS claims recheck the current company policy and reservation window. A blocked pending command has not been dispatched; claim authorization cannot recall an already in-flight/uncertain command. Stops/source meters/finalization remain allowed.

Migration090001 adds nullable closed_without_dispatch_at/closure_reason, preserving existing links. Only a locked pending start with no source transaction/event/energy may be cancelled through the owned reservation DELETE. Its command/session/reservation become cancelled, its link closes without a source ended_at, and subsequent starts can own the binding. Observers skip closed requests; authenticated unexpected activity is rejected for reconciliation. Dispatching/submitted/rejected/uncertain starts cannot be silently cancelled or retried. Known-unsent local simulator expiry is now implemented as documented below; reconciliation of admitted/uncertain requests and real token revocation remain unfinished. Apply migrations before starting updated workers; coordinate code/schema rollback and preserve data.

## Bounded known-unsent expiry

Apply migration `2026_10_27_100001_create_charging_request_expirations` before updated scheduler workers. It adds a separate operational evidence table; no finance schema is changed. Run `./scripts/php artisan csms:expire-unsent --limit=100` for a bounded pass, or add `--reservation=<id>` for one synthetic request. Invalid limits/IDs fail. Only enabled non-production session-bridge simulators with unchanged matching `ev-test-*` bindings are eligible. Pending-before-dispatch proof is rechecked under locks at the exact half-hour window end. Contradictory source/energy/end/total state is skipped, never repaired by invented source events. Event/link source completion fields stay null, and no invoice is issued.

The standard Laravel scheduler registers `csms:expire-unsent --limit=100 --scheduled` every minute with overlap protection. A service manager/cron must execute Laravel `schedule:run` each minute (or supervise `schedule:work`); processes do not survive restoration. Scheduled mode cannot target an individual reservation; it stores a non-secret cache cursor to advance through bounded pages, resetting after a full pass. Cache loss repeats an idempotent scan. Use a suitable shared cache for multi-worker scheduling; database locks still enforce one closure. No scheduler has been deployed to production. Roll back code/workers together and retain operational evidence; schema down removes this evidence table and is not a data-preserving production rollback.

Customer/vendor web and native display the localized `expired_unstarted` state with no start/stop/cancel/invoice actions. Existing source-active sessions are untouched. This does not implement full no-show, occupancy policy, real token revocation or financial penalties.
