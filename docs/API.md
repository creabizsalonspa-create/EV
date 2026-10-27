# Current HTTP API (development foundation)

All endpoints are same-origin JSON, `Accept: application/json`. Mutations use Laravel's session cookie and `X-CSRF-TOKEN` from `GET /api/csrf`. Cookies are HttpOnly; CSRF is enabled outside Laravel's test runner. Native bearer authentication is not implemented. Errors: 401 unauthenticated, 403 wrong role, 404 inaccessible resource, 409 conflict, 422 validation, 429 rate limit. Auth endpoints are limited to 10/minute, authenticated endpoints 60/minute.

| Method/path | Access | Contract |
|---|---|---|
| POST /api/register | Public | name, email, password (12–128 chars), optional locale en/ar; always customer; 201 |
| POST /api/login | Public | email, password; regenerates session; 200 |
| POST /api/logout | Session | invalidates session and rotates CSRF; 204 |
| GET /api/me | Session | identity; 200 |
| GET /api/stations | Public | optional connector_type CCS2/Type2/CHAdeMO, country, min_power_kw; paginated 25; unknown availability, remote_control=false |
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
