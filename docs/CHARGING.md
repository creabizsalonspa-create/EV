# Local charging protocol integration

This module exercises **simulated OCPP chargers against the separately running CitrineOS v2.1.0 service**. It does not operate physical equipment, prove hardware compatibility, or establish live charging/payment readiness.

For a future authorized physical device pilot, use [OCPP Pilot Acceptance](OCPP_PILOT_ACCEPTANCE.md) as a **draft, unexecuted** safety, evidence, model/firmware matrix and rollback gate; it does not authorize a charger command.

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

## Started sessions require authenticated source continuity

Before a signed start has been observed, the source consumer can wait for a
simulator station/authorization that is still being provisioned. Once a
transaction has started or the source station has been pinned, disappearance
of either authenticated identity is a reconciliation incident rather than a
healthy empty poll. Similarly, a previously authenticated started transaction
that disappears or loses its required meter start or absolute start time
requires review; incomplete first-time source provisioning still waits safely. The reader never finalizes or releases capacity from
missing-source evidence, and resumes only after a verified original identity
returns. OCPP 1.6 and 2.0.1 station-removal/authorization-removal tests
require exactly one final invoice and zero extra commands after recovery.

## Monotonic meter cursor under parallel workers

The signed inbox owns charging event idempotency, while the restartable
source reader owns an independent monotonic `source_meter_cursor` checkpoint.
Once the authenticated terminal event, invoice and matching source
cursor are all durably committed, the reader short-circuits subsequent source
polls; later persisted diagnostic/voltage rows must not re-open the immutable
charging session or generate new invoices. If the terminal signed inbox
accepted the event but its reply was lost before the cursor advance, the reader
replays the same authenticated terminal event identity once to reconcile the
cursor, never to create another invoice or command. During this replay the
terminal meter sample timestamp is validated against the authenticated
transaction start/end window, not against the already-committed end-event
`last_event_at`, because the terminal register may precede the recorded
endTime. The signed inbox still enforces the original event identity/hash.
Concurrent or delayed observers use a conditional database update: a stale
worker may acknowledge an earlier non-energy source row but cannot write a
smaller cursor after another worker has already committed a later page.
Refreshing the link after the conditional update uses the durable position on
the next source poll; no second charger command or invoice is implied.
Both OCPP protocol fixtures simulate a concurrently advanced cursor and
verify that a late observer never rewinds it. This checks source checkpoint
monotonicity, not live cross-process throughput or physical acceptance.

## Immutable external source station and transaction identities

The local OCPP source consumer pins the independently authenticated
CitrineOS `ChargingStations.id` and its specific `Transactions.id` onto
the canonical session link together under a database row lock before its
first signed start event. Hasura station, authorization and transaction
projections must be well-formed list results, and authorization IDs must
be positive integers. Transaction rows must contain the full requested
identity, start/stop, meter and active-state shape; missing fields fail
closed before any signed event or cursor advance. For rider sessions, the
complete raw source collection is validated **before** already-assigned
transactions are filtered, preventing malformed older rows from being hidden. Absent/malformed
collections are a reconciliation failure, not an empty poll or permission
to infer a source identity. The external station id is not a cross-database
foreign key. Subsequent observations must match the original id **as well as**
the existing OCPP connection name, protocol, source authorization, transaction,
connector proof and signed inbox lineage. Reprovisioning a station with the
same name and protocol under a different source row never splices energy into
an existing session or issues a second start command.

The new nullable station-id and transaction-row-id columns do not
fabricate historical identities: sessions already started without either
pin require explicit reconciliation rather than adopting the current row.
Even if the OCPP protocol transactionId has been reused under the same source
station, a different persisted source transaction database row cannot
resume an existing canonical session. A separate unique database constraint
on `binding_id` and `source_transaction_database_id` additionally prevents
two canonical sessions from claiming the exact same upstream row, including
when competing workers both attempt to pin it. Nullable legacy identities
remain pending explicit reconciliation rather than being fabricated. A new session without source transaction evidence
does not pin an arbitrary upstream station. Both OCPP 1.6 and 2.0.1 have
replacement, legacy-unpinned and resume-with-original-source regressions.
This remains simulator-only evidence, not approved physical charger identity
validation or production upstream registration.

## Bounded meter-source page integrity

The restartable OCPP simulator source reader validates the complete Hasura
`MeterValues` page **before advancing any durable source meter cursor**,
including voltage/non-energy records that are intentionally not billed.
Only ordered, strictly increasing positive integer source row identifiers
within the signed inbox's sequence bound, an actual array of sampled values,
explicit source timestamps and a maximum of 100 rows per page are accepted.
The entire page is also checked for transaction chronology, source-row
timestamp monotonicity, unique cumulative energy registers, supported energy
units and meter precision before any cursor advance. The first row of a later
page must not precede the last signed source event in time; if it does, the
observer preserves the cursor and requires reconciliation instead of silently
skipping or billing reordered evidence. The first authenticated terminal
cumulative register forms a hard page boundary: any subsequent upstream
diagnostic/voltage rows are outside the canonical session and cannot block
signed terminal finalization or cause a replay of an invoice. Ignored voltage-only records cannot conceal a timestamp outside the
actual source transaction, and a malformed register later in the same page
cannot cause earlier records to be marked read.
Missing pages, overlong pages, unknown timestamps and re-ordered/repeated
row IDs stop reconciliation without skipping the original unread row.
Neither a failed read nor a duplicate cumulative register issues a new
charging command or fabricates energy. Previous signed start observations
remain durable; final invoices are still issued only from an authenticated
terminal cumulative register matching CitrineOS final energy.

Both OCPP 1.6 and 2.0.1 are covered by `SourceConsumerTest` malformed-page,
restart, duplicate-register and single-invoice regressions. A dedicated
100-row boundary test reads exactly the full first page of non-energy source
evidence, persists cursor 100, restarts to consume the unread terminal register
at id 101, and verifies that another restart observes an empty page, with
one invoice and no second charger command. An expanded 250-row
three-page simulated source stream for each OCPP version exercises two
identical periodic cumulative-register observations (no double counting),
three independent observer invocations and a final authoritative 2.5 kWh
session, with one invoice and no second charger command. These fixtures
exercise the canonical local-simulator source and signed inbox; they do not represent
full upstream conformance or physical charger acceptance.

## Fault observation cursor integrity

The bounded local CitrineOS fault observer rejects malformed/non-list
station, event-page and newest-event projections before any cursor or alert
commit, and a missing station row identifier cannot masquerade as an absent
charger. Both protocol fixtures also exercise 250 simulated status observations
over three 100-record cursor pages, with two independently detected and
recovered faults, no duplicate notices, no command or invoice side effects. Both protocol fixtures require operator-only review notification and
replay from the unadvanced cursor after corrected authenticated source data.

The bounded local CitrineOS fault observer validates the recognized OCPP
connector-status vocabulary and strict timezone-qualified source timestamp
before it commits even the first event in a source page. Unsupported device
states, relative/naive dates and future observations fail the entire page and
leave the durable cursor unchanged in `requires_review`. The owner receives
a private generic review notification; an operator-approved corrected source
page can resume from the original event ids. Recovery is sourced from a new
valid observation, never inferred from the failed poll. The observer does not
dispatch charger commands or alter reservations, sessions or billing.

`ContinuousFaultObserverTest` covers both OCPP versions and invalid-status,
relative-clock and naive-clock handling, followed by restart-safe source
catch-up. These are mocked-source regression checks, not conformance or
hardware acceptance.

## Source station protocol identity and reconciliation

The restartable local-simulator `SourceConsumer` now requires the authenticated
CitrineOS station's reported protocol to match the immutable CSMS session
binding before it reads transactions or emits signed start/meter/end events.
The source may report the pinned version as `1.6`/`2.0.1` or
`ocpp1.6`/`ocpp2.0.1`; missing, unknown or cross-version identities
fail closed for operator reconciliation. A provisioned station without an
authorization or transaction still contributes no session evidence.

The protocol check runs on each observation, including after an existing
start, so reprovisioning cannot silently reinterpret source meter readings.
Regression coverage in `SourceConsumerTest` exercises mismatches for both
versions, preserves the command and invoice state, and resumes only after
an independently corrected source identity. It neither sends nor replays
commands and does not establish physical hardware compatibility.

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

## Canonical topology continuation

See [CANONICAL_TOPOLOGY.md](CANONICAL_TOPOLOGY.md) for new scoped EVSE/mapping APIs, connector choices, immutable source/booking snapshots, parallel capacity and protocol proof. Legacy contracts/history remain; direct-managed sources are simulation-tested only. Current owner handoff is MASTER_AGENT2_CONTINUATION.md, which supersedes historical account restrictions.

## Self-reported arrival and unused booking expiry

See [RESERVATION_LIFECYCLE.md](RESERVATION_LIFECYCLE.md) for the implemented customer web/Flutter workflow, scoped check-in API, bounded scheduler, immutable evidence and PostgreSQL concurrency checks. No physical-presence or no-show fee claim.

## Received source evidence and command recovery

See [COMMAND_RECOVERY.md](COMMAND_RECOVERY.md): source-observed state, immutable proof, scoped web/native reconciliation and bounded operational recovery. No uncertain command is resent and no command/hardware acceptance is claimed.

## Secure charger admission and no-source case operations

See [CHARGER_ADMISSION.md](CHARGER_ADMISSION.md) for new scoped APIs, owner/Admin web and vendor Flutter controls, TLS/mTLS simulation evidence, credential/version/replay safeguards, source inspection and remaining production boundaries. Financial truth remains Agent1-owned.


## Explicit operator operating calendar — pending integration

`OperatingCalendar` gates only new booking/admission, using the existing published
station information and explicit `enforce_operating_hours` opt-in. It is independent
of source observations, OCPP command acceptance, financial snapshots and physical
availability. Existing advisory hours remain nonblocking until enabled by the owner.

Station publication, booking and start/claim checks share the station lock. Accepted
bookings keep database-immutable calendar snapshots. PostgreSQL independent-worker
tests force both closure-first and booking-first serialization orders. Hours are
rechecked before dispatch/publication and source pending-start admission; closing
a calendar never pretends an admitted/unknown command ended. Pending RFID replays
cannot bypass current hours; ongoing authorization and finalization remain possible.
Controlled OCPP1.6/2.0.1 GraphQL/OAuth contracts verify these boundaries, without
claiming physical hardware acceptance. See API.md and calendar evidence.

## Strict source transaction scalar identities

The OCPP 1.6/2.0.1 source observer treats authenticated GraphQL projections as **untrusted schema data**. A malformed non-null `remoteStartId` is never coerced to the canonical start-command ID (in PHP, a one-element array casts to integer `1`). Non-scalar/boolean `meterStart` and `totalKwh`, and non-string non-null `startTime`, are rejected **before** source-row pinning or signed inbox publication. This keeps the existing typed transaction reconciliation and exact Wh/kWh behavior, without dispatching or retrying a command. Paired protocol regression fixtures verify no synthetic start, invoice, cursor or ledger action on the malformed source, then recovery from the corrected original record. Production-hardware acceptance remains separate.

The observer also validates the exact source meter baseline and any available reported total **before** pinning the authenticated CSMS row identity. Unsupported precision cannot strand an unstarted canonical session with a prematurely adopted external row; the correct original source can subsequently recover without inventing a new transaction or command. The 1.6 legacy `remoteStartId=0` integer remains allowed (only shape is validated); a 2.0.1 remote start is still correlated against its real command ID.

## Durable chronology of non-energy source records

A source meter cursor now atomically stores the UTC microsecond timestamp of the actual row it consumed, including ignored voltage/diagnostic observations. Cross-page validation compares new rows against that persisted source timestamp rather than only the latest signed energy event, closing a gap where a diagnostic-only page boundary could conceal a regressing source clock. Concurrent observers update both cursor fields only on monotonic cursor advance; neither a failed source page nor lost inbox ACK can advance or rewind them. Pre-existing cursor rows have a null timestamp, not an invented historical source timestamp, and use the existing signed-event fallback until the first new validated cursor advance. Paired 1.6/2.0.1 multi-page regression fixtures check failure, unchanged journal/cursor, corrected source recovery and one final invoice. Simulator evidence only.

## Monotonic fault/status source clock and honest catch-up

The read-only OCPP 1.6/2.0.1 status worker now checks each authenticated event's strict absolute timestamp against the preceding source event in the same page **and** the last durably committed status observation from the previous page. Regressing clocks require operator review with no cursor, alert-recovery, command or availability mutation; corrected source rows can resume. A page is now `caught_up` only when its persisted last event reaches the independently reported upstream newest-event watermark, not merely because fewer than 101 rows arrived. Paired protocol regression fixtures check partial-page catch-up, same-page/cross-page rollback and source recovery. Physical availability is never asserted from simulator telemetry.

A read-only customer connector-freshness projection now also fails closed if the highest persisted source event ID is not the record carrying the highest source timestamp. That prevents older `Available` telemetry from being reused as current availability after a later `Faulted` event with a regressed clock, including historical source records ingested before the new observer checks. Source ID and timestamp disagreements are returned as `unknown`, not a fabricated live status; the affected OCPP protocol fixture verifies no charger or finance mutations.

For bounded tenant/source-facing status reads, a reversible multi-column `charging_observation_event_freshness` index supports the last source event lookup by generation, exact EVSE and connector, and source event ID. Both PostgreSQL and SQLite feature tests assert that the migration-created index exists. This is not a second status ledger and does not assert live charger presence.

## Canonical target proof source shapes

A mapped OCPP 1.6 charging session now requires exactly one authenticated, correctly typed CitrineOS transaction-to-connector record and exactly one matching charger connector. OCPP 2.0.1 requires a proper list of persisted `Started` TransactionEvent messages whose embedded transaction identity, start-command ID and exact EVSE/connector tuple match the canonical binding. Malformed objects, mixed/implicit connector IDs, and mismatched source target data cannot be silently coerced into a signed start or billable session. Paired mapped-topology negative/recovery fixtures cover both protocols; no physical hardware was operated.

## Continuous worker terminal ACK recovery

The read-only `csms:consume` worker selects not only nonterminal links but also signed-terminal links whose persisted source meter cursor still trails `last_sequence`. A terminal inbox commit may succeed while its HTTP acknowledgment is lost, leaving `ended_at` set before the source cursor catches up; filtering on `ended_at IS NULL` alone previously skipped that legitimate recovery indefinitely. The worker now re-reads the original authenticated source and replays only the same signed event identity, never the charger start/stop command, until the durable cursor converges. It excludes already-converged terminals and locally closed-before-dispatch links. The existing signed-inbox lost-ACK protocol fixture now exercises actual `csms:consume --once --link=...` worker selection, final cursor and exactly-one-invoice safety. Simulator only; no external charger activation.

The canonical Wh parser now accepts only explicit decimal strings or native integers. Boolean JSON values (especially `true`, which PHP converts to `1`) and floating-point representations cannot silently turn into billing-grade registers. Both OCPP versions verify invalid energy values in a later source page leave the durable cursor, session state and invoice unchanged, then recover from corrected source text.

The lost-terminal-ACK worker fixture now executes the real signed-inbox and local in-process branches for both OCPP 1.6 and OCPP 2.0.1, not only 2.0.1. The malformed floating-point source-page fixture uses JSON numeric `3.5 kWh`, which survives encoding as a float and would previously finalize matching terminal energy; `3500.0 Wh` may encode as integer `3500` and would not actually exercise the float boundary. Direct Wh parser tests separately reject integral-looking float inputs.

An OCPP 1.6/2.0.1 `Transaction.Begin` energy register is now validated as exact Wh/kWh source evidence and compared with the authenticated transaction `meterStart` before advancing any meter cursor. The previous implementation skipped every Begin register, so a contradictory/malformed start meter could be discarded and an apparently valid later `Transaction.End` could create an invoice. The new paired regression fixtures reject mismatched and boolean Begin registers, preserve all unread source rows, and verify corrected source recovery without another command or invoice.

## Fail-closed configuration source station identity

The local-only OCPP 1.6/2.0.1 read-only configuration adapter now requires a single well-formed authenticated source station list with an actual positive integer database ID, boolean `isOnline`, and matching protocol. Missing or object-shaped GraphQL results, duplicate stations and string-coerced IDs/online flags cannot reach local token issuance or a configuration transport request. The controller records a private `not_dispatched` outcome without falsely claiming charger acceptance, and existing idempotency prevents an unapproved replay. Parameterized negative fixtures cover both supported protocols; none authorize hardware operations.

The bounded source-status duration analytics now checks **source event ID chronology against event timestamps** before claiming any simulated duration totals. Legacy or malformed imported records where a newer status ID carries an older timestamp result in a whole-report `requires_review` with no partial availability, fault or uptime numbers. Normal same-timestamp source rows are ordered by their authenticated IDs; a dedicated regression test asserts zero command/finance side effects. Hardware uptime is never inferred from status telemetry.

The configuration evidence parser distinguishes optional **missing** lists from explicitly null, object-shaped, or structurally malformed `configurationKey` (OCPP 1.6) and `getVariableResult` (OCPP 2.0.1) arrays. Unknown-key arrays are bounded and must contain strings. Invalid device replies cannot be turned into a source-observed settings snapshot; only whitelisted, sanitized values are ever projected. Negative protocol fixtures verify no command, invoice or other side effects.

For read-only OCPP configuration observation, the projected Hasura `OCPPMessages` collections must now be genuine bounded lists with positive typed source message IDs and strict absolute event timestamps. Only typed response message types 3/4 and valid response payload shape can establish a correlated device reply; malformed/missing collections are rejected before writing encrypted snapshots or declaring device settings observed. Protocol-paired negative tests verify this remains read-only and does not retry a command.

## Reset admission fails closed on malformed source topology

The local-only supervised OCPP 1.6/2.0.1 reset adapter now requires a **single typed source station**, an empty explicitly listed active-transaction collection, a bounded unique positive-integer connector list, and a bounded list of typed status observations with strict timestamps. GraphQL object projections, string IDs, missing timestamps, duplicated connectors and active transactions cannot be silently coerced to the idle/Available evidence necessary for an authorized reset. Twenty paired negative protocol fixtures assert no reset operation, source device command, or financial change. Existing one-shot status and post-reset review gates remain intact. Hardware pilots remain unauthorized.

A local supervised reset now rejects contradictory **same-instant** statuses and any regression between descending source event IDs and their absolute timestamps. A falsely newer `Available` event cannot overrule a later-clock fault. Paired OCPP 1.6/2.0.1 regression fixtures require 409, zero reset operations, no new source token, and no charger/finance mutations. This remains simulator-only: software status does not confirm physical idle state.

## Rider credential source identity safety

The existing encrypted rider RFID/IdToken publication boundary now rejects non-list/malformed Hasura `Authorizations` projections (including a one-element object, null row, and missing or string-coerced identity) **before** issuing any source mutation. Previously an object-shaped one-row response could be interpreted as absence and lead to an attempted second authorization insert. The existing short database lease, operator/tenant checks and source reconciliation remain mandatory. Four negative source-projection regression cases assert zero source writes, zero charging commands and explicit `requires_inspection`; this remains a simulator-only credential contract, not approval for live rider activation.

## Remote-session source identity projection integrity

The OCPP simulator's per-session remote credential publication now fails closed on missing, object-shaped, ambiguous or malformed Hasura authorization collections. The original GraphQL response is never treated as an absent authorization unless the authenticated result is an explicit empty list. A source-owned credential must contain exactly one well-typed `CREABIZ_REMOTE` nonce marker; null and unexpected marker records cannot authorize a session. Parameterized source-projection tests assert HTTP 409, no source mutations, no extra charging commands or invoices. Existing online-authorizer, short-lived credential and operator/capacity guards remain mandatory; no production token is issued.

Per-session OCPP remote credentials now require an authenticated authorization expiry encoded as an actual absolute ISO timestamp with an explicit UTC offset, not loosely parsed relative text such as `tomorrow` or invalid calendar dates. A malformed or expired source value is never considered an active authorization; the existing source marker, session nonce, read-only lookup and immediate expiry tests remain in force. A replay-safe negative/valid-expiry fixture proves no source mutation or charging command is dispatched.

## Preserve source provenance during migration rollback

The new source-station pin, source-transaction database-row pin, uniqueness constraint and persisted meter-cursor timestamp are now **loss-protected** in migration `down()`: if any canonical link retains non-null source identity or cursor chronology, downgrade aborts before deleting a field or its uniqueness index. Operators must retain/restore a private backup and deliberately reconcile historical source provenance before any destructive schema action. An isolated database regression verifies all four guards, preserved columns and the source-transaction uniqueness index; no migration is run on production.

A separate **disposable-test-database-only** migration rehearsal now exercises the complete 000001–000005 charging source provenance series in reverse, then forward, checking all three identity/cursor columns and both indexes at each stage. The existing source-consumer regression separately proves populated source history refuses lossy downgrade. This new rehearsal is explicitly included in the PostgreSQL connector CI suite as well as the general Laravel feature suite; it cannot target a non-test/non-review database.

## Offline OCPP readiness gate (not hardware acceptance)

Use `php artisan csms:preflight` to print a strictly redacted, read-only JSON readiness report. This does **not** perform HTTP requests, issue CSMS tokens, send charger commands, mutate financial state or claim physical/production acceptance. A successful exit (0) proves only these local **software-configuration prerequisites**: simulator-only feature gates; independent >=32-character source/inbox secrets (never shown); a bounded source URL with no embedded credentials or query secrets; and the canonical OCPP/source tables, pinning columns and source-status indexes. A failed exit (1) means blocked, without suggesting that missing telemetry is zero or live availability is verified. `production_ready`, hardware/queue acceptance, certificate lifecycle and external pilot approval remain `false` unconditionally. Automated SQLite + isolated PostgreSQL tests check healthy and deliberately unsafe settings and absence of charger/network/finance effects.

The preflight is intentionally stricter than a generic URL validator: authenticated source GraphQL URLs cannot be marked ready when configured as **cleartext HTTP to a non-local host**. Only fixed loopback/CitrineOS Docker service names may use local HTTP; a non-local source must use HTTPS without URL-embedded credentials. The runtime remains simulator-disabled in production; this offline check neither asserts TLS handshake success nor overrides separate staging certificate/issuer verification.

## Source firmware model/protocol identity is untrusted until typed

The signed firmware maintenance adapter (local simulator only) now requires a one-record **list-shaped** authenticated CitrineOS source station with explicitly typed manufacturer, model, firmware version and negotiated protocol. It rejects missing, object-shaped, null or contradictory manufacturer/protocol/firmware data before a firmware command can be claimed or a download token issued. This validation is in addition to the independent signer pin, signed artifact bytes, optional signed compatibility path, maintenance lockout and second pre-dispatch source recheck. Twelve protocol-paired regression scenarios require zero firmware command or financial mutations. A positive simulator result is not evidence of hardware flashing or acceptance.

OCPP source transaction identity is now protocol-specific at ingestion as well as dispatch: OCPP 1.6 accepts only canonical signed 32-bit transaction numbers (including typed upstream integers); OCPP 2.0.1 requires a nonempty string no longer than 36 bytes. Four paired malformed/overflow source-projection fixtures fail before source-row identity pinning, then replay the corrected original source to produce exactly one signed session and invoice. Invalid protocol transaction IDs are never coerced into remote-stop commands or billing evidence.
