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
