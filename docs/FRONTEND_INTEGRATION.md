# Shared frontend/backend integration

All authorized accounts are full-stack maintainers. Older task assignments do not permanently partition the product. The canonical native client remains `apps/mobile`, preserving the other account's verified implementation. Earlier parallel customer/vendor/shared-package drafts were backed up before retirement; they are not a second maintained client.

Existing cookie/CSRF endpoints remain compatible. The optional `/api/v1` Sanctum API is additive; the current native cookie client does not need to switch authentication to consume new features. Native bearer tokens expire after 24 hours, cap at five per account and revoke on logout/password reset. Admin remains web-only.

## Mounted contracts

- Finance: `/api/stations/{station}/tariffs`, `/api/finance/invoices`, `/api/finance/sessions/{session}/invoice`, invoice detail, `/simulate-payment`, `/simulate-refund`, `/journal`. See [FINANCE.md](FINANCE.md). Only simulated finance is enabled; do not label it PSP-paid/legal tax invoicing. The session captures its active tariff/quote at start.
- Vendor operations: `/api/vendor-ops/companies`, evidence upload/download, submit/review/history, staff consent/permissions/revocation, sites/chargers/connectors/maintenance. See [VENDOR_OPS.md](VENDOR_OPS.md). Approval is an internal workflow, not legal KYC/hardware certification. Accepted customer-role staff need a vendor persona in the clients; role-only gates do not complete that UX.
- OCPI: `/api/roaming/locations` public; `/api/ocpi/providers` and scoped negotiate/sync/records/authorization. See [OCPI.md](OCPI.md). Imported records remain unverified and not billing/control eligible; no partner is configured.

Finance and vendor relative routes are also mounted under authenticated `/api/v1`. OCPI remains session-authenticated. Web mutations retain CSRF. These are actual application routes, not only test registrations.

## Correlated CSMS simulation

`POST /api/reservations/{reservation}/csms-start`: reservation owner, current confirmed window, provisioned simulator binding. Response 202: `session`, `link`, `start_command`, `stop_command`, `evidence: SIMULATED_CSMS`, `physical_charging_verified: false`. `pending_start` waits for the source transaction; submitted commands are not active charging.

`POST /api/charging-sessions/{session}/csms-stop`: customer owner, owning vendor or admin. Queues at most one stop, transitions to `stopping`, and awaits source final meter/end. Repeats return the same command. Unresolved sessions block a second start on that simulator binding.

The service-signed inbox correlates binding/transaction/cumulative registers, rejects conflicts and decreasing meters/timestamps, and deduplicates event IDs/sequences. Only the source end/meter atomically finalizes energy, cost and one immutable SIMULATED invoice. Manual simulator meter/stop endpoints cannot override linked sessions. Unknown command outcomes require reconciliation, never blind start retries.

Scoped OpenAPI: [openapi/charging-bridge.json](openapi/charging-bridge.json). The signed event endpoint is for a trusted adapter, never a customer/mobile secret. Production/hardware ingestion, full OpenAPI, live PSP/partners and full client UX remain unfinished.

## Connected source and invoice UI

Reservation list rows add csms_bound; bound customer requests use POST reservations/{id}/csms-start. Session list rows add csms_linked and start_command_status/stop_command_status, with SIMULATED_CSMS evidence for linked sessions. Web and canonical Flutter hide manual meters on linked rows and route stop to charging-sessions/{id}/csms-stop. pending_start/stopping remain waiting states until source confirmation, with manual refresh/pull-to-refresh. Unbound application simulation is preserved.

Both web and native can request the session-unique simulated invoice and render its actual integer subtotal/tax/total, collected/refunded/due and ledger balance. Only SIMULATOR_ONLY / real_payment_processed=false summaries are displayed as simulation documents. No real checkout/refund/payout controls were added. New widget/browser tests cover routing, disabled overrides, waiting state, invoice display and expiry cleanup.

## Company personas

Web company operations are now independent of the global vendor-role panel. Server capability hints expose the current member's scope; accepted customer-role staff can use granted inventory APIs while private evidence remains owner/admin only. Web supports actual application/evidence/review/renewal/staff/hierarchy/maintenance workflows. Native company/KYC/staff/inventory parity remains next work; do not mark both client surfaces complete from web coverage.

## OCPI client interfaces

Web partner discovery and Flutter Discover → Partner locations consume `/api/roaming/locations` with same-origin numeric pagination. Both reject changed public capability flags and retain partner-reported provenance. Authenticated vendor/admin web Roaming operations uses owner-scoped providers and normalized records, with explicit confirmations before negotiation/sync and admin authorization changes. Server configuration remains the sole source of connection origins/tokens. No auto-polling, command replay, remote-charging capability or billing eligibility is inferred. Provider creation/configuration and operator mobile screens remain unfinished. Tests: `npm run test:roaming`, native `test/roaming_test.dart`, synthetic TLS/device integration; live partner conformance is separate.

## Canonical topology continuation

See [CANONICAL_TOPOLOGY.md](CANONICAL_TOPOLOGY.md) for new scoped EVSE/mapping APIs, connector choices, immutable source/booking snapshots, parallel capacity and protocol proof. Legacy contracts/history remain; direct-managed sources are simulation-tested only. Current owner handoff is MASTER_AGENT2_CONTINUATION.md, which supersedes historical account restrictions.

## Self-reported arrival and unused booking expiry

See [RESERVATION_LIFECYCLE.md](RESERVATION_LIFECYCLE.md) for the implemented customer web/Flutter workflow, scoped check-in API, bounded scheduler, immutable evidence and PostgreSQL concurrency checks. No physical-presence or no-show fee claim.

## Received source evidence and command recovery

See [COMMAND_RECOVERY.md](COMMAND_RECOVERY.md): source-observed state, immutable proof, scoped web/native reconciliation and bounded operational recovery. No uncertain command is resent and no command/hardware acceptance is claimed.

## Secure charger admission and no-source case operations

See [CHARGER_ADMISSION.md](CHARGER_ADMISSION.md) for new scoped APIs, owner/Admin web and vendor Flutter controls, TLS/mTLS simulation evidence, credential/version/replay safeguards, source inspection and remaining production boundaries. Financial truth remains Agent1-owned.
