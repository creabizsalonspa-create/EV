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
### Operator station availability blocks

Public `GET /api/stations/{station}/availability-blocks` lists up to 50 current/future station-wide policy intervals with bilingual public reasons, UTC timestamps, revision and explicit unverified physical availability. The same bounded public projection is included in station information/catalog responses; creator, request identity and audit details remain private. Bearer route equivalents use `/api/v1`.

Only the owning vendor can `POST .../availability-blocks` with UUID `idempotency_key`, `starts_at`/`ends_at` in exact `YYYY-MM-DDTHH:mm:ssZ` UTC format and nonblank `reason_en`/`reason_ar` (300 characters each). A new interval starts no earlier than five minutes ago, within one year, ends in the future, and lasts at most 30 days. At most 50 current/future blocks can exist per station. Replaying an identical request returns its original receipt, including cancellation; changed payloads conflict. Times/reasons are not editable: cancel then publish a new policy. Public reasons must contain no private operational/customer details.

`POST .../availability-blocks/{uuid}/cancel` requires the known revision. Natural cancellation replay is safe and does not reactivate the policy. Records/audits are retained; migration rollback refuses data loss. Station-row locks serialize publication/cancellation with existing reservation/start/pre-dispatch gates. Half-open blocks `[start,end)` apply even when weekly operating hours are advisory or unpublished. New overlapping bookings and starts fail closed; existing accepted booking receipts remain replayable/cancellable, and active charging can stop. This does not automatically cancel bookings, trigger refunds, change tariffs or send charger commands. Connector-specific blocks and wider live availability remain separate unfinished scope.

Vendor web and Flutter publish/retry/cancel against the canonical APIs. Uncertain retries retain the same frozen request identity. Customer web/Flutter display the public intervals, labeled operator policy rather than a live availability guarantee. Read refreshes do not mutate policy. No hardware acceptance or production credit is inferred.

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
Authenticated `GET /api/discovery/search` and `/api/v1/discovery/search` search published local platform inventory and already-authorized OCPI location reports together. Optional `q` (80 characters, literal name/address/operator text), `scope=all|catalog|partner`, and normalized `connector=CCS2|Type2|CHAdeMO` apply before a single 25-result SQL-union pagination. Stable `catalog:{id}` and `partner:{id}` keys preserve supplier identity even when IDs collide; ordering is descending local ID then source kind, without asserting chronology or deduplicating physical sites. Hydration is bounded to the selected page. Partner authorization/publication is checked again before projecting the allowlisted public location and source timestamps. Unknown availability and false booking/control remain explicit; no provider lookup, credential, private payload, price comparison, physical availability or actionable roaming is inferred. External discovery-provider queries retain their separate consent workflow.

Web and Flutter provide explicit EN/AR unified search and pagination. Platform results open existing station details/connector/booking controls through the additive exact `station_id` catalog selector; partner cards are read-only. Native foreground/account fences discard late results and clear them on background. Responses are private/no-store, searches stay in memory, and no financial or charging writer is called.

Unified search includes an explicit current-page map handoff. The web reuses its existing source-separated Leaflet layers and tile-consent gate; map selection clears the unrelated external-provider layer. Flutter reuses the report-map renderer with distinct platform provenance and unverified partner labels. Invalid/polar coordinates stay in text results. Maps load no tiles until explicit consent, acquire no GPS and make no additional provider/catalog lookup. Map details grant no charging or billing authority.

Unified discovery also accepts customer-only `saved_only=1`, filtering both existing favorite tables before union pagination. It returns each customer's own `is_favorite` state, never another account's preferences. Current account status, role, epoch and password snapshot are rechecked around private reads. Revoked/unpublished partners stay excluded even if previously saved; their established saved-partner management view retains removal access.

English/Arabic unified web/native cards save/remove through the existing canonical `favorites/{station}` and `roaming/favorites/{record}` writers; no new preference storage or mutation endpoint exists. The typed identities remain separate when IDs collide. Successful writes reread authoritative state. Unknown outcomes block further star actions until an explicit search refresh; background/account changes discard callbacks and never replay a write. Flutter binds the view to its opening account fence so it cannot acquire the next account's private state or mutate its preferences.

Station and unified searches accept `recent_source_status` using the ten bounded connector-report states. Filtering runs before pagination against the exact mapped connector, current binding generation, OCPP protocol/component identity and canonical ownership/approval/local-license rules. Connector/power conditions apply to that same connector. Only accepted observations newer than five minutes and not in the future qualify. A newer/future report, same-instant conflict, unsupported state, future receipt or changed binding suppresses an older claim; reported errors normalize to Faulted. PostgreSQL uses its native SHA-256 over the same server-owned binding tuple as the canonical fault observer; SQLite calls that existing fingerprint function directly. Both are tested with OCPP1.6 and2.0.1 mappings and local license-day boundaries.

The EN/AR filter is explicitly an as-of-search simulator-source report, excluding OCPI partner reports. `availability` stays unknown and every booking/start gate is unchanged. Search again to recheck; list membership never proves physical availability or grants charger control.

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
