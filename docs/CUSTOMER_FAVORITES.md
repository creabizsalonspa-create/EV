# Customer station favorites — 28 October 2026

## Implemented scope

Customer-owned saved references to the existing canonical managed/discovery station catalog, across Laravel, web and canonical Flutter. This does not yet include normalized OCPI partner locations: their distinct identity/authorization projection requires a later extension to this same preference workflow. Interactive maps, routing and reviews remain independent unfinished software. Saving never grants availability, booking, remote-control, pricing, payment or operator permissions.

The common `StationCatalog` presenter reuses `StationGate` for current capabilities in both public and saved lists. Public discovery responses deliberately contain **no** private preference field. Current-page saved status comes from a separate authenticated bounded lookup. Favorite storage has a unique customer/station key, stable timestamps, customer-only scope and foreign-key cleanup; populated non-test rollback refuses silent preference loss. No financial business logic, ledger or charger command is invoked.

## Shared cookie and native-v1 APIs

All routes use existing authentication, CSRF for cookie writes, and unchanged production platform throttles. Vendor/Admin callers receive403; guest callers receive401. Both clients share the same customer records.

- `GET /api/favorites?page=1`:25 records per page, newest saved timestamp then station ID descending; source/capabilities unchanged, private `is_favorite` and `favorite_since` metadata.
- `GET /api/favorites/status?station_ids[]=123`:1–25 distinct positive integer IDs, returns only the current customer's `favorite_station_ids` within that requested set. Invalid/oversized lookups fail422.
- `PUT /api/favorites/{station}`: idempotently save an existing station; body user IDs never select the owner. Missing stations404, invalid integer IDs422.
- `DELETE /api/favorites/{station}`: idempotently remove only the caller's preference, including an already-removed target. Invalid IDs422.
- Identical protected `/api/v1/favorites` routes use existing Sanctum authentication. Preference responses use `Cache-Control: no-store, private`.

Web/Flutter provide authenticated save/remove controls, a named saved-station view, pagination, empty state, EN/AR RTL and inherited Light/Dark/System themes. Saved lists reuse existing reservation/connector selection; each action remains subject to authoritative backend policy. Removing the last row of a page reads the valid last page rather than displaying an impossible page number.

Private responses are bound to session generations. Logout/role cleanup removes private controls/lists/count attributes. Transport failures never optimistically change the preference or automatically replay a write. An unsuccessful refresh retains uncertainty; only a successful authoritative read clears it. Explicit PUT/DELETE remains safe to repeat because it sets the intended state, not a non-idempotent operation.

## Executed local evidence

- Laravel263 tests /7644 assertions; five favorite authorization/privacy/validation/pagination/token-sharing cases contribute81 assertions.
- Disposable PostgreSQL54 catalog/topology/favorite tests /814 assertions plus14 connector/admission process races /197. Preserved finance146 /6381 plus41 /539 process races pass.
- Flutter111 passing /nine deliberate default live-only skips; analysis clean. Seven favorite cases exercise private status/schema/session fencing, unknown-write no-replay, failed-refresh uncertainty retention, authoritative refresh, empty removal, expiry and Arabic390px. Existing workspace pagination/retry/tab assertions remain intact.
- Actual native client → Laravel topology integration passes saved-state/privacy/idempotency then the existing parallel EVSE booking/cancellation/replay checks; no extra logins were added to the paced native batches.
- Actual Laravel browser passes cookie CSRF rejection, cross-customer privacy, lost committed acknowledgement without replay, authoritative refresh, public DTO privacy, paginated last-row recovery, literal malicious names, no discovery booking authority, logout cleanup, EN/AR and390/768/1440 Light/Dark layouts. Seven real screenshots retained; Arabic Dark390 inspected. Existing frontend/localization/nearby suites pass.
- Eight host simulator/artifact guards and five progress-evidence guards pass. Native iOS fixture now exercises save/list/remove over trusted TLS and requires `DEVICE: customer favorites and private catalog status verified`, retaining all existing Keychain/Arabic pricing/private PDF markers and no connected-test retry.

Hosted Android/iOS builds and the extended iOS runtime case are **pending for this new source** until the current PR evidence says otherwise. Local Linux has no Xcode/Android SDK. Simulator/HTTP QA does not establish physical charger, live OCPI/merchant, real-device or store acceptance.

## Accurate continuation

The existing42-category/312-subfeature inventory and weighting remain unchanged. Canonical favorites are a verified local subset; authorized partner-location favorites still require extension through the same workflow. No credit is assigned to the broad Favorites item until its named scope is complete and exact-source verification/integration is recorded. Never change denominators or reuse the informal70% baseline to manufacture progress.

After verification: merge through the PR, record exact-source/merge/artifact evidence, then extend existing discovery/maps, partner identity/favorites and the full remaining EV/commercial/native/Admin requirements. Do not create a competing preference store, catalog or financial state machine.
