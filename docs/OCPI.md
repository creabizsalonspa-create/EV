# OCPI eMSP adapter — implemented contract subset

## Status and source assessment

**Implemented and contract tested locally; no live operator or complete OCPI conformance verification.** No operator, credential, agreement or government relationship is assumed. The adapter never enables booking, remote start/stop or real billing from imported data.

CitrineOS v2.1.0 source `4fd7599b91c2fb6e6065e7c9b94be69880cced27` was inspected at `packages/ocpi/src/util/util.ts`: client credentials permit EMSP roles, while server credentials permit CPO roles. Its OCPI application is CPO-oriented; deploying it alone would not supply the outbound eMSP catalog/reconciliation capability required by Crea-Biz. The new Laravel `App\Services\Ocpi` adapter supplies a separate outbound read/synchronization boundary. It does not share CitrineOS tables.

## Configuration and authority

The default configuration has **zero partner connections**. Copy the non-secret metadata shape from `services/business-api/.env.ocpi.example` into deployment settings only after operator authorization. `OCPI_CONNECTIONS_JSON` contains approved connection keys, HTTPS origins, versions paths, environment labels and the names of credential variables. Credential values must be securely injected and never committed or pasted into chat.

An `OCPI_*_TOKEN` value is the already Base64-encoded credential segment for an OCPI 2.2.1 `Authorization: Token ...` header. It can be supplied by a supported proxy binding to that exact approved HTTPS operator hostname. The adapter does not transform the value (which would break proxy placeholder substitution). No secret value appears in provider JSON, database records or test evidence. Tests use an explicitly non-live fixture token.

Only an administrator can create a provider tied to an existing vendor and a preconfigured connection key. Endpoint origins and credentials cannot be entered through these APIs. The `authorized` flag is a recorded administrative prerequisite, not proof of a signed commercial agreement or certification. The provider owner or an administrator can negotiate/synchronize; other vendors and customers cannot inspect its private sessions/CDRs. Revocation immediately hides public imported locations, blocks further requests and clears negotiated endpoints/version. Reauthorization requires fresh negotiation.

The client enforces HTTPS, public DNS-shaped configured origins, same-origin advertised endpoints/pagination, no user info/fragments/nonstandard ports, no redirects, no CR/LF in authorization data, timeouts and bounded response/page/record sizes. It sends request/correlation IDs. Deployment must additionally restrict egress to approved operator destinations and protect DNS resolution; this application guard is not a replacement for network policy, DNS-rebinding controls or penetration testing. Raw upstream errors and credentials are withheld on transport failure.

## Implemented behavior

1. Retrieve the approved versions resource, select exactly OCPI 2.2.1 and fetch that version's details.
2. Retain only advertised `SENDER` endpoints for `locations`, `tariffs`, `sessions` and `cdrs`. A readable locations endpoint is mandatory. The presence of a commands endpoint does not enable commands.
3. Pull module records with bounded same-origin `Link: ... rel="next"` pagination; reject loops, unsuccessful OCPI envelopes, malformed data, oversized records or excessive pages. The client performs no automatic network retries.
4. Validate a documented normalization subset of each module. Locations include identity, publication, address, coordinates and EVSE/connector summaries. Tariffs include currency and price components. Sessions include identity, state, energy, location/connector and optional costs. CDRs include identity, dates, location/connector, energy/time and costs. Original records are encrypted at rest using the Laravel application key and hidden in API serialization. Normalized monetary values are retained as decimal text without computing billing from binary floats.
5. Serialize ingestion per provider. Exact canonical payload replays are duplicates; older mutable updates are stale and ignored. Different payloads at the same source timestamp conflict. Subsecond timestamps are preserved. Failed batches roll back completely. Completed sessions cannot regress to an active state. Accepted CDR changes conflict and require reconciliation; application model update/delete hooks also reject changes to CDR records.
6. Public location output includes only published locations from authorized providers. It always labels data `PARTNER_REPORTED_UNVERIFIED`, reports authoritative availability as `unknown`, and keeps `remote_control=false`/`reservable=false`. It does not merge these records into controlled physical inventory.
7. Private imported sessions, tariffs and CDR projections always retain `billing_eligible=false`. A final reconciliation/approval boundary must explicitly validate those records before the finance subsystem can consume them.

## HTTP routes

Root application must `require __DIR__.'/ocpi.php';` from its web routes. The route file uses normal Laravel session authentication/CSRF for mutations and existing named rate limits. It does not expose unauthenticated OCPI push/receiver endpoints.

| Method/path | Access | Behavior |
|---|---|---|
| GET `/api/roaming/locations` | Public | Paginated published imported locations, truthful unknown/control labels |
| GET `/api/ocpi/providers` | Vendor/admin | Own providers, or all for admin; no connection key/endpoints exposed |
| POST `/api/ocpi/providers` | Admin | `owner_id`, `name`, `connection_key`, `authorized`; connection must already be configured |
| PATCH `/api/ocpi/providers/{provider}/authorization` | Admin | Explicitly authorize/revoke; clears negotiated endpoints/version |
| POST `/api/ocpi/providers/{provider}/negotiate` | Owner/admin | Negotiate 2.2.1 readable capabilities; commands remain disabled |
| POST `/api/ocpi/providers/{provider}/sync/{module}` | Owner/admin | Pull and ingest supported module, report created/updated/duplicate/stale counts |
| GET `/api/ocpi/providers/{provider}/records/{module}` | Owner/admin | Paginated normalized records, raw payload and hash hidden |

No scheduled synchronization is enabled by default. An operator-configured queue/lock/retry policy, monitoring and per-partner rate limits remain required before scheduled use.

## Evidence and limits

Executed `./scripts/php artisan test --filter=OcpiIntegrationTest --compact`: **14 tests, 50 assertions passed** at the initial adapter checkpoint (root's later combined test evidence supersedes this count). Laravel HTTP fakes prevent external requests. Coverage includes 2.2.1 negotiation/auth headers, endpoint/redirect/pagination defenses, unauthorized/unnegotiated blocking, encrypted raw data, truthful catalog labels, scope isolation, full owner sync with pagination, revocation/unpublished filtering, replay/atomicity/stale handling, subsecond timestamps, future-data rejection, decimal projections and CDR immutability.

Remaining independent implementation and external acceptance work:

- Complete official OCPI schema validation and conformance suite; the normalizer intentionally validates a subset and does not prove full specification conformance.
- Bidirectional credentials bootstrap/rotation/registration and explicit CPO identity/party authorization checks.
- eMSP receiver/push endpoints with partner-token authentication, replay protection and routing headers; tokens/authorization/commands modules and asynchronous command result correlation.
- Full session/CDR-to-charge/invoice reconciliation, credit CDR handling, pricing restrictions, pagination/date-window cursor scheduling, provider health and incident handling.
- Public map/client catalog integration, shared client contracts and appropriate provenance UI on all surfaces.
- Database-level CDR immutability and PostgreSQL concurrency/backup/retention/privacy validation; current model guards are not a database security boundary.
- Actual contracted sandbox/live operator integration and credentialed end-to-end interoperability tests. No live or hardware verification has occurred.
