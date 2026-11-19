# Consented company charging-session observations

This is a read-only vendor/staff workflow, not live availability, physical-charging acceptance, a billing interface or delegated charger control. It extends existing company membership and consent; it does not create a second role, inventory mapping or financial source of truth.

`charging.read` is the fourth explicitly supported company permission. Existing three-permission grants are unchanged. An owner changing permissions increments the existing invitation version and removes active access until the verified recipient explicitly accepts that exact version. The recipient retains the customer account role. Existing revocation and Admin security holds remain authoritative. The owner, invitation and acceptance web/Flutter screens display the new permission in English and Arabic.

## API

`GET /api/vendor-ops/companies/{company}/charging-sessions` (private browser session) and the existing authenticated native mount `/api/v1/vendor-ops/companies/{company}/charging-sessions` accept `page`1–100000 and optional `status`: `pending_start`, `charging`, `stopping`, `stopped`, `cancelled`, `expired_unstarted`, `unknown`. No status means all recorded states. Pagination is25 rows ordered by descending session ID; unsupported states become `unknown`. These are bounded pages, not a whole-company operational acceptance report.

The transactional read locks and rechecks current account status/role/password/auth epoch, company and accepted membership. A current owned station must be linked through the existing unique company/site relation. Same-owner other-company sessions are excluded. Unverified, invited, revoked, security-held or foreign staff receive404; inactive/stale account authority is denied. Owners/Admin use their existing authority. Admin native login restrictions remain.

The query selects session ID, recorded state, energy Wh, record creation/update/stop times, station ID/name/source and recorded CSMS protocol. It never selects customer, vehicle, booking identity or monetary columns. Envelope flags explicitly say customer identities/financial fields/control actions/verified physical charging/live availability are false. Unsafe energy becomes unavailable. Simulator records are labeled separately from unverified recorded sources. Creation/update/stop times are record metadata, not invented telemetry freshness. Responses use `Cache-Control: no-store, private`. No command, invoice, network-provider call or financial write occurs.

## Web and Flutter

The existing company directory exposes “Recorded charging sessions” independently of KYC/inventory access. Both clients show source qualifiers, observation time, recorded status, recorded Wh, station and record times; pagination/status filtering are GET-only. Errors remove prior rows and require explicit retry of the failed page. EN/AR layouts preserve isolated UTC timestamps. Unrecognized private response fields are discarded and malformed/acceptance-promoting projections are rejected.

Captured callbacks, old account/company replies and single-flight requests are fenced. Native initial inactive mount makes no read; background clears observations/filter/error, ignores late replies and requires an explicit foreground read. Web background/page-hide removes the panel, aborts the read and leaves detached callbacks inert. Account replacement clears it. Data is not written to browser or preference storage. Server authorization remains authoritative; a previously displayed capability is not a grant.

The real browser test uncovered a label translation that removed its nested select. The label now translates a separate span, preserving the actual select during locale updates. A former oversized-permission test assumed only3 permissions; its oversized input now contains5, retaining duplicate/unknown/no-audit assertions for the4 supported grants. No production rate limit was changed.

## Verification and boundaries

Backend: `VendorStaffChargingReadTest` covers versioned consent, redaction/no writes, tenant and same-owner company isolation, page/filter bounds, revocation/security holds/email verification, stale account authority and real native login. It is also registered in the existing connector PostgreSQL suite.

Web: `tests/vendor-session-observations.cjs` exercises projected schemas, EN/AR, pagination/retry/filter, single-flight, old callbacks/replies and hidden/background behavior. `tests/vendor-session-observations-e2e.cjs` exercises actual Laravel owner grant, stale/current recipient consent, company directory navigation, EN/AR390 pagination/filtering, membership revocation and unchanged session/command/invoice storage. Its owned local SQLite fixture creates only synthetic stopped sessions, never charging or financial actions. It retains no password/credential output.

Native: `vendor_session_observations_test.dart` covers strict immutable projections, English/Arabic390px, directory navigation for an operations-only customer, pagination/errors, lifecycle, late401/account/company changes, callback and single-flight guards. `live_vendor_session_observations_test.dart` is explicitly opt-in, driven by `node tests/native-vendor-session-observations.cjs` against an owned loopback Laravel instance; its private0600 fixture is removed in `finally`. The driver verifies unchanged session/command/finance snapshots. Hosted native validation runs it in its own unchanged authentication window; operational browser validation likewise has an independent window. No assertion retry or skipped prerequisite earns acceptance.

Exact local totals, artifact hashes and pending hosted state are recorded in docs/evidence/vendor-session-observations-20261119/local.json. Current Android/iOS builds and full hosted checks remain required before merge. Complete staff command-control parity, full live operational monitoring and the remaining original42/312 scope remain unfinished. No physical device, real charger, live roaming/PSP/bank, signed/store or production-deployment acceptance is claimed.
