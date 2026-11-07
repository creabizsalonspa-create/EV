# Test evidence index

> Historical checkpoint content follows. Current branch, canonical apps/mobile client, toolchain and verified evidence are in UNIVERSAL_CONTINUATION.md, PROGRESS.md and QA_REPORT.md. Retired parallel app/package paths below must not be recreated.

Updated 2026-10-27. Evidence applies to the named source checkpoint and tested operation. This is not a complete-platform acceptance report; [FEATURE_MATRIX.md](FEATURE_MATRIX.md) and [INTEGRATION_GAPS.md](INTEGRATION_GAPS.md) retain missing work.

## Source and environment

The last committed expanded checkpoint when this index was prepared is `e7e4833354b46ab22548c58c6e2a20f146352393`; active source includes native authentication and unfinished mobile work. Git history/status identify later integrated revisions. PR: [#1](https://github.com/creabizsalonspa-create/EV/pull/1).

Executed environment: Linux cloud machine, Docker 28.4 / Compose 2.40.3, pinned PHP 8.5.12 + Composer 2.10.3, Laravel 12.70.1, business SQLite, separate pinned CitrineOS 2.1.0/PostGIS/RabbitMQ, Node 24.19, Playwright 1.62.1 with system Chromium. Flutter 3.47.8 / Dart 3.13.7 was subsequently installed; that installation is not a mobile compile result.

## Persisted checkpoint evidence

| Command / target | Outcome | Evidence / coverage limit |
|---|---|---|
| `./scripts/php artisan test --compact` | 31 passed, 226 assertions | [backend-expanded.txt](evidence/backend-expanded.txt); 29 platform tests plus 2 upstream scaffold tests |
| `EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e` | Passed | [browser-expanded.txt](evidence/browser-expanded.txt); registration, vendor inventory, reservation/cancel, role/tenant visibility, garage privacy, fleet consent, support-note privacy, admin overview, RTL and 390px width |
| `npm run test:ocpp` | Both protocol subsets passed | [ocpp-expanded.txt](evidence/ocpp-expanded.txt); boot, heartbeat, status, unauthenticated rejection, authenticated remote-start receipt/acknowledgment and Laravel-outbox delivery; simulated chargers only |
| `./scripts/php /usr/bin/composer validate --strict` | Passed | Strict manifest/lock validation reported in [QA_REPORT.md](QA_REPORT.md) |
| `./scripts/php vendor/bin/pint --test` | Passed at expanded checkpoint | 58 PHP files reported at that revision; new files need fresh checks |
| `npm audit --json --cache /workspace/.npm-cache` | Zero reported advisories | [npm-audit.json](evidence/npm-audit.json); not application penetration/security approval |
| Composer advisory query, initial attempt | Failed, exit 100 | [composer-audit-error.txt](evidence/composer-audit-error.txt); historical egress denial, not the latest readiness result |
| Composer advisory query, later retry | Passed, no advisories/abandoned packages reported | Execution result `/tmp/ev-composer-audit-retry.json` observed during this task; must persist the successful report with the integrated source rather than rely on temporary files |
| Composer license inventory | Generated | [composer-licenses.json](evidence/composer-licenses.json), [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md); full image SBOM/license review remains |
| Compose configuration validation | Passed | `docker compose -f services/csms/compose.yaml --env-file services/csms/.env config --quiet`; no secret values printed |
| Initial and repeated local installation/migrations | Passed current instance | `scripts/setup`, development startup and documented migrations; not a published-snapshot/new-task restoration test |

Earlier foundation outputs remain in `evidence/backend-foundation.txt` and `evidence/browser-foundation.txt` as historical results. They do not supersede the expanded checkpoint.

## Active-source evidence requiring final persistence

The root task executed the active Laravel suite after adding Sanctum/native-authentication tests: **35 passed / 246 assertions**. `MobileAuthTest` covers token issuance/expiry/revocation, customer-only registration, denial of native admin login, five-token cap and native vehicle tenant isolation. This count is an observed active-task result, not yet a log committed alongside the latest integrated source. Rerun and persist it when finance/OCPI/mobile work is integrated.

Initial `flutter analyze` failed before source analysis because the analyzer attempted to create `/home/agent/.dartServer` on a read-only filesystem. The supported `ANALYZER_STATE_LOCATION_OVERRIDE` now points to a writable workspace directory. The root task subsequently ran shared-package analysis successfully and **7 Flutter API/widget tests passed**: bearer request/logout behavior, remote-cleartext rejection/server conflict errors, invalid authentication response, failed-revocation local cleanup, vendor registration/Arabic RTL, truthful discovery provenance, and cross-surface vendor-role rejection/revocation. See `packages/ev_app/test/api_test.dart` and `app_test.dart`. Successful outputs must be persisted with the integrated native source. An earlier empty test directory was not counted as passing.

The initial Android archive was truncated and not used. The replacement official command-line-tools 23 archive was checksum-verified, with platform 36, build-tools 36.0.0 and platform-tools installed. No app APK/iOS artifact or device test is claimed until its actual build/test passes.

Hosted workflow definitions exist. PR #1 triggered hosted checks observed in progress, but no completed conclusion is recorded here. Query the actual run and retain its result before claiming hosted CI passed. Use GitHub Actions for infrastructure integrations if the current cloud machine cannot execute them; local Docker tests already executed here must not be confused with a hosted run.

## Test-to-behavior pointers

- `PlatformTest`: registration/role boundaries, authentication failures, discovery truth labels, slot uniqueness/idempotency/payload conflict, cancellation/tenant scope, time-window/input validation, private vehicles and vendor station audit/provenance.
- `SimulationTest`: authorized application simulation, monotonic meters, integer rounding/frozen stop totals, idempotent start/stop, cross-tenant denial and invalid reservation windows. These are application-level simulation tests, not OCPP transaction billing.
- `ChargerCommandTest`: admin-only simulator binding, owning-vendor command scope, idempotent queue, submitted versus accepted distinction, negative dispatch confirmation, timeout/reconciliation/no-replay and disabled dispatch guard.
- `EnterpriseTest`: configured regions with commerce disabled, recipient consent, privacy, own-vehicle/same-fleet cost-center assignment, revocation and non-enumerating invitations.
- `AccountTest`: generic password recovery, reset single use/expiry, password update and DB-session removal, profile role boundary and security/cache headers.
- `SupportTest`: tenant/private-note isolation, notifications/read ownership, ticket state machine, catalog/support rate-budget separation and actual 429 enforcement.
- `MobileAuthTest`: bearer-token lifecycle and native role/tenant boundaries as described above.

## Required QA still unrun or incomplete

Complete OCPP transaction-to-billing simulation; remote-stop/fault/outage/reconnect/device matrix; outbound OCPI 2.2.1 conformance and authorized-partner tests; PSP sandbox and financial reconciliation; Postgres concurrency; full mobile compile/widget/device suites; full localization/accessibility; load/security/penetration checks; backup/restore/rollback drills; production-like staging; signed Android/iOS/store delivery and actual hardware acceptance remain open. A partial test matrix or zero-advisory report must never be presented as all these gates passing.

## Source acquisition evidence

The root task inspected the supplied CitrineOS ZIP and compared its content to the official pinned checkout: archive comment `4fd7599b91c2fb6e6065e7c9b94be69880cced27`; all 3,165 files matched byte-for-byte. This verifies the supplied source corresponds to the selected checkout, not that every upstream feature or protocol branch has been tested.

Actual customer/vendor APK and release-ready AAB outputs, iOS projects/IPA where authorized tooling permits, and complete web builds must each have exact commands, source revision, successful logs, artifact path/checksum and signing/configuration scope. Screenshots are not build evidence. The current Android build is in progress with no successful artifact recorded in this index yet.
