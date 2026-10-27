# Internal QA — universal integration checkpoint

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
