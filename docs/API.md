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
