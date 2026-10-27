# Internal QA — universal integration checkpoint

## OCPI web and Flutter workflow checkpoint

Added a separate paginated partner-location catalog on web and canonical Flutter, with coordinates, source/receipt timestamps, reported EVSE statuses and connector types. No reported status grants availability, reservations, charging or payment authority. Both clients fail closed on unexpected public capability flags. Native route supports retry, loading/empty states, English/Arabic and RTL without replacing the existing catalog or navigation.

Vendor/admin web exposes scoped paginated providers and normalized locations/tariffs/sessions/CDRs, manual confirmed negotiation/data pulls, and admin authorization/revocation. Connections still require secure server provisioning; browser never receives connection keys, endpoints, raw payloads or operator tokens. Private views clear/invalidate pending results on logout. No external partner is configured or contacted by acceptance tests.

Verification: Laravel **88 passed / 5,571 assertions**, Pint **114 files**; Flutter analyzer clean, **27 unit/widget passed / 1 explicit default live skip**, including three new capability/pagination/retry/RTL/disposal tests. Real-Laravel roaming browser passed pagination, 503 recovery, owner isolation, admin revocation/public removal, literal user text, 390px Arabic layout and logout. Existing frontend contract/localization suites pass. Native on-device TLS fixture now also tests partner catalog navigation/pagination; updated Android/iOS hosted builds and device execution remain pending for this source until their CI run completes. Logs: evidence/roaming-*.

Remaining: full OCPI credentials handshake, push/tokens/commands, reconciliation/conformance and authorized partner acceptance; secure admin connection onboarding and broader native operator/company workflows. Complete connector-level reservations/company maintenance, production CSMS workers, actual PSP sandbox/webhooks, subscriptions, commissions/settlements, loyalty/maps, remaining customer/vendor/admin workflows and release/security/device gates are unfinished. These are software continuation tasks, not all external blockers. No final-release claim is made.



## Company, staff and inventory workspace checkpoint

Implemented EN/AR web company applications/edits, private evidence upload/authenticated download, required-evidence submission, internal admin review/history, granular staff permissions/recipient consent/revocation, and scoped sites/chargers/connectors/maintenance. Company capabilities are server-derived hints and never replace transaction-time authorization. Customer-role staff now have a usable company workspace without global vendor promotion. Pending invitations include only recipient-authorized company identity. Private data and blob URLs clear on logout; user-entered names/review reasons are never translated as UI resources.

Added audited renewal: admin can request changes from approved/suspended state, owners correct evidence/expiry and resubmit, and inventory remains blocked pending approval. License validity uses the configured country timezone's end of day; API tests cover the Dubai/UTC boundary and changed configuration. This is an internal workflow, not legal KYC or physical control certification. Legacy station/booking integration with connector maintenance and native company screens remain unfinished.

Verification: Laravel **88 tests / 5,571 assertions**; company-focused **11 tests / 110 assertions**. Real-Laravel browser workflow passed application/private-file privacy/review/customer-role staff hierarchy and revocation, nested-phone layouts, RTL/input preservation and logout; existing browser/contracts/localization passed. CI executes the new vendor browser suite. Reports: evidence/vendor-workspace-*.

Hosted platform and native workflows both passed at **36828ee**, including PostgreSQL finance/races, both continuous OCPP flows, Android debug, unsigned iOS simulator and **1 passed iPhone TLS/Keychain/RTL integration**. That native tree is unchanged by this web/backend vendor slice. Exact source/build/retention metadata: evidence/fullstack-*-ci.json, fullstack-native-artifacts.json and fullstack-device.txt. Production rate limits remain unchanged; bounded 429 and reconnect test fixes are verified. No complete-platform/release claim is made.


## Continuous source and connected clients checkpoint

Implemented a restartable authenticated local CSMS observer with durable source cursors, exact Wh/kWh conversion, station/authorization/remote-start correlation and HMAC inbox delivery. It never dispatches commands. Both OCPP versions passed with an actual continuous process restarted during charging, one immutable simulated invoice, simulated payment/refund and balanced journals. Lost HTTP acknowledgement after finalization is regression-tested without double invoicing.

Web/native now select CSMS start/stop for linked records, hide manual meter overrides, show source-wait states and display read-only simulated invoice summaries. Existing unbound simulator flows remain supported. Backend list metadata is additive and tenant-scoped.

Current local verification: Laravel 85 / 5,532 assertions; native analyzer clean, 24 unit/widget passes plus one explicit default live skip, separate real-Laravel native integration 1 pass; three Chromium suites pass. PostgreSQL finance 14 / 4,999 assertions and cross-invoice payment/refund races 2 / 26 assertions pass. CI now runs the consumer/restart and PostgreSQL suites. Evidence: fullstack-* and source-consumer-*. The inherited native source 0fe9e75 additionally passed hosted Android debug/iOS simulator builds and 1 iPhone-simulator TLS/Keychain/UI test (run 40469622903); updated native screens require their own hosted build result.

Still required: safe provision/dispatch/reconciliation workers, connector-level inventory/reservations/company maintenance gates, full vendor/staff/KYC, OCPI and finance UI, and all commercial subscription/settlement/loyalty/map scope. Real hardware, PSP/roaming partners, legal KYC/tax and signed store/device/staging/operational acceptance remain separate. This remains an unfinished commercial platform, not a final release.


## Verified full-stack merge

Branch **development/fullstack-continuation** preserves backend b0c43be and frontend through 0fe9e75 with merge 6156600. No original branch was reset or force-pushed. Canonical client remains apps/mobile. The only merge conflicts were documentation; backend and frontend behavior are both retained. The integrated checkpoint also fixes charging setup when the event-key file exists before Hasura setup, and makes the PostgreSQL finance immutability function repeatable for migrate:fresh. Existing keys/data are preserved.

Executed: Laravel **82 tests / 5,477 assertions**; Flutter analyzer and **21 passed / 1 explicit live-only skipped**, separate actual-Laravel native integration **1 passed**; all three Chromium suites passed. Legacy OCPP transport, full protocol sessions and correlated business/invoice/payment/refund simulations passed for both 1.6 and 2.0.1. PostgreSQL finance **14 tests / 4,999 assertions** and synchronized cross-invoice payment/refund concurrency **2 tests / 26 assertions passed** in an isolated disposable database. Immutable storage and duplicate-posting assertions remain enforced. Evidence is docs/evidence/fullstack-*. No physical hardware, real PSP, roaming partner or production acceptance is claimed. Native device TLS/Keychain CI remains a separate unfinished check until a run passes.

Next code: durable continuous source-event consumption using authenticated documented CSMS APIs and the signed inbox; then linked-session routing and mounted finance/vendor/OCPI client workflows, connector-level reservation gates and all remaining commercial scope. PostgreSQL concurrency is now locally verified for the tested cases; load, isolation and production acceptance remain separate.


**NOT READY FOR FINAL ACCEPTANCE.** The full commercial requirements remain mandatory. This report concerns the integrated source checkpoint containing this file, based on frontend commit `92a8c8b`; historical evidence in Git remains revision-specific.

## Current executed evidence

| Check | Result | Evidence |
|---|---|---|
| Combined Laravel tests | 82 passed / 5,479 assertions | `evidence/universal-backend.txt`; latest log is authoritative |
| Real-backend browser | Passed | `evidence/universal-browser.txt` |
| Isolated web contracts | Passed | `evidence/universal-frontend.txt`; responsive, safe rendering, errors and pagination |
| Web EN/AR/RTL | Passed | `evidence/universal-localization.txt` |
| Canonical Flutter analyzer + tests | Clean;21 passed,1 explicit live-only skipped | `evidence/universal-native.txt` |
| Native protocol against running Laravel | 1 passed separately | `evidence/universal-native-live.txt`; no physical device claim |
| Full OCPP1.6/2.0.1 sessions | Both passed | `evidence/charging-protocol-sessions.txt` |
| CSMS → business → finance | Both passed | `evidence/charging-business-sessions.txt` |
| Strict Composer manifest/lock and advisory scan | Executed; inspect report | `evidence/universal-composer-audit.json` |
| npm advisory scan | Executed; inspect report | `evidence/universal-npm-audit.json` |
| PHP formatting | Checked with Pint; affected style failures fixed | Recheck current source before pushing |
| PostgreSQL migration/concurrency | Not locally verified | Isolated runner under `tests/Postgres`; readiness/storage failures are not passing tests |

The protocol tests use actual running CitrineOS/PostGIS/RabbitMQ/Hasura and simulated chargers. They verify accepted authorization, start, cumulative energy, reconnect, stop and persisted fault/recovery. Both reconcile exactly2.5kWh. OCPP2.0.1 repeated sequence produced an extra upstream periodic meter row; the report records it rather than claiming upstream perfect dedup. Billing uses cumulative register delta and the business inbox deduplicates event/sequence identity.

The business test uses actual authenticated Laravel HTTP, an explicitly labeled development-only current-slot reservation fixture, command outbox and documented CSMS GraphQL transaction/meter observations. A controlled test adapter signs and forwards those observations. It verifies one invoice329AEDminor, exact replays, conflict rejection, one simulated payment/refund and balanced ledger. It is **not** a continuously running production consumer, a physical charger, a PSP sandbox/live payment or a legal tax invoice. Public reservation behavior is covered by separate browser/API tests.

Finance, vendor-ops and OCPI routes are now mounted in the application. Security regressions include wrong-tenant access, manual override prevention for CSMS-linked meters/stops, signature freshness/authentication, sequence/event identity, meter monotonicity, finalization/replay, encrypted evidence, private notes and immutable accounting. Finance review also corrected numeric form tariff casts and unique-key race recovery. PostgreSQL tests must still pass before PostgreSQL-specific claims.

## Preservation and resource recovery

Fetched all remote heads/PRs, retaining both accounts' work. Local source/hash manifest and Git bundle were verified before integration. Newer verified frontend resolved conflicting interrupted web drafts; canonical `apps/mobile` supersedes local parallel native drafts, which are retained outside checkout. No force push, production reset or existing-data deletion occurred.

A disposable PostgreSQL image attempt filled disk and triggered sandbox startup failures. Only task-generated downloads/caches/test images were reclaimed; application/CSMS data and source archives were preserved. Reproducible SDK and Gradle caches were moved to temporary storage with path links. After recovery, affected tests were rerun. Temporary toolchain caches are not durable release artifacts or proof of snapshot restoration.

## Open gates

Full client/business scope, continuous source ingestion, full OCPI and PSP adapters, subscriptions/loyalty/settlement/fleet billing, connector bookings, 2FA/privacy, formal localization/accessibility/premium design, native recovery, notification delivery, PostgreSQL concurrency, image/SBOM/security/load/restore/staging and all signing/store/hardware/partner/legal gates remain open. Staging configuration in `infra/staging` has not been built/deployed/accepted here. Existing hosted native artifacts are debug/unsigned simulator outputs for the source revisions in `docs/FRONTEND.md`, not complete customer/vendor release packages.

No `100% COMPLETE`, `PRODUCTION_READY` or `SOFTWARE BUILD COMPLETE` claim applies. Next implementation and exact commands: [UNIVERSAL_CONTINUATION.md](UNIVERSAL_CONTINUATION.md).

## Full-stack continuation merge — 2026-10-27

Continuation branch: development/fullstack-continuation, created from backend b0c43be. Merged the complete latest frontend history through 0fe9e75, including the three commits through 95ca47e plus the subsequent PKCS12 fixture/diagnostic correction. Both histories and apps/mobile are preserved; original branches are unchanged. Source files merged without conflict. Documentation combines current backend evidence with frontend work rather than replacing either implementation.

Added frontend scope includes read-only scoped vendor/admin command history with pagination/EN/AR/retry/private-view cleanup, streamed-response session generation checks and stronger secure-storage/TLS regression tests. Prior frontend local tests passed 21 unit/widget tests (1 default live skip) plus the separate Laravel protocol integration. Android debug/iOS simulator builds passed at 95ca47e; device TLS/Keychain acceptance is still under investigation and is not claimed passed. The integrated Laravel/OCPP/finance/OCPI/browser/Flutter suites must now be rerun; inherited evidence remains revision-specific.

## Charging CI 429 correction

The hosted continuous-session test exhausted the existing per-account budget while polling. The synthetic integration client now respects a bounded Retry-After once for a received 429, snapshots the identical serialized request body, and never retries transport uncertainty or 503 command outcomes. Poll cadence is reduced. Production rate-limit configuration is unchanged. Focused request tests cover immutable payload, repeated/invalid limits and no uncertain-command replay; both continuous protocol/restart/invoice flows pass locally. Hosted rerun is required for the new revision. Evidence: charging-rate-limit-contract.txt and charging-rate-limit-protocols.txt.

## Reconnect and hosted simulator diagnostics

The rate-limit correction passed hosted platform run 40476705397. A parallel run exposed the upstream interval between isOnline=false and socket-registry cleanup. Protocol tests now retry only rejected reconnect handshakes within 12 seconds, then still require accepted boot, heartbeat, stop, final energy and persisted fault recovery. Initial connection and all charging commands are unchanged. Both local protocol tests pass; server rate limits and charger command replay rules remain intact.

Hosted native builds passed, but some iPhone runner attempts timed out before the Dart test began. CI now records Xcode/runtime selection, prefers an installed iOS 18 simulator when available, bounds Flutter launch below the job timeout and retains verbose/screenshot/Runner diagnostics. No certificate or test assertion is bypassed. New device-run results must be recorded separately.
