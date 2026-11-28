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

## OCPI web and Flutter workflow checkpoint

Added a separate paginated partner-location catalog on web and canonical Flutter, with coordinates, source/receipt timestamps, reported EVSE statuses and connector types. No reported status grants availability, reservations, charging or payment authority. Both clients fail closed on unexpected public capability flags. Native route supports retry, loading/empty states, English/Arabic and RTL without replacing the existing catalog or navigation.

Vendor/admin web exposes scoped paginated providers and normalized locations/tariffs/sessions/CDRs, manual confirmed negotiation/data pulls, and admin authorization/revocation. Connections still require secure server provisioning; browser never receives connection keys, endpoints, raw payloads or operator tokens. Private views clear/invalidate pending results on logout. No external partner is configured or contacted by acceptance tests.

Verification: Laravel **88 passed / 5,571 assertions**, Pint **114 files**; Flutter analyzer clean, **27 unit/widget passed / 1 explicit default live skip**, including three new capability/pagination/retry/RTL/disposal tests. Real-Laravel roaming browser passed pagination, 503 recovery, owner isolation, admin revocation/public removal, literal user text, 390px Arabic layout and logout. Existing frontend contract/localization suites pass. Native on-device TLS fixture now also tests partner catalog navigation/pagination; updated Android/iOS hosted builds and device execution remain pending for this source until their CI run completes. Logs: evidence/roaming-*.

Remaining: full OCPI credentials handshake, push/tokens/commands, reconciliation/conformance and authorized partner acceptance; secure admin connection onboarding and broader native operator/company workflows. Complete connector-level reservations/company maintenance, production CSMS workers, actual PSP sandbox/webhooks, subscriptions, commissions/settlements, loyalty/maps, remaining customer/vendor/admin workflows and release/security/device gates are unfinished. These are software continuation tasks, not all external blockers. No final-release claim is made.

## Admin association UI

Admin web now associates server-provisioned approved connection aliases with a vendor using searchable/paginated safe owner choices. Invalid/already assigned aliases are excluded and metadata never exposes credential values, credential variable names, origins or endpoints. Empty configuration explains the secure operator provisioning prerequisite. A new provider starts revoked; registration makes no external request. Lost acknowledgement requires refreshing instead of replaying the POST. Provider paging is newest-first. Selector failures do not remove existing provider operations.

`npm run test:ocpi-provisioning` launches an isolated loopback Laravel server with synthetic injected metadata and a generated test token, retains the main application's environment, verifies registration/RTL/lost-ack with real APIs and removes only its own ephemeral container. `--no-reload` preserves injected values across Laravel's development server process. No external negotiation/pull occurs, no real token is used, and synthetic database/audit records are retained. Existing roaming tests and all OCPI contracts remain required. Full credentials lifecycle/rotation, push, commands/reservations, authorized partner conformance and a complete CPO/eMSP implementation remain unfinished.

## CPO catalog publication

The CPO catalog Sender uses the same approved managed credentials as partner discovery. A managed connection with `party_role: EMSP` can select a CPO identity through `local_credential_profile`. Named identities in `OCPI_LOCAL_CREDENTIAL_PROFILES_JSON` use the same versions URL and single-role format as `OCPI_LOCAL_CREDENTIALS_JSON`. Connections without a profile keep the default identity, allowing CPO and eMSP connections in the same application. A profile or catalog contract change invalidates prior managed authority until the parties renew credentials.

Define `cpo_catalog` on the approved connection. The contract has an `id`, a `sites` object keyed by canonical VendorOps site ID, and a `tariffs` array of canonical tariff version IDs. Each site supplies reviewed `city`, ISO alpha-2 `country_code`, ISO alpha-3 `country`, and a `connectors` object keyed by canonical vendor connector ID. Connector entries supply `format`, `power_type`, `max_voltage`, `max_amperage`, and optionally `tariff_ids`. The server verifies the site's license and owner, the connector's canonical location/EVSE mapping, and the tariff's canonical station and currency. Availability is exported as `UNKNOWN`. Contracts cannot export simulator sites through a non-sandbox connection.

Admin web **Roaming operations → CPO catalog publications** publishes tariffs before dependent locations. Each action is explicit, version-fenced and audited. A location withdrawal preserves a timestamped `publish=false` record with `REMOVED` EVSEs and no tariff references. A tariff withdrawal removes it from Sender results and uses DELETE on an approved sandbox Receiver. A withdrawn tariff ID stays retired. Changed source data requires deliberate republishing.

The version details advertise separate `/2.2.1/cpo/locations` and `/2.2.1/cpo/tariffs` Sender URLs for contract-enabled connections. Lists support UTC `date_from` (inclusive), `date_to` (exclusive), `offset` and `limit`, with `X-Total-Count`, `X-Limit` and bounded `Link` pagination. Locations also support object reads down to a single EVSE or connector. A peer token sees only publications bound to its active provider, owner, credential generation, authorization revision and contract.

External catalog writes require `OCPI_CPO_PUSH_ENABLED=true`, a local/testing runtime, a sandbox contract and the negotiated module Receiver. A durable claim allows one write per publication revision. An ambiguous response remains unknown until an explicit read matches the immutable payload or confirms a withdrawn tariff is missing. Reconciliation requires matching request tracing and never replays a write.

The CPO sender can also publish final sessions and charge detail records in local/testing under an approved sandbox connection. Add a `session_exports` object to `cpo_catalog`, keyed by canonical charging session ID. Each entry specifies the approved `site_id`, synthetic peer `token_uid` and `contract_id` beginning with `TEST-`, and the sandbox CPO's `evse_id`. The mapping is part of the credential contract. Publish the approved tariff and location first, then the final session and its CDR. Applied simulator meter receipts and the existing canonical test invoice must agree. These private publications are encrypted and omit customer, payment and invoice identifiers.

Sender reads require the assigned peer token. An opted-in sender can PUT the final session and POST its immutable CDR once to the negotiated sandbox receiver. CDR acknowledgement requires the traced HTTP 201 response and a receipt URL under the negotiated CDR endpoint. If the response is uncertain, an Admin can enter the peer-assigned receipt ID to compare the receiver object with the immutable publication using GET.

This implementation complements eMSP imports and Receiver pushes for locations, tariffs, sessions and CDRs. Live session updates, source-backed live token consent and independent partner conformance require additional work. The contract tests use HTTP fakes and local databases.

## Peer credential rotation

An authenticated peer rotation verifies the newly offered token with read-only OCPI versions and credentials Receiver discovery on the approved HTTPS origin. Failed proof leaves the current credential generation and operation history intact. Each read rechecks the active vendor owner, provider authority and credential link. Activation verifies the same state again with the owner, provider and credential link locked. Incoming bootstrap enrollment applies the same checks and verifies the bootstrap expiry. A suspended vendor's existing peer credential can still be revoked without contacting the peer.

The actual local Laravel browser and native credential tests use an explicitly selected, test-only PHP router. It installs a closed synthetic outbound HTTP fake only in local SQLite with a matching generated connection alias and approved fixture metadata. Unknown tokens or unexpected paths are rejected, never sent to the network. Both clients exercise a failed proof preserving the prior generation, a successful two-read synthetic proof replacing the generation, old-token rejection, and secure revocation. This complements the isolated PHP HTTP-fake tests and does not bypass any application authority check or certify a real partner.

## CDR session claim indexing

New CDRs record their session claim index in the same transaction as the immutable source. For existing records, run `php artisan ocpi:index-cdr-claims <provider-id> --limit=50` from the authorized application host. Pass the returned `next_after_id` as `--after` until it is null. An integrity failure is counted as requiring review and leaves the source bytes available for investigation.

Reconciliation uses indexed exact-session comparisons across the full provider, country and party history after that scope is indexed. Unlinked and duplicate claims still require review. Unindexed histories retain the preceding bounded comparison. Indexing changes only internal index fields and does not create invoices, adjust balances or call partners.
