## Browser session response ordering — 12 November 2026

The web middleware suppresses only the session cookie on GET/HEAD when the decrypted
incoming session ID and final session ID are identical. This prevents a delayed private
read from restoring actorA's old cookie after logout and actorB's login. Fresh anonymous
bootstrap and rotated sessions still emit cookies; POST/PUT/PATCH/DELETE retain normal
framework refresh. XSRF and unrelated cookie attributes remain unchanged. Native bearer
authentication is unaffected. Unchanged reads no longer extend browser cookie expiry;
server inactivity storage lifetime is unchanged, and bootstrap/rotation/writes retain
normal cookie refresh. Clients must still fence stale response bodies and wait for
initial anonymous bootstrap before login. Read regression uses two real synthetic actors,
normal login/logout and an actually delayed successful protected GET; no charging/money
mutation, assertion retry, production rate change or browser-cookie spoof is used.

# Durable fault operations (candidate)

Under each existing charger path, GET `fault-alerts` provides scoped25/page durable observations; POST `fault-alerts/inspect` reads one authenticated source page and atomically records immutable events/alerts (inventory.write). Its `next_before` supports bounded catch-up. PATCH `fault-alerts/{id}/acknowledge` requires a10..2000-character note, stores the first encrypted acknowledgment once and audits it; it cannot resolve a fault or change charging capacity. Changed source-event replay conflicts, generation changes and revoked access fail closed. Recovery requires newer, later Available/NoError source evidence on the same component. GET remains read-only; no remote command/payment is sent. Cookie and bearer prefixes share records. Inspect/acknowledge do not blindly retry unknown responses. Bounded monitor: `scripts/csms-monitor-faults`; source simulator-only truth labels remain in force.

# Source-backed charger diagnostics (candidate)

`GET /api/vendor-ops/companies/{company}/chargers/{charger}/source-diagnostics` and the equivalent bearer `/api/v1/` route require current `inventory.read` and canonical owned simulator mapping. Reads the authenticated CitrineOS projection, not business-session inference. Returns reported online state, protocol, firmware, last source-message age and25status/fault events; `before` is an exclusive positive source-ID cursor. Missing registration returns unknown online state; transport/auth/schema failures return503. Rechecks ownership/permission/mapping after source calls. Private/no-store, no raw source info/credentials, no command/finance writes. Only the local simulator adapter is enabled; production access returns503. Both protocol source/browser tests: `node tests/charger-diagnostics.cjs`. Native tests remain pending SDK restoration.

# Charging evidence overview (candidate)

Vendor/admin read-only `GET /api/charging-operations/dashboard` and `/api/v1/charging-operations/dashboard` expose scoped command counts, open source-linked sessions, missing recent evidence and unresolved recovery counts. Attention records paginate25/page; no token/payload/customer identity or recovery note is exposed. No command dispatch or financial write occurs. Evidence freshness is not charger availability. Contract and validation: [CHARGING_DASHBOARD.md](CHARGING_DASHBOARD.md).

# Verified discovery and native loyalty additions (PR18/19/20)

Public discovery lookup: `GET /api/discovery/capabilities`, `POST /api/discovery/addresses`, `POST /api/discovery/route`; equivalent `/api/v1/discovery/...` routes. Web POST retains CSRF middleware. POST requires explicit consent; address accepts3..180-character query, en/ar language and optional two-letter country. Route accepts2..5 latitude/longitude points (endpoints plus up to3 ordered stops). Capabilities identify the fixed Geoapify adapter and whether it is configured. Server key is never returned. Defaults disabled; rate budgets12/IP/minute,30 globally/minute and5000/day. Route responses use sourced meters/seconds/geometry with traffic and charging-time flags false. Sanitized422/502/503 failures retain manual navigation;429 requires waiting. Full contract/activation/privacy: DISCOVERY_ROUTING.md.

Authenticated native read-only loyalty uses existing `commercial/loyalty/account`, `ledger`, `rewards`, `redemptions` and `redemptions/{id}` under both API prefixes. Server integer noncash points, debt/holds, tiers, available stock, pagination and pending/manual fulfillment status remain authoritative. No GET posts earning, redemption or reconciliation. Cross-member request detail is404. Native UI does not expose administrator economics/publication/fulfillment or invent monetary value. Full endpoint contracts: LOYALTY_ECONOMICS.md and FINANCE_MOBILE_CONTRACT.md; native scope/evidence: NATIVE_LOYALTY.md.

# Customer favorites additive APIs

Protected GET favorites/status (1–25 station_ids), GET favorites (25/page), idempotent PUT/DELETE favorites/{station} share cookie/native-v1 records. Customer-only, existing CSRF/throttles, no private fields on public discovery and no change to charging capability. See CUSTOMER_FAVORITES.md for complete contracts and readiness.

# Verified integration backlog closed — 28 October 2026

PR14 verified source **ae8a10f3bb3758efb072bcea73fe0688197e6e53** passed all10 checks and merged at6d995d2. PR15 safely merged that base, reran all affected checks at **bb75151b1572e073f63f8b664336983a94198b2c**, passed all8 checks (two full platform/PostgreSQL matrices and Android/iOS native validation), and merged at **8573081d3712b1fd2249a9da9030d96f3aa82351**. Both merge trees exactly equal their tested source trees. Actual iOS runtime PDF-render/Keychain/Arabic markers and artifact/log digests are retained in `docs/evidence/native-vendor/hosted-checkpoint.json` and `docs/evidence/catalog-nearby/hosted-checkpoint.json`.

The full42-category/312-atom weighted tracker now credits both verified merges. Reproduce coded/tested/merged/production-accepted coverage with `python3 scripts/requirement-coverage --check`; no commit/test-count percentage or production acceptance is inferred. Agent2 temporarily owns the entire original product. The temporary branch freeze has been fulfilled; resume remaining customer/EV/commercial/native/Admin software from this verified base, preserving canonical services. Interactive maps/navigation/favorites/reviews, full production OCPP/OCPI, remaining native commercial/vendor/Admin/security/deployment/release work are still unfinished. Physical chargers, live PSP/roaming partners, signing/stores and real operational acceptance remain external gates, not excuses for independent missing software.

Earlier candidate/failed/pending records below are historical and superseded by this exact verified checkpoint.

## Latest owner update and native CI correction — 28 October 2026

Agent2 temporarily owns the full EV and finance/commercial scope while Agent1 is unavailable. Reuse existing canonical finance and preserve historical ownership records. Native-vendor PR14 source1b72354 passed backend/PostgreSQL/Android but failed the PDF-ready assertion after private upload; source5b258f1 now waits for actual asynchronous native PDF load and fails fast on a viewer error. Latest iOS validation is pending, so PR14 remains unmerged. Nearby search source2909566 plus that preserved fix remains an unmerged locally verified candidate. The 42-category audit in REQUIREMENT_STATUS separates implemented subsets and remaining software; no broad70% estimate or production acceptance is claimed.

# Agent2 nearby catalog continuation — 28 October 2026

GET `/api/stations` optionally accepts latitude−90..90, longitude−180..180 and radius_km1..200 together; returns numeric distance_km in great-circle km, nearest-first then ID-descending, and validated filters in pagination links. Without the group, existing behavior is retained. See CATALOG_NEARBY.md.

Agent1 exclusively owns financial/commercial business logic and backend/web/Admin finance. This slice changes EV catalog APIs/UI/tests only. Native-vendor PR14 must pass exact-source iOS CI before integration. Interactive maps remain unfinished software.

# Current HTTP API (development foundation)

All endpoints are same-origin JSON, `Accept: application/json`. Mutations use Laravel's session cookie and `X-CSRF-TOKEN` from `GET /api/csrf`. Cookies are HttpOnly; CSRF is enabled outside Laravel's test runner. Native bearer authentication is not implemented. Errors: 401 unauthenticated, 403 wrong role, 404 inaccessible resource, 409 conflict, 422 validation, 429 rate limit. Auth endpoints are limited to 10/minute, authenticated endpoints 60/minute.

| Method/path | Access | Contract |
|---|---|---|
| POST /api/register | Public | name, email, password (12–128 chars), optional locale en/ar; always customer; 201 |
| POST /api/login | Public | email, password; regenerates session; 200 |
| POST /api/logout | Session | invalidates session and rotates CSRF; 204 |
| GET /api/me | Session | identity; 200 |
| GET /api/stations | Public | optional q (name, max 100), connector_type CCS2/Type2/CHAdeMO, country, min_power_kw, page; paginated 25; unknown availability, remote_control=false |
| POST /api/stations | Vendor | name, country uppercase 2 chars, currency uppercase 3 chars, IANA timezone, latitude, longitude, source discovery/simulator, connector_type, power_kw 1–1000; 201 |
| GET/POST /api/vehicles | Session | own records only; create name + connector_type; 201 |
| GET /api/reservations | Session | own customer records, own-station vendor records, admin all; paginated |
| POST /api/reservations | Customer | station_id, starts_at literal UTC `YYYY-MM-DDTHH:mm:ssZ`, idempotency_key <=100; future <30 days, half-hour boundary, simulator inventory only; 201, exact retry 200; conflict 409 |
| DELETE /api/reservations/{id} | Owner | cancels an unstarted booking; started reservations return 409; slot becomes reusable |
| GET /api/simulations | Session | scoped as reservations; SIMULATED and not_processed labels |
| POST /api/reservations/{id}/simulate-start | Owner | starts only within confirmed reserved interval, same reservation returns same session |
| PUT /api/simulations/{id}/meter | Owning vendor | energy_wh integer 0–1,000,000, cumulative monotonic; charging state only |
| POST /api/simulations/{id}/stop | Owner/owning vendor/admin | freezes test cost, repeat stop is idempotent; integer half-up rounding |
| GET /api/admin/overview | Admin | aggregate counts and last 50 audit events |

Reservation dates are stored as UTC. End = start +30 minutes. No arbitrary-duration or multi-connector reservation support yet. Session amounts are development test estimates; no real invoice, PSP authorization or settlement is implied. Simulator readings are vendor-entered fixtures, not signed hardware evidence.

## Added identity, enterprise, infrastructure and support contracts

| Method/path | Access | Contract |
|---|---|---|
| POST /api/forgot-password | Public, 5/min | email; generic 202 regardless of registration; delivery requires configured mail transport |
| POST /api/reset-password | Public, 5/min | email, token, password, password_confirmation; resets through Laravel broker; deletes existing DB sessions and rotates remember token; 200 |
| PATCH /api/profile | Session | name, locale en/ar; cannot change role, identity or email |
| GET /api/regions | Public | configured country/currency/minor units/timezone/tax; commerce disabled |
| PUT /api/regions/{country} | Admin | uppercase 2-char country, currency, minor_units 0–3, IANA timezone, nullable tax_bps 0–10000; this cannot enable commerce |
| GET/POST /api/fleets | Session | own/accepted fleets; create name and configured country |
| GET /api/fleets/{id} | Owner/active member | owner sees membership, driver sees only their assigned vehicles; members can see cost centers |
| POST /api/fleets/{id}/invitations | Owner | email of existing eligible account; non-enumerating 202; recipient must accept |
| GET /api/fleet-invitations | Session | own pending invitations |
| POST /api/fleet-invitations/{id}/accept | Recipient | invited/active only; revoked stays rejected |
| DELETE /api/fleets/{id}/members/{membership} | Owner | revoke and remove assigned fleet vehicles in a transaction |
| POST /api/fleets/{id}/cost-centers | Owner | unique per-fleet code and name |
| POST /api/fleets/{id}/vehicles | Active member/owner | own vehicle_id, same-fleet cost_center_id; vehicle cannot belong to two fleets |
| POST /api/stations/{id}/csms-binding | Admin | simulator source only, unique identifier matching `ev-test-*`, protocol 1.6 or 2.0.1; 201 |
| POST /api/stations/{id}/commands | Owning vendor | action start/stop, idempotency_key, transaction_id for stop; creates pending outbox command; 202; retry returns same row, payload conflict 409 |
| GET /api/charger-commands | Owning vendor/admin | scoped outbox states; submitted is never physical-session acceptance |
| GET/POST /api/support | Session | own tickets (admin all); create subject <=150 + body <=5000 |
| GET /api/support/{id} | Owner/admin | paginated messages; internal notes visible only to admin |
| POST /api/support/{id}/messages | Owner/admin | body, optional internal (admin only); closed rejects replies; customer reply reopens resolved ticket |
| PATCH /api/support/{id}/status | Admin | status + public reason; explicit transition graph enforced |
| GET /api/notifications | Session | own in-app notification feed; no push/SMS/email delivery is implied |
| POST /api/notifications/{id}/read | Owner | idempotently records read timestamp |

CSMS commands are dispatched explicitly using `./scripts/csms-dispatch ID`. Dispatch is disabled unless opted in and rejected in production. It targets only the local simulator stack. States: pending → dispatching → submitted / rejected / outcome_unknown. Dispatching after a worker crash and outcome_unknown require reconciliation, never blind replay. A submitted HTTP confirmation does not complete a charging session or billing workflow.

Named rate-limit budgets separate authentication (10/minute), recovery (5), general API/catalog (60 each), charger commands (30) and support (30). Budgets key by authenticated actor or guest IP. This avoids unrelated requests exhausting a stricter endpoint's allowance. Production anti-abuse, distributed rate limits and fine-grained staff access still need QA.

## Email ownership verification

- `POST /api/email/verification-notification`: authenticated, CSRF protected, separate budget of 3 requests/minute per actor. Sends Laravel's signed notification to the current account's email only; payload email/id claims are ignored. Returns 202 after the transport accepts the request, or 200 without sending if already verified. Never returns the link or signature. Registration remains available during mail outages; the user explicitly requests the message using **Send verification email**.
- `GET /email/verify/{id}/{hash}?expires=…&signature=…`: named `verification.verify`, authenticated and signed, 10 requests/minute per actor; 60-minute notification expiry. The account ID and email hash must match the signed-in user. Modified, unsigned, expired, old-email, and other-account links return 403. A guest is redirected to sign in, then must reopen the original email link. No signature is copied into the sign-in query or browser storage.
- Success renders a confirmation page and atomically records `email_verified_at` plus one `email.verified` audit event; repeated valid requests are idempotent. `GET /api/me` exposes the timestamp. The profile API cannot set it. The Laravel Verified event is dispatched only for the first transition.
- The web account shows verification status, send and refresh controls. Verification confirms mailbox ownership only. It does not approve a vendor, perform KYC, enable payments, or grant privileges. Existing simulated workflows remain accessible without verification; verified-only production policies, 2FA and native authentication remain separate unfinished work.
- Local mail is logged. Automated tests validate notification generation and signed-link consumption, not real mailbox delivery. Staging needs a configured mail transport, correct `APP_URL`, HTTPS and delivery QA. Logs contain sensitive links: do not publish or copy them into test evidence.

## Web appearance

The shared web UI supports Light/Dark/System through the header Appearance control. The selected preference is browser-local (`creabiz.theme`) and defaults to the operating system. System changes apply immediately; choices synchronize across tabs. Storage denial falls back to the current page. The preference also applies to verification and password-reset pages before styles load. Native clients, full design-system review, complete Arabic translation and formal accessibility acceptance remain outstanding.

## Frontend integration checkpoint

The web client continues to use the existing endpoints without backend contract changes. Catalog pagination follows only the returned query string on the same fixed `/api/stations` endpoint; it never fetches a returned foreign origin. Admin cards/table consume `GET /api/admin/overview` counts plus `audit_events`. Fleet vehicle assignment submits `vehicle_id` and `cost_center_id` to the existing same-fleet endpoint. UI-only hiding never replaces server authorization.

The initial native source uses the same session-cookie/CSRF endpoints, keeps credentials in memory, refreshes CSRF after login, and requires a reachable HTTPS origin in release builds. This integration is source-only and unverified on devices. It does not introduce bearer-token APIs, native secure persistence or web admin access.

Backend coordination requirements (not implemented endpoints or approved contracts): multi-connector station/EVSE/connector identifiers with reservation/control targeting; verified vendor organization/staff permissions and KYC review states; authenticated session event/energy lifecycle; versioned tariff quote + currency/minor-unit rules; idempotent hosted-payment intent/invoice/settlement APIs; authenticated discovery/availability provenance and freshness; outbound OCPI capability negotiation. Frontend modules remain incomplete until the backend defines/implements these, and must not invent live data or successful finance states.

## Native pagination, settings and support UI contracts

Native catalog sends only existing `q`, `connector_type`, uppercase `country`, `min_power_kw` and `page`; it trusts `current_page`/`last_page`/`total` and never follows server pagination URLs to a different origin. Distance/price/live-availability filtering remains unavailable, not simulated. Other native list views use their existing page parameter. Native preferences store only theme/locale locally; an explicit profile save sends name + the selected locale through PATCH /api/profile. No cookie/token persistence or new auth endpoint is introduced.

Native recovery/verification send existing requests; link consumption still uses the signed-in web flow, not an invented native deep-link contract. Support threads use existing GET /api/support/{id} messages pagination and POST messages; notifications use existing feed/read routes. No admin mobile surface is created. Web audit CSV is generated locally from filtered latest-50 API records, not a complete historical export. Full export, connector targeting, finance and authorized map/live integration contracts remain backend coordination work.

## Current native fleet, simulator and session integration

Native fleet UI consumes existing GET/POST fleets, GET fleet-invitations, POST invitation accept, GET fleet details, POST invitations/cost-centers/vehicles and DELETE member. Invitation response is deliberately nonenumerating; acceptance is explicit. Driver detail hides other members/vehicles; assignment chooses only acting-user vehicles, paginated. Revocation removes assignments. Existing contracts do not support re-inviting revoked memberships, center editing/deletion, arbitrary manager vehicle assignment or unassignment; coordinate with backend rather than invent endpoints.

Vendor session UI consumes paginated GET simulations, PUT simulations/{id}/meter with integer cumulative energy_wh (0..1000000, never decreasing), POST simulations/{id}/stop. Server ownership and stopped-session conflicts remain authoritative. Estimates are SIMULATED, not invoices or processed payments.

Current native auth supersedes earlier in-memory-only checkpoint: optional encrypted persistence uses existing HTTPS server-expiring Secure/HttpOnly cookies with fresh GET me and CSRF on restore. No bearer/refresh endpoint is introduced; server session lifetime/revocation remains authoritative. Plaintext loopback tests cannot enable persistence. Existing native recovery/verification requests still hand link consumption to web; mobile deep-link contract is unfinished.

## Universal integration additions

Current additive contracts and client requirements are in [FRONTEND_INTEGRATION.md](FRONTEND_INTEGRATION.md). Finance, vendor-operations and OCPI are mounted in the application; request/role/response details are in [FINANCE.md](FINANCE.md), [VENDOR_OPS.md](VENDOR_OPS.md) and [OCPI.md](OCPI.md).

New routes: owner-scoped `POST /api/reservations/{reservation}/csms-start`, authorized `POST /api/charging-sessions/{session}/csms-stop`, and signed service-only `POST /api/internal/csms/events`. See [OpenAPI 3.1 contract](openapi/charging-bridge.json). Completion requires correlated source final meters; this local simulator bridge is disabled in production and is not hardware verified.

Vendor/admin web additionally reads GET /api/charger-commands with page query, displaying only ID, station ID, action and transport status. It never follows returned pagination origins or exposes raw payload/confirmation/idempotency data. No bind/create/dispatch/replay control is introduced. pending/dispatching/submitted/rejected/outcome_unknown are transport states; none proves physical energy, completed charging or commercial success. Existing API scoping remains authoritative.

Reservation rows now include csms_bound; scoped session rows include csms_linked, source-specific evidence and start/stop transport statuses. These are additive capability/progress labels, never hardware/energy acceptance. Shared web/native select the existing linked control paths and simulated invoice summary. Continuous source observations are signed by a trusted local worker, never customer-held keys. See CHARGING.md.

## Invoice history filters

`GET /api/finance/invoices` and `/api/v1/finance/invoices` preserve existing role/tenant pagination and accept optional `q` (literal alphanumeric/hyphen invoice-number prefix, max 80), uppercase three-letter `currency`, `from` and `to` (`YYYY-MM-DD`, years 0001–9999). Dates are inclusive UTC calendar days; reversed/invalid ranges return 422. `GET .../finance/invoices/{id}` returns scoped immutable invoice/summary plus read-only payment/refund records; clients render only appropriate amount/time/reason fields, never idempotency/fingerprint metadata. No real PSP processing is implied by SIMULATED records.

## Scoped pricing and publish identity

`GET /api/finance/stations` (also `/api/v1`) is vendor/admin-only and paginated. Each item projects id/name/country/currency/minor_units/evidence, with null precision for missing matching region configuration. `GET stations/{id}/tariffs` requires current vendor ownership or admin and adds `pricing_context` without changing existing rows/pagination.

Existing tariff POST fields remain rate_minor_per_kwh/tax_bps/commission_bps. Optional idempotency_key (nonempty max100), expected_minor_units (0–3) and expected_currency (uppercase ISO-shaped code) add retry/precondition safety. Identity hashes are never returned. Matching retries return the original 201 without another audit/version; changed payload or stale currency/precision returns409. Vendor commission_bps must be zero and means retain administrator policy; admin may set commission. Existing clients omitting the new fields keep compatible append behavior, so new clients should always supply them.

## Managed catalog and pending-start cancellation

Company inventory writers can GET vendor-ops/companies/{id}/catalog-stations?q=... with scoped numeric pagination to select owned unlinked same-country simulator stations. Site creation's existing optional station_id now has an operator UI. Public station rows add reservation_policy=legacy|managed_ready|managed_blocked; availability remains unknown, and reservable is false for blocked managed inventory. No private company/license/maintenance reason is projected.

Session lists add start_admission_blocked and can_cancel_before_dispatch as UI hints; mutation handlers revalidate under locks. DELETE reservations/{id} still rejects started/manual/uncertain sessions, but can atomically cancel a proven pending, never-dispatched linked start. Repeating a completed cancellation is idempotent. Source link summaries distinguish closed_without_dispatch_at/closure_reason from actual ended_at; neither source completion nor an invoice is invented. Cookie/v1 finance and existing unlinked simulation contracts remain preserved.

## Admin OCPI association options

`GET /api/ocpi/connection-options` is admin-only and returns approved configured aliases with `configuration_valid` and `assigned`, plus `external_requests_made=false` and `partner_acceptance_verified=false`. Validation checks existing server config without a network request. No origin/endpoint, token value or token variable name is returned. `GET /api/ocpi/owner-options?q=<name>&page=N` is admin-only, paginated at25, vendor-role only and returns ID/name only; q is bounded at80 characters. Existing `POST /api/ocpi/providers` performs provider registration; the Admin web flow sends `authorized=false`, then requires separate explicit authorization. Duplicate aliases cannot create another provider. Registration does not call a partner. These admin web endpoints are not exposed as native Admin functionality.

## Canonical topology continuation

See [CANONICAL_TOPOLOGY.md](CANONICAL_TOPOLOGY.md) for new scoped EVSE/mapping APIs, connector choices, immutable source/booking snapshots, parallel capacity and protocol proof. Legacy contracts/history remain; direct-managed sources are simulation-tested only. Current owner handoff is MASTER_AGENT2_CONTINUATION.md, which supersedes historical account restrictions.

## Self-reported arrival and unused booking expiry

See [RESERVATION_LIFECYCLE.md](RESERVATION_LIFECYCLE.md) for the implemented customer web/Flutter workflow, scoped check-in API, bounded scheduler, immutable evidence and PostgreSQL concurrency checks. No physical-presence or no-show fee claim.

## Received source evidence and command recovery

See [COMMAND_RECOVERY.md](COMMAND_RECOVERY.md): source-observed state, immutable proof, scoped web/native reconciliation and bounded operational recovery. No uncertain command is resent and no command/hardware acceptance is claimed.

## Secure charger admission and no-source case operations

See [CHARGER_ADMISSION.md](CHARGER_ADMISSION.md) for new scoped APIs, owner/Admin web and vendor Flutter controls, TLS/mTLS simulation evidence, credential/version/replay safeguards, source inspection and remaining production boundaries. Financial truth remains Agent1-owned.


## Recurring authorization continuation — 28 October 2026

Saved provider-hosted consent, off-session subscription collection and customer/vendor web/Flutter controls extend the canonical financial gateway. See [RECURRING_PAYMENTS.md](RECURRING_PAYMENTS.md) for scoped endpoints, versions, scheduler, recovery, migration and test-mode limits. No live merchant acceptance or full-platform completion is claimed.


Credential lifecycle: existing admission GET adds a stored-credential state; GET `vendor-ops/companies/{company}/chargers/{charger}/admission/events?page=1` returns scoped redacted immutable25-row audit. See CHARGER_ADMISSION.md. This is not charger availability or PKI validation.


## Native company operations continuation — 28 October 2026

See [NATIVE_VENDOR_OPERATIONS.md](NATIVE_VENDOR_OPERATIONS.md): current web/Flutter creation POSTs use optional stable UUID idempotency; company GET adds private submission-readiness hints; staff acceptance now requires the displayed `expected_version`. Migration/client rollout is coordinated and old unversioned acceptance fails safely. Native company/KYC/staff/inventory workflows use the existing APIs and permissions, with bounded private multipart/binary transport and in-app previews. This is internal/synthetic acceptance only.

## Internal payment-case private evidence

See [PRIVATE_CASE_EVIDENCE.md](PRIVATE_CASE_EVIDENCE.md). Authenticated customer/vendor/Admin invoice parties may list/upload/download `commercial/cases/{case}/evidence[/{evidence}]`. Upload is consented PDF/PNG/JPEG up to4 MiB,20 files/case, with an actor/case-scoped idempotency identity and frozen MIME/size/hash/category. New uploads require an open canonical case; replay is retained after closure. Storage is encrypted/private; metadata immutable; downloads verify integrity and never return storage/provider identities. These are `PRIVATE_REVIEW_ONLY`, `provider_submitted=false`; no provider submission, financial decision or refund is implied.


### Explicit OCPI TEST invoice consent

Completed owned sandbox roaming orders can expose `can_preview_test_invoice` only with the local/testing `OCPI_TEST_BILLING_ENABLED` flag and an explicitly approved server-side TEST billing contract. Default is off. `GET /api/roaming/sandbox-orders/{id}/test-invoice-preview` returns exact minor-unit/energy terms and a five-minute actor/source/policy-bound preview token. `POST .../{id}/test-invoice` requires a UUID request_key, preview_token, terms_version, consent and expected_total_minor/currency/minor_units/energy_wh matching that preview. `POST .../{id}/test-invoice-resolve` requires request_key and explicit cancel_unissued acceptance; it returns the existing invoice or irreversibly cancels only an unissued request identity.

Only source-consistent completed sessions/CDRs, approved merchant authority, frozen single-component ENERGY pricing and exact configured regional currency/precision qualify. Costs requiring unsupported minor-unit rounding are rejected. Source updates, lost responses, concurrent retries and cancellation cannot create a second invoice. One canonical ChargingSession has exactly one origin: local reservation or source roaming order. No local station/reservation is fabricated. Existing FinanceService, invoice history and balanced ledger are reused; no automatic payment, production partner acceptance or physical charging is implied. Responses and previews are private/no-store. Existing invoice receipts remain readable after regional policy changes without rewriting financial history.


### Private charging invoice source evidence

`GET /api/finance/invoices/{invoice}/source-evidence` (also under the existing native `/api/v1` prefix) uses the canonical invoice customer/vendor/Admin authorization and `private, no-store`. Version1 returns invoice/energy, kind (`APP_SIMULATION`, `OCPP_SIMULATOR`, `OCPI_SANDBOX`), status (`not_source_verified`, `verified_simulated`, `requires_review`), source_verified, known protocol and receipt SHA256 fingerprints. Empty proofs serialize as an object. Applied OCPP start/final meter receipts must match stopped session/link/invoice energy. OCPI encrypted immutable consent facts, source session and CDR hashes must match the invoice. Missing/damaged proof does not gain verified status. All current evidence is explicitly SIMULATED_ONLY; physical_charging_verified, financial_records_changed, payment_authority and charging_command_authority stay false. Hashes are recorded receipts, not independent hardware attestation. No raw partner tokens, rider tags, charging credentials or source payloads are returned.


### Source-linked OCPI credit refund requests

The existing customer `POST /api/finance/invoices/{invoice}/refund-requests` accepts optional positive integer `ocpi_credit_record_id`. It must identify an immutable credit CDR referencing that invoice's original CDR under the same provider, country/party and currency. The link is evidence only: the customer must still enter the amount/reason and the vendor/Admin must explicitly review under existing captured-fund, mock/provider and ledger controls. A source reference never authorizes automatic repayment or changes the original CDR/invoice. Request replay includes the source ID; changing it conflicts. One credit CDR has one durable refund review. Scoped request history includes the source ID for web/native review; native pending intents preserve it across lost acknowledgements. Source validation is repeated before approval.

Apply additive migration2026_11_15_000001 before application rollout. It preserves existing indexes through native ADD, restricts source deletion and refuses rollback if linked financial review history exists. Unpaid invoice credit adjustments and live provider execution are separate workflows, not completed by this evidence link.

Refund history includes a version1 `source_credit_evidence` object on linked requests: invoice_id, credit_record_id, status (`source_reference_matched`/`requires_review`), financial_authority=false and automatic_refund=false. Matched references include currency and allowlisted decimal strings in reported_cost (excl_vat/incl_vat); these are partner-reported evidence, not a refund amount or financial approval. Raw source payloads and tokens are omitted. Responses use private/no-store and existing invoice-owner/vendor/Admin scoping.

## Canonical privacy requests — PR60 consolidation, 11 Nov 2026

One encrypted case service owns intake and append-only Admin review: `account_privacy_requests`. Cookie/CSRF clients (including canonical Flutter `EvApi`) use `GET/POST /api/account/privacy-requests` and `POST /api/account/privacy-requests/{id}/withdraw`. The additive bearer API uses the same controller at `/api/v1/privacy-requests` and `/{id}/withdraw`; no separate privacy table or decision engine is introduced.

`kind` is `access`, `correction` or `erasure`; `details` is a trimmed private explanation of 10–2000 characters. Optional `idempotency_key` is 12–100 ASCII letters, digits, underscore or dash, unique per account. A repeated identity with identical details returns its original receipt, including after withdrawal; changed content is HTTP409. Account row locking serializes same-owner creation; one open request per kind remains enforced. Own paginated history includes the owner's explanation and status, never internal reviewer notes, request identities or another owner's cases. Responses are private/no-store.

Only the owner may withdraw a pending, reviewing or externally blocked case; repeating withdrawal records one audit. Completed review cannot be withdrawn. An independent active web Admin reviews through existing versioned `/api/admin/privacy-requests/{id}/review` and private `/reviews` history; withdrawn/final cases cannot be reopened by stale decisions. No automated export, legal determination, account erasure or ledger mutation is performed.

Migration `2026_11_21_000001` adds receipt identities and withdrawal timestamps without dropping retained data. If a privately staged legacy `privacy_requests` table exists, bounded encrypted import preserves its archive, identity, resolution and review notes. Orphaned retained rows stay in that archive. Unknown states, unreadable ciphertext and identity collisions fail closed for protected operator review; populated identity rollback is refused outside disposable test databases.


## Opt-in station operating calendar — 12 November 2026 continuation

The existing owner-only `PUT /api/stations/{station}/information` (also `/api/v1`)
accepts optional boolean `enforce_operating_hours`. New records default to false;
omitting it preserves the stored flag, including true, for older editors. Existing
optimistic `revision` and station-owner row locks remain mandatory. Existing weekly
`open`/`close` pairs and up to50 `closed_dates` are the one calendar source. No second
booking calendar, financial rule or physical availability feed is introduced. Public
information/catalog replies include the boolean and existing unverified provenance.

When opted in, `POST /api/reservations` checks the entire half-open30-minute UTC
interval against the station's IANA timezone. Closed dates override the entire local
day, including overnight spill. A previous closed day cannot spill into the next day.
Equal opening/closing times mean that local calendar day is open24hours; null pairs
mean closed. UTC minute traversal respects repeated/skipped DST wall times and
fractional timezone offsets. Invalid opted-in policy fails closed with409.

Accepted reservations include server-generated nullable `operating_calendar_snapshot`
(revision, timezone, weekly hours, closure dates, vendor-policy label and
`physical_availability_verified:false`). Clients cannot supply this evidence. SQLite
and PostgreSQL prevent later snapshot changes. An exact accepted booking replay
returns its historical receipt even if policy changes; a new start checks current
policy again. Cancellation and active source finalization remain available.

Application simulator start, canonical CSMS start, pending RFID receipt authorization,
remote credential publication, online remote pending-start authorization and the
pre-dispatch claim all recheck current hours. A blocked pending command remains
provably unsent with `pre_dispatch_operating_calendar`, not a fake source stop or
financial finalization. Charging/stopping online callbacks and stop paths retain
their existing authority checks. Both OCPP protocol contracts are covered.

Known409 calendar errors have English/Arabic web/native messages. Partner discovery
does not gain booking or command authority from calendar metadata. Apply the additive
calendar migration before deploying this code; rollback refuses to erase retained
booking policy or active enforcement outside explicit disposable test databases.

### Canonical discovery filters
`GET /api/stations` (also `/api/v1/stations`) accepts literal `operator`, `address`, and `access` text filters. Operator/address match only explicitly vendor-published station information, never private account/KYC/provisioning records. Arabic and English address/access text is searchable. Filters compose before pagination.

`max_rate_per_kwh` is a decimal string paired with uppercase `price_currency`; currency precision comes from configured regions. It selects the latest effective simulated energy tariff, excluding tax. Unknown/future-only prices, other currencies and invalid precision do not match. This is neither a final-cost quote nor live payment acceptance.

The owner-only station-information PUT accepts optional nullable `operator_name` (100 characters), `address_en`, `address_ar` (300 each). Existing revision checks apply. Omission preserves published values; explicit null clears them. Returned labels retain vendor-reported, unverified provenance.

### Global account session revocation
Customer/vendor `POST /api/account/sessions/revoke-all` and bearer `/api/v1/account/sessions/revoke-all` require the current `password`. The authenticated account is the only target. A row-locked credential check increments its authorization epoch, rotates remember-me credentials, deletes its tokens and SQL sessions and appends a secret-free audit event. Browser sessions on other drivers fail the epoch gate. Success204 is private/no-store; guests401, unsupported roles403, invalid password422. Admin retains its separate MFA-governed route. Mobile login rechecks the epoch/password hash under the account lock before token issuance.

### Contact phone verification (disabled until configured)
Authenticated customer/vendor `GET /api/account/phone` (and `/api/v1/account/phone`) returns capability, masked verified number and resumable unexpired challenge metadata. Phone fields are encrypted and excluded from generic account serialization. This does not enable phone login or SMS2FA.

`POST .../phone/challenges`: E.164 `phone`, current `password`, explicit `consent=true`, `locale=en|ar`, stable `idempotency_key`. Replays never resend; changed numbers conflict. Limits include per-account cooldown, hourly account/target limits and unchanged authentication throttles. Uncertain delivery stays uncertain and is not automatically replayed. `POST .../phone/challenges/{UUID}/confirm` accepts a4–10 digit string `code`; owner/epoch/expiry/provider identity must match, with at most five attempts. Approval arriving after expiry cannot verify. `DELETE .../phone` requires current password and removes stored phone/challenge payloads. `accounts:expire-phone-challenges` removes up to1000 expired encrypted payloads every ten minutes while retaining replay tombstones.

Enable only with approved SMS service configuration: `PHONE_VERIFICATION_ENABLED`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_VERIFY_SERVICE_SID`. Adapter uses a fixed TLS origin, no redirects/retries, explicit SMS consent and server-side checks of provider account/service/channel/verification/recipient identity. References: https://www.twilio.com/docs/verify/api/verification and https://www.twilio.com/docs/verify/api/verification-check . Provider contract tests do not constitute real SMS acceptance.

Production phone delivery additionally requires a restricted Verify API key through `TWILIO_VERIFY_API_KEY_SID` and `TWILIO_VERIFY_API_KEY_SECRET`; root account-auth-token fallback is rejected in production. Credential scope and country/SMS activation must be approved by the owner and provider.

### Customer/vendor authenticator protection

`GET /api/account/second-factor` reports only enabled/pending status and the count of unused recovery codes. The same routes exist below `/api/v1` for non-Admin bearer clients. Reads use the bounded account-security read limit; writes use the authentication limit and require the current password.

`POST .../prepare` returns a new manual-entry secret and local `otpauth` URI once. Its encrypted pending seed expires after ten minutes and is bound to the current authorization epoch. `POST .../enable` requires a six-digit authenticator proof. Login accepts optional `second_factor_code`; after enrollment the correct password alone cannot establish either a browser or bearer session. TOTP counters and recovery-code consumption are locked and single use.

`POST .../recovery-codes` replaces the eight recovery codes; `POST .../disable` removes enrollment. Both require a fresh TOTP or unused recovery code as well as the password. Secrets and encrypted recovery digests are separate from generic user serialization and audit payloads. Every activation/change revokes existing bearer credentials and other browser epochs. A proven current browser receives a regenerated session; bearer callers receive `reauthentication_required: true` and must retain the returned one-time recovery codes before signing in again.

Web and Flutter support explicit preparation, proof, rotation, disablement and factor/recovery login in English/Arabic. Seeds/codes are memory-only, cleared on refresh/account change/background; neither surface sends a seed to an external QR service. Losing all factors requires an independently approved recovery process; this feature provides no password-only recovery bypass. Admin retains its independent mandatory MFA/recovery policy.

`accounts:expire-second-factor-setup` clears at most 1,000 expired pending seeds per run and is scheduled every ten minutes. Migration rollback refuses to drop surviving enrollment records. Automated tests do not establish real-device, store, independent security-review or production acceptance.

### Charging credential epoch and recovery-key rollover

Unsent remote OCPP/OCPI starts bind to the authenticated actor's security epoch. Logout/revocation or suspension blocks stale pending starts; old unstamped queued starts require fresh consent rather than silent backfilling. Proven unsent starts remain cancellable. Dispatched/uncertain commands remain unreplayed, and established source observations and queued stops retain their existing reconciliation rules. Private epochs are not returned to clients; history provides safe recovery notices.

New account recovery sets use encrypted format2 with domain-separated digests of128-bit random one-use codes. Legacy96-bit HMAC sets accept only the current or explicitly approved `APP_PREVIOUS_KEYS` during key overlap, and web/native status recommends regeneration. Keep required previous keys until legacy recovery sets are regenerated and encrypted data is rewrapped. Once format2 codes are issued, rollback software must retain its format2 reader; an older MFA reader is not compatible with that data.
### Read-only local CSMS connector source observation (charging 3.3.4)

`GET /api/vendor-ops/companies/{company}/chargers/{charger}/live-connectors` is an **explicit read only** from the vendor charger diagnostics view (also available from the native diagnostics screen). The authenticated operation is restricted to `local` or `testing`, enabled CSMS session bridging, an owned simulator charger, valid current staff/company read scope and one canonical `ev-test-*` binding. It does **not** contact a production charger.

It checks a unique typed station's protocol, manufacturer, model and signed-in user's current permission; reads a bounded inventory of at most 100 source connector IDs and 1000 status notifications, validates event ordering, target uniqueness and the station heartbeat, then independently re-reads station identity and current local mappings/owner authorization **after** the CSMS request. Missing connector event coverage, aged heartbeats, offline source, source/owned topology mismatch or old status cannot be promoted to `recent`. Stale observations are presented as `stale` or `unknown`, with `reported_status=null`. Duplicate source connector identities, unmapped/invalid events, clock regressions and changed ownership fail closed. OCPP 1.6 correlates by connector number without assuming an EVSE field transmitted over OCPP; OCPP 2.0.1 uses exact positive EVSE/connector numbers.

The JSON includes `evidence=SIMULATED_CSMS_SOURCE`, `company_id`, `charger_id`, `protocol`, `source_topology_matches_local`, `source_identity_rechecked`, `source_online`, `source_last_message_at`, `observed_at` and scoped `connectors` with canonical `mapping_id`, source EVSE/connector numbers, operator maintenance flag, `state`, `reported_status` (only for recent source status), `source_event_at`, `physical_available=false` and `remote_control_authorized=false`. The top-level `physical_availability_verified=false`, `remote_control_authorized=false`, `commands_sent=false` are hard-coded. This endpoint never authorizes a booking, power relay, charger command, real-world connector use or billing.

Web and Flutter show the same evidence only after an explicit user action, never poll silently, avoid untrusted HTML, and hide stale/private data when access changes. Integration testing uses two protocol fixtures, adversarial status/source identity and tenant-owner changes, and verifies **zero charging or finance writes**. A matching local status is software source evidence, not physical hardware acceptance or the whole multi-charger fleet-monitoring requirement. Cross-company, full-scale live fleet dashboards and actual approved hardware still require separate acceptance.

### Operator station availability blocks

Public `GET /api/stations/{station}/availability-blocks` lists up to 50 current/future station-wide policy intervals with bilingual public reasons, UTC timestamps, revision and explicit unverified physical availability. The same bounded public projection is included in station information/catalog responses; creator, request identity and audit details remain private. Bearer route equivalents use `/api/v1`.

Only the owning vendor can `POST .../availability-blocks` with UUID `idempotency_key`, `starts_at`/`ends_at` in exact `YYYY-MM-DDTHH:mm:ssZ` UTC format and nonblank `reason_en`/`reason_ar` (300 characters each). A new interval starts no earlier than five minutes ago, within one year, ends in the future, and lasts at most 30 days. At most 50 current/future blocks can exist per station. Replaying an identical request returns its original receipt, including cancellation; changed payloads conflict. Times/reasons are not editable: cancel then publish a new policy. Public reasons must contain no private operational/customer details.

`POST .../availability-blocks/{uuid}/cancel` requires the known revision. Natural cancellation replay is safe and does not reactivate the policy. Records/audits are retained; migration rollback refuses data loss. Station-row locks serialize publication/cancellation with existing reservation/start/pre-dispatch gates. Half-open blocks `[start,end)` apply even when weekly operating hours are advisory or unpublished. New overlapping bookings and starts fail closed; existing accepted booking receipts remain replayable/cancellable, and active charging can stop. This does not automatically cancel bookings, trigger refunds, change tariffs or send charger commands. Connector-specific blocks and wider live availability remain separate unfinished scope.

Vendor web and Flutter publish/retry/cancel against the canonical APIs. The **owner-only navigation** is Vendor Operations → Company Inventory → linked Site → **Manage station availability blocks** on web, and Vendor Inventory → Company → linked Site → **Manage station availability blocks** on Flutter. The UI uses the site's actual `station_id` and refuses to show a station editor for an unlinked site, delegated inventory staff, or a non-vendor account. Server writes independently recheck current owner role, active credentials, and current `auth_epoch` inside the transaction, even if a browser still holds an old page. An invalid or failed source refresh clears old cancellation buttons; typed source receipts must identify the same station and `STATION_WIDE_OPERATOR_POLICY` with `physical_availability_verified=false`.

Uncertain retries retain the same frozen request identity, including after a transient network failure; changing station or authenticated API invalidates that old pending identity. Flutter backgrounding clears visible policy rows; HTTP 401/419 clears private rows and notifies its parent to expire the session. Customer web/Flutter display the public intervals, labeled operator policy rather than a live availability guarantee. Read refreshes do not mutate policy. No hardware acceptance or production credit is inferred.

### Shared account sign-in budget

Browser `/api/login` and bearer `/api/v1/login` share an additional ten-attempt, one-minute account budget alongside the unchanged independent authentication/IP budget. Case-normalized, bounded email input is keyed with application-key HMAC; raw email/IP/password/factor values are not stored in the limiter identity. The limiter does not query account existence, so unknown accounts receive the same generic 429 and `Retry-After` contract. Registration, current-password actions and existing per-IP throttles retain their distinct policies. Web and Flutter use their existing localized rate-limit response. Production workers must share the configured persistent cache as required by the deployment configuration; process-local caches cannot provide distributed enforcement.

### Versioned reservation rules and logical occupancy

Station catalog and connector options publish `reservation_terms` for the supported simulator business policy. New reservations capture an immutable, server-owned `reservation_terms_snapshot`: version, UTC slot/arrival/expiry instants, station timezone, cancellation rule, zero cancellation/no-show fees, and explicit unknown physical occupancy. User input cannot supply this snapshot. Clients may send `expected_reservation_terms_version`; stale/unknown versions conflict, including receipt retries. Legacy receipts remain unbackfilled; older clients receive new terms with `client_version_confirmed: false`. That field proves only matching client policy metadata, not a legal signature or physical presence.

The 30-minute slot and 15-minute early self-arrival window match existing enforcement. Unused bookings expire at slot end only when no admitted or uncertain operation requires reconciliation; arrival remains self-reported. Cancellation remains available before an admitted/uncertain start and never interrupts active charging. No fee, refund, invoice or physical stop is automatically generated by these rules.

Future bookings remain schedulable. Starting another session on the same logical resource is blocked by an active/unknown session, an incomplete stopped record, or an unclosed source link. Canonical EVSEs remain independent; an ambiguous legacy session conservatively holds its station. The guard fixes overlapping legacy application-simulator starts and reuses station-lock ordering; natural start replay and stop/reconciliation remain available. UI rules are shown in EN/AR on web and Flutter confirmations/receipts, and supported versions are fenced into uncertain booking identities. Physical occupancy acceptance and source freshness remain separate external/software requirements.

Connector discovery adds `status_freshness` beside the unchanged `availability: unknown` and reservation capability. It projects only the exact connector's accepted observations from the current binding generation: `recent`, `stale` or `unknown`, a bounded normalized `reported_status`, UTC `observed_at`, `checked_at`, `valid_until` and a five-minute maximum age. Conflicts, future records and missing source data fail closed. No binding identity, source event, fault details or credentials are exposed. Source evidence remains `SIMULATED_SOURCE`; physical availability is unverified. Web/Flutter expire the display using monotonic elapsed time and offer explicit refresh. This read-only projection never authorizes booking/start, charges a fee or proves charger connectivity.

Authenticated customer web and native routes `POST reservations/{reservation}/price-estimate` (`energy_wh`: 1–1,000,000 integer Wh) and `POST reservations/{reservation}/price-consent` (`quote_token`, explicit `accepted`) provide simulator pre-charge review. Responses are private/no-store. Estimates use the existing canonical effective tariff, currency precision and integer half-up energy/tax calculation; all money amounts are decimal strings of minor units. A versioned encrypted confirmation is bound to the owner, reservation, account epoch, tariff fingerprint and an inclusive five-minute expiry. Acceptance appends an immutable receipt and audit event; it does not create a session, payment or journal.

New API bookings snapshot `price_acceptance_required: true`. Both application and OCPP/rider start admission verify a current receipt against the tariff captured by the existing canonical `finance_session_quotes` record, whose nullable `price_consent_id` retains the receipt lineage. Changed pricing, expired confirmation or account revocation requires fresh explicit review; existing-session replay and safe stop/reconciliation retain their previous behavior. Historical bookings are not falsely backfilled with acceptance. A standalone review action supports owner approval before a simulator rider tap. Estimates disclose zero simulator session/idle fees, exclude external parking/access charges, vary with the final meter, and never imply a spending limit, automatic stop, production tariff or real payment. Production pricing/PSP/physical acceptance is not established.
### Source-observed status duration accounting

Charger source diagnostics include `status_durations` for the current immutable source generation over the preceding 24 hours in UTC. Each persisted component observation contributes at most 300 seconds, clipped at the next observation/window boundary. Silence, unsupported states and conflicting statuses in the same second remain unknown. Reported errors count as fault observations. Components remain separate; neither heartbeats nor source online flags fill gaps. These are bounded simulator-source observations, not physical uptime percentages, availability guarantees or charging authority.

Analysis refuses partial claims above 12,000 observations or 64 components and returns `requires_review` with no component totals. Web and Flutter show source seconds and unknown gaps; authorization, mapping fences and no-store diagnostics remain unchanged. No command, fee, capacity update or physical acceptance is inferred.

### Provider dispute inbox receipts

New or changed test-provider dispute status, deadline, reason or settlement-hold receipts create in-app notices for canonical invoice parties in the same transaction. Replayed events and unchanged reconciliations do not emit duplicate notices. Read/counter/read-state queries recheck current active customer/vendor role and current invoice lineage; malformed or non-test proofs stay hidden. Notices contain local record IDs only and never send email/push, submit provider evidence or create financial postings. Web opens the financial workspace; Flutter opens the exact scoped provider receipt.

### Native vendor campaign controls and creation recovery

Campaign receipts include decimal-string `control_revision`. Updated web/native lifecycle decisions send `expected_control_revision`; under the campaign lock, stale revisions conflict without posting a decision. Legacy clients may omit this additive field. Vendor creation/submission/pause/resume/cancel reuse the canonical paid-package limits and require current role, active account, authenticated epoch and password snapshot. Admin approval/rejection/suspension/reinstatement retain the existing protected web workflow; Admin bearer tokens remain forbidden.

Flutter freezes and encrypts the exact creation intent before dispatch, retains its identity across unknown responses/restart, and clears private UI data on background/account changes. `POST /commercial/campaign-intents/cancel` accepts the same owning vendor's `idempotency_key`: it recovers an already-created campaign unchanged, or seals an unused key against delayed creation. It never cancels an existing campaign or payment. Negative receipts and audits persist; rollback refuses to erase them. Creation and resolution serialize on the vendor lock; all backend writers must support the seal before publishing this native recovery workflow. Existing campaign enablement and signed delivery controls stay unchanged; no additional cash charge or human-impression proof is introduced.

Scoped session history now includes `cost_projection`, a read-only rollup from the captured canonical session quote and, when consistent and issued, its invoice. Integer energy/tax rounding and configured 0–3 currency precision match billing; amounts remain exact decimal strings of minor units. Missing/unsupported quotes, inconsistent source counters or invoice lineage and unsafe arithmetic produce unavailable estimates. Source observation time and freshness are reported as of the read; manual simulator meters are explicit. This is neither a live-connectivity claim nor a spending cap, payment status or automatic stop. Customer/vendor/Admin scope is unchanged, queries are batched, and history responses are private/no-store.

English/Arabic web and Flutter history distinguish recorded estimates from actual simulated invoice totals and avoid displaying a pretax energy component as the final total. Native session history clears private data on background and discards callbacks or confirmations belonging to an earlier account/view. Refresh is explicit; no background polling or command replay is introduced.

### Approved OCPI catalog reception and withdrawal

Managed CPO → EMSP connections may explicitly configure `incoming_modules` with `locations`, `tariffs`, `sessions`, `cdrs`. The allowlist remains pinned in the credential configuration fingerprint; changing it requires approved credential exchange. No modules or live charging controls are enabled by this code change. Credential status and web/Flutter show configured versus active reception. Suspended owners cannot make new catalog changes or rotate/enroll credentials; existing authenticated session/CDR evidence and safe unregister/revocation remain available.

The negotiated OCPI 2.2.1 receiver accepts `GET/PUT/PATCH locations/{country}/{party}/{location}/{evse?}/{connector?}` and `GET/PUT/DELETE tariffs/{country}/{party}/{tariff}`. Peer party, token generation, approved modules and source identity are checked again under canonical provider/link locks. Partial component updates require explicit source timestamps, preserve unrelated components and reject ambiguous IDs, missing PATCH targets, stale records and same-time conflicts. Out-of-order component changes require a newer complete source publication; no timestamps are invented. Removed EVSEs remain historical observations and are excluded from offered connector standards.

Tariff DELETE appends an immutable retirement receipt with encrypted source snapshot, preserving the canonical record and financial references. Current protocol GET then returns not found; operator web/native lists label it historical. Replays cannot reactivate the tariff: pushed or polled publication must be newer than both the retirement receipt and its source timestamp. Every backend writer must support this retirement guard before enabling partner withdrawal. No payment, invoice, booking or local command is created. Imported tariff prices remain non-billable and availability remains unknown.

Authenticated web and `/api/v1/ocpi` operations share one route map. Native Admin bearer tokens remain forbidden; Admin administration retains its protected web session. Existing customer discovery and vendor/Admin/Flutter record readers consume these same canonical records and public projections exclude private extensions. This is bounded software functionality, not live partner conformance, physical charging acceptance or completed CPO/eMSP implementation.

Retired tariff hashes also fence sandbox offers and first-time IdToken grants, including after later republication. Previously established, exact authorization receipts and safe stop/source reconciliation retain their original behavior. Withdrawal never releases uncertain charging capacity or retries a command.

### Vendor agreement publication and owner acceptance

`vendor-ops/companies/{company}/agreement` is a private, owner/Admin-only current-country review and paginated history. Protected web Admin `POST /publish` requires the current password, bilingual text, publication reference, explicit publication approval and `expected_revision`. An identical lost-response retry recovers the version; competing revisions conflict. `POST /withdraw` requires the current password, version ID, expected revision and confirmation; it stops new acceptance country-wide while preserving published text and receipts. No document is seeded or approved automatically. Admin bearer tokens remain forbidden.

Owner web/native `POST /accept` binds explicit acceptance to the current version UUID and SHA-256. Country, ownership, account epoch and password-snapshot changes are fenced under locks. A repeated exact acceptance returns its existing receipt. `GET /receipts/{uuid}` reads that company's historical immutable document/receipt, including after a newer publication or withdrawal. Company evidence is encrypted at rest; no browser/native persistence is added. This records software evidence, not verified legal acceptance, KYC approval, financial or charger authority; existing onboarding/charging/payment gates remain independent.

Authenticated `GET /api/discovery/search` and `/api/v1/discovery/search` search published local platform inventory and already-authorized OCPI location reports together. Optional `q` (80 characters, literal name/address/operator text), `scope=all|catalog|partner`, and normalized `connector=CCS2|Type2|CHAdeMO` apply before a single 25-result SQL-union pagination. Stable `catalog:{id}` and `partner:{id}` keys preserve supplier identity even when IDs collide; ordering is descending local ID then source kind, without asserting chronology or deduplicating physical sites. Hydration is bounded to the selected page. Partner authorization/publication is checked again before projecting the allowlisted public location and source timestamps. Unknown availability and false booking/control remain explicit; no provider lookup, credential, private payload, price comparison, physical availability or actionable roaming is inferred. External discovery-provider queries retain their separate consent workflow. Unified, partner-search and available saved-partner rows share `catalog_checked_at`, `reported_source_age_minutes`, `received_age_minutes` and timestamp-evidence fields. Ages are whole minutes at response time; missing or future source timestamps never become zero-age reports (including subsecond future clocks). Receipt age describes local ingestion separately and cannot freshen an old source report. Revoked/unpublished saved rows redact all these fields. Web/Flutter unified cards flag reports at least 24 hours old and label unknown clocks explicitly; these are catalog-age warnings, never verified physical availability. Search again to recheck. Optional `max_source_age_hours` (integer1–168) bounds partner source timestamps before union pagination, rejects future clocks and leaves platform results unchanged. Web/Flutter offer one hour,24 hours or7 days; pagination preserves the submitted choice. Receipt timestamps cannot satisfy this filter.

Web and Flutter provide explicit EN/AR unified search and pagination. Platform results open existing station details/connector/booking controls through the additive exact `station_id` catalog selector; partner cards are read-only. Native foreground/account fences discard late results and clear them on background. Responses are private/no-store, searches stay in memory, and no financial or charging writer is called.

Unified search includes an explicit current-page map handoff. The web reuses its existing source-separated Leaflet layers and tile-consent gate; map selection clears the unrelated external-provider layer. Flutter reuses the report-map renderer with distinct platform provenance and unverified partner labels. Invalid/polar coordinates stay in text results. Maps load no tiles until explicit consent, acquire no GPS and make no additional provider/catalog lookup. Map details grant no charging or billing authority.

Unified discovery also accepts customer-only `saved_only=1`, filtering both existing favorite tables before union pagination. It returns each customer's own `is_favorite` state, never another account's preferences. Current account status, role, epoch and password snapshot are rechecked around private reads. Revoked/unpublished partners stay excluded even if previously saved; their established saved-partner management view retains removal access.

English/Arabic unified web/native cards save/remove through the existing canonical `favorites/{station}` and `roaming/favorites/{record}` writers; no new preference storage or mutation endpoint exists. The typed identities remain separate when IDs collide. Successful writes reread authoritative state. Unknown outcomes block further star actions until an explicit search refresh; background/account changes discard callbacks and never replay a write. Flutter binds the view to its opening account fence so it cannot acquire the next account's private state or mutate its preferences.

Station and unified searches accept `recent_source_status` using the ten bounded connector-report states. Filtering runs before pagination against the exact mapped connector, current binding generation, OCPP protocol/component identity and canonical ownership/approval/local-license rules. Connector/power conditions apply to that same connector. Only accepted observations newer than five minutes and not in the future qualify. A newer/future report, same-instant conflict, unsupported state, future receipt or changed binding suppresses an older claim; reported errors normalize to Faulted. PostgreSQL uses its native SHA-256 over the same server-owned binding tuple as the canonical fault observer; SQLite calls that existing fingerprint function directly. Both are tested with OCPP1.6 and2.0.1 mappings and local license-day boundaries.

The EN/AR filter is explicitly an as-of-search simulator-source report, excluding OCPI partner reports. `availability` stays unknown and every booking/start gate is unchanged. Search again to recheck; list membership never proves physical availability or grants charger control.

Managed OCPI credential register/rotate/unregister and incoming preparation retain the original authenticated Admin snapshot. Current role, active status, security epoch and password hash are checked before discovery/claim and again under the Admin→provider lock order immediately before one-shot transport. Revocation during discovery denies the stale request without creating credentials. Revocation after claim conservatively quarantines the operation under the existing unknown-outcome policy; a newly authenticated operator can recover the receipt without resending. Completed partner approvals and historical source reconciliation are not retroactively rewritten.

### Observed site interval demand

The existing authenticated company operational analytics read and CSV/PDF exports
now include `maximum_observed_site_interval_average_w`,
`demand_observed_seconds`, and `demand_ambiguous_evse_seconds`. The maximum
is nullable when no usable accepted meter intervals exist; observed zero
remains zero. The calculation sums floored integer average watts from accepted
session meter intervals wholly inside the UTC report window. It uses the same
canonical current binding/topology and tenant scope as the energy report.
Concurrent intervals from different EVSEs contribute together; overlapping
sessions on one EVSE are excluded during their ambiguous overlap. Missing
EVSE telemetry is not inferred to be zero. Coverage is partial, not a whole-site
instantaneous peak or grid-meter demand measurement. `physical_site_demand_verified`
is always false and `billing_authority` remains false. EN/AR web, PDF and native
views preserve these distinctions. No ledger writes or provider calls occur.
Oversized exact-number sums return409 rather than rounding or overflowing.

The operational report separately exposes `reported_non_fault_unavailable_seconds`.
It counts accepted simulator connector `Unavailable` time without an error
code, bounded by the next observation and the existing 300-second freshness
window. Reported fault time is counted separately. Conflicting observations
and stale gaps contribute to unknown time. These metrics do not establish
physical downtime, uptime, or operational availability.

### Private Admin platform operations map

`GET /api/admin/operations/map` is web-session Admin only, behind the existing
Admin second-factor gate. It rechecks active role and credential epoch/password
under a user lock. It returns25 platform inventory points per page, selected
before projection, with allowlisted company review labels and inventory counts.
No KYC documents, user contacts, charger identifiers, provider secrets, session
identities or controls are included. It grants no charging or payment authority.

Filters: literal station-name `q` (80characters), two-uppercase-letter `country`,
`company_id`, `page`, and `recent_source_status` using the existing exact-current
five-minute accepted simulator-report policy. Status matching does not establish
physical availability. Optional `south`, `north`, `west`, `east` must be supplied
together; west greater than east explicitly crosses the date line. Invalid
station coordinates are excluded. Company ownership mismatches have no company
projection and cannot satisfy the company filter. Responses are private/no-store.

The EN/AR Admin interface reads only on explicit search, keeps pagination tied
to submitted filters and clears private results on account/background changes.
Self-hosted Leaflet renders only the loaded page. OpenStreetMap tiles require
explicit consent; moving the map makes no API request, and there is no GPS lookup.
Polar coordinates outside Web Mercator stay in the text list. The map covers
platform inventory across companies; it does not assert partner-network coverage,
physical connectivity or live operating acceptance.

### Optional generic notification email

Authenticated web `/api/notifications/email` and native `/api/v1/notifications/email` support GET settings and PUT `{enabled, confirmed, expected_revision}`. The revision is a decimal **string**; enabling requires explicit consent, a verified account email and configured server transport. No recipient override is accepted. Disabling cancels queued digests. GET `email/history?page=1` returns only the current account's paginated generic handoff receipts; transport acceptance never confirms delivery. Existing in-app preferences remain a separate contract.

`NOTIFICATION_EMAIL_ENABLED=false` is the default. Approved production configuration requires a public HTTPS root `APP_URL`, SMTP/TLS and a valid sender. No SMTP credentials enter client responses. The scheduler runs bounded `php artisan notifications:handoff-email --max=100`; use the existing scheduler, not a second sender. Local/testing accepts only the in-memory array transport. Users consent to new unread activity only, up to one generic digest per 15 minutes, excluding activity older than 24 hours. Email contains no notification details or authentication tokens. Changed account epoch/role/email/transport invalidates consent. A durable claim is committed before handoff; claimed or unknown outcomes are never automatically retried. Inspect receipts for interrupted operations; do not replay them. Actual email receipt and production activation require separate operator acceptance.


### Consented scheduled OCPI module polling

Web `/api/ocpi/providers/{provider}/sync-plans` and native `/api/v1/ocpi/providers/{provider}/sync-plans` GET return current plans and 25 private run receipts per page. PUT `sync-plans/{locations|tariffs|sessions|cdrs}` accepts `enabled`, explicit `confirmed` consent when enabling, `interval_minutes` (15,30,60,360,1440), and decimal-string `expected_revision`. Only the current owning vendor or protected web Admin may manage plans; Admin bearer access remains forbidden. Recipient URLs/tokens cannot be supplied by clients.

`OCPI_SCHEDULED_SYNC_ENABLED=false` defaults off. The existing scheduler runs `ocpi:poll-approved` once per minute, at most one negotiated module per invocation and one in-flight scheduled poll per provider. Approved connection metadata, active provider owner, current actor role/epoch, partner authorization revision and credential/configuration fingerprint are checked before reads and again before canonical ingestion. No database lock spans the HTTP pull. Opt-out during a pull prevents ingestion. Changed authority requires renewed explicit consent. Existing PartnerClient pagination/record/origin/credential limits and RecordSynchronizer replay, retirement and immutable CDR rules apply unchanged. No charging command, reservation, invoice or financial posting is performed.

A failed read waits for its next configured interval; the error receipt contains only fixed codes. A claim interrupted for 30 minutes disables that plan and records operator review. Renewing consent starts a new run identity; it never silently resumes an interrupted request. History and outcomes remain partner-reported/unverified and do not establish physical operation or commercial acceptance. Configure and accept actual partner polling separately; local tests fake every partner request.


### Signed firmware compatibility paths

A configured firmware entry may now include `compatibility: {protocols: ["1.6","2.0.1"], from_versions: ["vendor-version"]}` and `compatibility_signature` (base64 SHA-256/OpenSSL signature by the same independently pinned, current certificate that signs the firmware bytes). The manifest message is `creabiz-firmware-compatibility-v1\n` followed by compact UTF-8 JSON in this exact key order: `catalog_key,manufacturer,model,version,sha256,protocols,from_versions`. Unicode/slashes are unescaped; both lists are sorted with bytewise string order, unique and bounded (two protocols,20 exact source versions). No ranges, wildcards or inferred version ordering are accepted. Firmware source versions use1–80 ASCII letters/digits/dot/underscore/plus/colon/dash, beginning with a letter or digit. Changing any bound identity/policy invalidates the signature; a changed approved policy requires a new catalog identity.

Approval verifies both signatures and stores the manifest fingerprint with the immutable release. Vendor/Admin web and Flutter expose the approved protocol/source-version policy; releases without this optional policy retain the existing model-only path and are labelled accordingly; no compatibility attestation is backfilled. At claim and again immediately before transport, the backend verifies the stored policy and matches current canonical source manufacturer/model, protocol and firmware version. Missing/mismatched source data denies new claims. If the live process catches a preflight failure before entering the firmware HTTP call, it durably records `not_dispatched`, revokes the unused download capability and keeps the local maintenance hold until an authorized owner/Admin explicitly cancels with a note. This positive application proof permits cancellation without fabricating device evidence. A crash, an entered firmware HTTP call, or any uncertain transport still retains the existing unknown/held state and cannot use this cancellation path. Exact retries return the original outcome without sending again. Operations expose the manifest fingerprint for lineage. Existing pinned-certificate, signed-byte, company, idle/session/reservation, one-shot dispatch and correlated review safeguards remain. This software remains within the existing disabled-by-default local simulator adapter; no physical upgrade, manufacturer approval or production enablement is asserted.

### Private process and queue observations

`GET /api/admin/operational-health` is a current-credential Admin web-session
read behind the existing second-factor gate. It reports only process observation
timestamps and aggregate counts for the configured default queue. Payloads,
exceptions, connection strings, queue names and user/job identities are never
returned. Viewing the report emits no heartbeat or retry.

A real Laravel worker loop records `queue_worker` only when it consumes the
configured default connection/queue; writes coalesce per process for30seconds.
The scheduler runs `platform:health-heartbeat` every minute. Observations expire
at150seconds. Changed worker scope, future/missing observations and unavailable
storage remain unknown. A recent loop is not proof of successful jobs, every
worker, charger connectivity or production readiness. All four verification
flags are false. Database queues expose ready/reserved/delayed/failed counts
and oldest-ready age; other transports remain unsupported/unknown instead of
reporting zero. Missing monitoring storage never stops the worker or logs SQL
secrets. `OPERATIONAL_HEALTH_ENABLED=false` disables observations.

The EN/AR Admin view refreshes explicitly, labels the snapshot time and clears
private results on account/background changes. It offers no retry, delete,
charger, payment or external-probe actions. Public health contracts are unchanged.


Loyalty HTTP requests recheck the original active account role, credential epoch
and password snapshot under a user lock before scoped reads or awards. A revoked
Admin cannot retain global redemption visibility or award access through an old
request snapshot. Existing publication and fulfillment service guards remain.

### Private authenticator security notices

Successful customer/vendor authenticator enablement, disablement, recovery-code replacement and individual recovery-code consumption create private `account.security.enabled|disabled|recovery_rotated|recovery_used` inbox notices in the same database transaction as the change. Preparation, failed proofs and rolled-back changes create no security notice. Metadata contains only `evidence=ACCOUNT_SECURITY_EVENT`; secrets, codes, hashes, IP addresses and device details are excluded. Existing scoped inbox read/unread controls apply. Web and Flutter render static English/Arabic security guidance. These in-app notices do not assert external delivery or successful login; the separate consent-bound generic email digest retains all existing configuration and account-epoch restrictions.

Saved platform and partner favorite actions also lock and recheck the authenticated customer's current role, active status, credential epoch and password fingerprint before any preference read or mutation. Role/status revocation rejects403; changed credentials reject409. The entire bounded database action serializes with revocation. This never grants booking or charging authority.

### Private fleet operations report

`GET /api/fleets/{fleet}/report` and native `/api/v1/fleets/{fleet}/report`
require the current active fleet owner or protected web Admin (Admin personal
access tokens remain forbidden). Required `from` and `through` are inclusive
`YYYY-MM-DD` fleet-local dates, at most 366 days. Optional `cost_center_id` must
belong to that fleet. Unknown regional timezone fails closed. Optional
`format=csv` returns a private, formula-safe CSV; default JSON returns current
inventory counts, applied local/UTC bounds, observation time, and historical
cost-center/currency/precision groups from approved claims **submitted** during
the selected interval. Approval is evaluated at read time; no unshared personal
invoice or session history is queried. Inventory covers the entire current fleet
and is independent of claim-period/cost-center filters. More than 2500 matching
claims requires narrower filters, with no silent truncation. Amounts are exact
unsigned decimal strings in minor units, separately grouped by precision and
currency. Recorded claim totals are not balances, cash collections, refunds,
settlement or proof of physical charging. No financial or charging record is
created. Web downloads the displayed CSV snapshot; Flutter previews a selectable
private CSV in memory and clears it on background/account change.


### Current-account RFID registry and publication authority

All customer card/grant reads and database changes recheck the captured active
account's role, password and authentication epoch under a User-first transaction.
Card issue and revocation also enforce this when invoked through their service.
Operator source publication runs separate short authorization/claim/commit
transactions, with no HTTP inside a transaction. Before creating or accepting a
source authorization it rechecks the current account, approved licensed company,
original token expiry, active approved grant and unexpired matching publication
lease. Credential changes after a source write prevent local promotion and leave
`requires_inspection`; this never marks a session started or paid and never
retries an uncertain publication automatically. Web and Flutter require explicit
publication confirmation and explain this inspection state. All existing local
adapter, real-time rider callback, no-offline-fallback, token privacy, source
ownership/CAS and hardware-unverified boundaries remain required.


### Explicit retirement of inactive test-source RFID registrations

`POST /api/vendor-ops/companies/{company}/rider-tokens/{token}/retire-source`
(and native `/api/v1/...`) lets the current owning vendor or protected web Admin
inspect and retire an owned **globally revoked or expired** token registration.
The operator must have an approved licensed company with an existing grant for
that token. An otherwise active token cannot be retired merely because one
company withdrew approval; this protects other companies sharing the registry.
The local adapter/capability proof and no-offline-fallback gates remain required.

A short leased claim precedes HTTP. The source identity/tenant/registry nonce is
checked before a compare-and-set update to Blocked, followed by observed source
verification and current-account/company/token/lease revalidation. Absent or
already blocked identities require no source mutation. No source identity is
created or deleted, no charging command is sent, no session is closed and no
payment is processed. Account or source drift and uncertain transport leave
requires_inspection without automatic retry. Customer activity records requested,
blocked/absent observations or inspection-required events without raw identifiers.
The operator list adds UTC ISO8601 `token_expires_at` for safe expiry presentation.
Web/Flutter offer explicit confirmation and label retirement as a source snapshot,
never physical charging-stop evidence. Response includes observation time,
`source_retirement_observed`, `source_record_absent`, and false
`active_sessions_stopped`/`hardware_verified`.


### Private Admin fleet directory

`GET /api/admin/fleets` is protected web Admin only (no Admin mobile route).
Optional `q` is a literal name substring (80 characters; SQL wildcards escaped),
`country` an uppercase two-letter filter, and `page`1..1000000. Filters apply
before pagination at25 rows. Only fleet ID, name, country and creation time are
projected; no owner email, driver list, personal invoice/session or ledger data.
The current active Admin role/password/auth epoch is checked under lock. Private
no-store replies and EN/AR directory controls retain applied filters across pages,
open the selected authorized fleet report and fence account/background replies.


### Stored partner catalog observations

`GET /api/ocpi/providers/{provider}/catalog-observations` and native `/api/v1/ocpi/...`
are current active owning-vendor/protected web Admin reads; Admin personal tokens
remain forbidden. Optional `module=all|locations|tariffs` filters before the bounded
10000-record scalar projection. Larger results reject422 with no partial counts.
No encrypted upstream payload, session/CDR, credential, endpoint, customer identity
or raw coordinate is selected/returned. Private no-store responses contain current
provider ID/name/authorization, snapshot UTC time, module totals, disjoint source-
report and local-receipt age buckets, stored publication/coordinate/list quality
and current-hash tariff retirement counts. Future clocks (including fractions of
a second), invalid or missing clocks are never current observations. Buckets are
within1h, over1h through24h, over24h through7d, over7d, unknown and clock-ahead.

A recent receipt never refreshes source age. Coordinates usable for rendering are
not geographic accuracy; stored publish flags and connector lists are not live
availability or supported charging. Unauthorized providers show private archived
records only. Physical/live-availability/remote-control/billing flags remain false.
The EN/AR web/vendor Flutter view reads explicitly, selects a module, clears on
background/account/target changes and never dispatches a partner request, booking,
command or payment. This report does not activate discovery providers or roaming.

### Partner catalog-age operations

The private owner/Admin `GET /api/ocpi/providers/{provider}/sync-plans` response includes `catalog_health` under the existing current-account and provider lock. Only this provider's published location records are counted. A bounded server policy (`OCPI_CATALOG_MAX_AGE_SECONDS`, default86400, valid60–604800) classifies source-update and local-receipt age: both must be within policy, not future; age equal to the limit is stale. A new receipt cannot freshen an old source report. Missing/future age remains unknown; future clocks are a subset of unknown. Counts partition published records and expose no payload, location, credential or customer data. Revoked providers/inactive owners return unavailable counts; invalid policy returns `policy_unavailable`, never invented zeros. Web/Flutter polling views show the same EN/AR read-time summary with explicit refresh. No polling consent, synchronization, tariff, session, billing or charging authority changes, and no physical availability or external delivery is verified by these observations.

Successful browser authenticator enable/disable/recovery-code replacement responses include the newly rotated `csrf_token` alongside `reauthentication_required=false`, under private/no-store headers. The web API accepts this handoff only for those proven mutations in its current account context, without replay. Native responses require reauthentication and return no browser token. This preserves session/CSRF rotation while allowing the next legitimate browser action.

The same validated policy also generates each available partner result's `catalog_age_policy` in unified search, partner search and saved places. English/Arabic web and Flutter views show its read-time state and explicitly unverified physical availability. Both source and receipt timestamps must be inside the configured limit; future/missing clocks stay unknown and exact expiry is stale. Private saved rows redact the policy after publication or provider authorization is withdrawn. Caller-selected source-age search filters remain separate from this server policy and never confer operational authority.


Browser MFA mutations fence earlier private requests before session rotation and
reject concurrent unrelated sends until the attempt settles. Only a successful,
current-account MFA response may hand off its rotated CSRF token. A safe inbox
read then rebuilds epoch-bound controls; it never acknowledges notices or retries
a mutation. Logout/account replacement invalidates the pending transition, so an
older completion cannot change the new account or its token.


### Current-request roaming authority and explicit unsent deactivation

Private roaming reads validate the captured request's role, password hash,
security epoch and active status under the user lock; an old request cannot
reload its user to acquire fresh authority. Provider operations history belongs
to the current owner and the original order owner; protected Admin history
remains available. Reassignment does not transfer previous customer records.
Start/stop/revoke HTTP calls remain outside application database transactions.
Stop validates the same actor and bound active source session again immediately
before transport. A known pre-wire failure retains `not_dispatched` with no
attempt marker: exact replay never resends, but a new explicit stop identity may
be confirmed. Any attempted/transport/callback evidence continues to block an
additional stop, even if a contradictory state claims `not_dispatched`.

`can_complete_source_revocation` permits explicit customer confirmation of
local authority deactivation that never reached source transport. Only immutable,
hash-matching `valid=false` token bytes with no attempt/receipt/callback facts
may be reused. Prepared, dispatching and unknown outcomes never retry. Web and
Flutter show a separate completion action; cancel sends nothing. Local revoke
and source token deactivation never imply that an active session stopped,
release an active EVSE hold, or authorize billing/physical charging.


### Recorded connector fleet

Owner vendor/protected web Admin `GET /api/charging-operations/connectors`
(native `/api/v1/...`, existing native Admin restriction preserved) provides
25 mapped connectors/page, stable ascending mapping IDs, optional positive
`station_id` and bounded `page`. Current captured role/password/epoch/status
is checked under user lock. Foreign station filters return404; station transfers
cannot expose prior-owner source evidence. Canonical topology/license/maintenance
checks and existing `ConnectorFreshness` generation/component rules qualify each
source snapshot. Exact five-minute expiry is stale; missing, future, conflicting,
replaced or blocked source stays unknown. No credential, raw payload, customer,
transaction or billing record is projected and no upstream read is performed.

EN/AR web and Flutter use explicit filtered reads and frozen-filter pagination.
Account/background changes discard cached and in-flight snapshots; resume does
not poll. Reports are labeled with snapshot/source timestamps and simulated
source evidence, never current physical availability, connectivity or command
permission. This observation page does not replace topology or admission checks.


Fleet rows may include `operations_target={company_id,charger_id}` only when the
current station/company/site/charger ownership is consistent. This is a navigation
hint, not a control grant; blocked connectors may still need diagnosis. Explicit
web/native selection opens the existing scoped source diagnostics/repair workspace.
No fault acknowledgment, inspection, reset, firmware or maintenance change occurs
merely by reading a fleet snapshot. Reassignment removes previous-owner targets.


### Private source meter observation history

`GET /api/roaming/sandbox-orders/{order}/meter-history` and provider operator
`GET /api/ocpi/providers/{provider}/sandbox-orders/{order}/meter-history`
(native equivalents under `/api/v1`) return accepted source Session versions
for the original dispatched token/authorization/location/EVSE/connector lineage.
Current captured account status/role/password/epoch is checked under user lock.
Customer order ownership or current+original provider vendor ownership is required;
protected Admin reads remain permitted. Reassignment never transfers customer readings.

Responses contain only source status, bounded exact kWh text, exact nullable Wh,
source/receipt timestamps and regression/precision/clock flags. They do not expose
tokens, authorization references, source payloads or hashes. Pagination25 accepts
bounded `page` and optional `through` (returned `through_id`) to freeze the accepted
version window across pages. A fresh read chooses a new window. Old versions are
not invented/backfilled; duplicate/stale records never refresh receipts/history.
Each accepted ingestion version appends atomically with the canonical record;
failed batches roll everything back. High-water and review indexes bound lookups.

A detected meter decrease or unrepresentable Wh precision remains a reconciliation
issue for that source lineage even after a later value rises. New test-invoice
consent cannot bypass this through matching final CDR totals or cached order state.
Existing invoices, ledger amounts, physical controls and capacity-release rules
are unchanged. A proven owned active session remains stoppable despite meter review.

Customer/vendor EN/AR web and Flutter history views use existing order workflows,
explicit reads, frozen pagination and account/background fences. The actual synthetic
CPO/browser/native proof covers active/completed readings, stop, privacy and revocation.
`physical_energy_verified`, `billing_authority`, `commands_sent` and `backfilled`
remain false. No real partner, physical energy or production acceptance is asserted.

### Approved sandbox OCPI reservation lifecycle

`POST /api/roaming/locations/{location}/sandbox-reservations` accepts the existing offer fields with a UUID `request_key`, exact `offer_hash`, separate `terms_version=sandbox-reservation-preauthorization-v1`, explicit `consent`, and `duration_minutes`5/10/15. This requires local/testing, the existing approved managed sandbox command contract, and an additional server-only `test_reservations:true` plus bounded `test_reservation_contract` in the credential fingerprint. Existing disabled fingerprints remain unchanged; configuration changes require managed agreement reconciliation. No production/live switch is added.

The canonical order holds the same shared EVSE capacity as START_SESSION. A single encrypted, immutable RESERVE_NOW carries the generated reservation UUID, server UTC expiry and preauthorized owned APP_USER token. Consent explicitly discloses that the partner may transition its RESERVATION session to ACTIVE without another START_SESSION. Source lineage, private meter history, reconciliation and the existing explicit STOP_SESSION remain authoritative. Reserving does not create a local charging session, reservation fee, invoice or payment. Existing energy-only TEST billing refuses reservation orders.

`POST /api/roaming/sandbox-orders/{order}/cancel-reservation` requires a new UUID and explicit consent. It first revokes future local token authority and uses the existing one-shot partner token-deactivation path, then requests CANCEL_RESERVATION for the exact reservation UUID. Known active/completed owned source sessions require reconciliation and the existing stop workflow. Cancellation never sends a stop. Replays return existing receipts; uncertain transport is never resent or replaced. A CANCELED_RESERVATION notice on the original reserve callback is separately immutable and cannot replace its original final receipt.

Private reconciliation releases unused capacity only after accepted source deactivation and authenticated final cancellation/notice or expiry, plus a changed, fresh, published AVAILABLE source location newer than the relevant final callback/notice/expiry, with no unresolved owned source session. Local expiry, preliminary command acceptance and token revocation alone never release capacity. Completed source transactions retain their existing completion handling; a new offer requires fresh post-closure location evidence. Customer web/Flutter expose separate consent/duration/cancel controls; Vendor/Admin history remains read-only. All displays retain sandbox/unverified/nonbilling labels.


## Read-only Admin command credential storage batches

`GET /api/admin/command-secret-storage?after=0&limit=25` requires the current active web Admin session and applicable Admin MFA; persisted Admin personal-access tokens are rejected. The authority snapshot is rechecked under a transaction/User lock. Limits1–100 and cursors0–9007199254740991 are validated. Payload/confirmation text is bounded before hydration; fields over65536bytes, unreadable ciphertext and unsupported payload shapes are counted without returning their contents.

The private no-store response returns scope `bounded_command_secret_storage`, UTC `as_of`, `after_id`, `limit`, `max_field_bytes`, `rows_examined`, mutually exclusive encrypted/legacy/unreadable/unknown-shape/no-known-secret row counts, encrypted/legacy known-field counts, `has_more` and `next_after_id` (0at exhaustion). `whole_table_verified`, `mutation_performed` and `production_acceptance` are alwaysfalse; `commands_dispatched` and `network_requests` are0. A batch is not proof about all rows, free text, provider storage, backups or production security. No write/sealing HTTP endpoint is added. Use existing explicit operator console tooling and its key/recovery procedure for authorized changes.

## Company-scoped recorded charging sessions (19 November 2026 candidate)

`GET /api/vendor-ops/companies/{company}/charging-sessions` and authenticated native `/api/v1/vendor-ops/companies/{company}/charging-sessions`: existing owner/Admin authority or current verified, accepted `charging.read` company membership. Existing grants are not upgraded; changed permissions require exact-version recipient consent. Accepts page1–100000 and optional recorded status;25 rows per page descending session ID. Current unique site linkage and station ownership define scope. DTO contains recorded status/Wh/times, station name/ID and source qualifier, with explicit false flags for customer identity, financial fields, controls, physical acceptance and live availability. No money/customer/vehicle/reservation identity columns are selected. No-store/private GET, no dispatch or financial write. See [VENDOR_SESSION_OBSERVATIONS.md](VENDOR_SESSION_OBSERVATIONS.md) for full bounds, consent, UI and evidence limitations. Complete staff control parity remains unfinished.

## Consented company CSMS meter history — 20 November 2026 continuation

`GET /api/vendor-ops/companies/{company}/charging-sessions/{session}/meter-history` and the authenticated native `/api/v1` mount reuse the accepted `charging.read` company grant. Bounded numeric routes, page1–100000 and optional safe `through` snapshot cursor;25 descending applied source events/page. Current owned company/site/station and matching binding lineage are rechecked. Exposes cumulative counter Wh and retained UTC source/receipt timestamps, not customer identity, source credentials, consumed/billed energy or money. No link yields explicit empty history; stale events are excluded. Pagination fixes the initial upper ID and explicit Refresh captures later appends. All complete-history/billing/physical/command flags are false; no network call, command or financial mutation. See [VENDOR_METER_HISTORY.md](VENDOR_METER_HISTORY.md), including retained PostgreSQL timestamp precision and current-source hosted acceptance requirements.
