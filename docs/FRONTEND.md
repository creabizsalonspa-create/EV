# Frontend implementation and continuation

## Current frontend continuation — 2026-10-27

Implemented native paginated fleets/invitations, explicit acceptance, owner-only invitation/revocation and cost centers, own-vehicle assignment across vehicle pages, and member-scoped detail. Vendor simulator session pages now edit cumulative Wh with monotonic validation, retain input after conflicts and confirm stop. All new screens use shared English/Arabic resources and existing backend authorization/contracts.

Opt-in HTTPS session persistence uses flutter_secure_storage 9.2.4 (iOS device-only unlocked Keychain; Android encrypted preferences/Keystore with backups disabled). Only secure, HTTP-only, unexpired server cookies are stored, bound to origin and capped at 24 hours per snapshot. Password/CSRF/account data are not persisted. Restore requires fresh /me and CSRF; expiry/rejection clears private views. Serialized vault operations prevent late saves after logout. Storage failure has no plaintext fallback; deletion failure does not falsely report logout.

Web English/Arabic resources cover authentication/recovery, catalog, bookings/sessions, garage, fleets, support/inbox and admin regions/metrics/audit controls. Explicit text/attribute bindings preserve typed fields and user content. Language persists locally with RTL and storage-denied fallback. Formal translation/accessibility acceptance remains outstanding.

Local verification: Flutter analyzer clean; **20 unit/widget tests passed, 1 explicitly live-only skipped**; separate **1 real-Laravel native integration passed** (now includes fleet membership/privacy/revoke and vendor meter authorization/conflicts/stop); Laravel **37 tests / 279 assertions passed**. Chromium real-backend, frontend-contract and new localization suites passed. Hosted Android/iOS build and native Keychain device-test results will be recorded after CI completes. No backend controller/migration/business rule changed.

Next implementation: native recovery/deep-link consumption using an agreed link contract, richer available-API account/garage workflow and lifecycle QA; full inventory/staff/KYC/finance/map capabilities need Agent 1 API contracts. Fleet API currently cannot re-invite revoked members, edit/delete centers or unassign vehicles; only the acting user's own vehicles can be assigned. No fake controls added for unavailable operations. Physical devices, deployed HTTPS, mail/push delivery, signing/stores, live charger/partner/PSP integrations remain unverified. The platform remains in development, not a final release.


Branch: **development/frontend**. Current application/test source validated at **d454a6875e4eee1af8f6d7c681f861d918df12a8**, [draft PR #2](https://github.com/creabizsalonspa-create/EV/pull/2). Subsequent documentation commits do not change that application source. The full commercial platform remains in development; this is not final owner acceptance.

## Native customer/vendor implementation

- Real server-side catalog pagination, name search, connector/country/minimum-power filters. Applied page/filter state survives tab navigation. Invalid input, loading/empty/error states, clear and exact-page retry are implemented. Stale/out-of-order responses and disposed callbacks are ignored.
- Persistent Light/Dark/System and English/Arabic through shared_preferences. Only theme/locale are stored; credentials stay in memory. Preferences load before production Flutter rendering. Corruption falls back safely; failed writes are visible. Material localization delegates, resource/interpolation parity and logical layouts provide RTL across implemented screens and dialogs.
- Customer/vendor registration/login, validation, profile update, recovery/verification requests, accurate verified status and session-expiry cleanup. Admin remains web-only. Recovery/verification link consumption uses the existing web flow; native deep links and secure persistent authentication remain unfinished.
- Simulator bookings/cancellation/start/stop, paginated reservations/sessions/garage and vendor station creation preserve existing API behavior and evidence labels.
- Paginated support tickets/public message threads, replies/closed-ticket behavior, notifications/read state. Internal notes are never rendered. A stale support 401 cannot clear a later screen.

### Native files

| Path | Responsibility |
|---|---|
| apps/mobile/lib/api.dart | Same-origin cookies/CSRF, encoded query parameters, typed account/station/filter/page models |
| apps/mobile/lib/catalog.dart | Catalog state/request generation, filters, page navigation and retry |
| apps/mobile/lib/preferences.dart | Persistent non-secret appearance/locale and storage failure handling |
| apps/mobile/lib/strings.dart | Aligned English/Arabic resources and translated error classes |
| apps/mobile/lib/support.dart | Ticket/inbox pagination, public threads and read/reply actions |
| apps/mobile/lib/main.dart | Responsive phone/tablet navigation, authentication, settings and other existing workflows |
| apps/mobile/test/ | Unit/widget tests plus opted-in actual-Laravel protocol integration |
| apps/mobile/integration_test/native_flow_test.dart | Native iOS-simulator UI/HTTP/platform-preference fixtures, excluded from product lib |
| .github/workflows/mobile.yml | Pinned SDK, tests, Android/iOS builds, ordinary artifacts, then iOS-simulator UI test |

## Web operations and design

The existing Blade/JS/CSS workspace retains customer/vendor/admin workflows and shared Light/Dark/System themes. The catalog has real API filters/pagination and clear provenance. API/session failures clear private views. Admin metrics/audit rows use actual API data; audit search and CSV export apply only to the latest 50 returned events. CSV quoting/formula-prefix handling is tested. Full historical export needs a backend contract. No backend controller, migration, business rule or CSMS service changed during this frontend batch.

`public/platform.css` semantic palettes: light background/surface/ink/accent #f4f7f8/#ffffff/#153438/#126b63; dark #0c1c20/#142b30/#e5f2f2/#88ded0. Cards 20px, controls 10px, visible focus, minimum touch targets and reduced motion. Native Material 3 uses the same teal seed, adaptive brightness and 20px cards/48px primary actions. Full premium design/accessibility acceptance is still required. Native and web implemented-workflow English/Arabic resources are present; formal translation review remains.

## Executed verification

| Check | Actual result / scope |
|---|---|
| Flutter analyzer | No issues |
| Default native unit/widget suite | **15 passed / 1 explicitly live-service test skipped** |
| Actual Laravel native-client integration | **1 passed**, including 26 station records, real 25+1 pages, combined filters, auth, bookings/privacy/support/inbox/read/cancel/logout |
| Backend regression | **37 passed / 279 assertions** |
| Chromium real-backend + isolated frontend contracts | Both passed; includes admin audit filtering/download and safe CSV content |
| Hosted Android debug APK | Passed and retained, native run 40460073960 |
| Hosted unsigned iOS simulator app | Passed and retained, same run |
| iPhone-simulator UI integration | **1 passed**, same run; native HTTP authentication, page navigation, platform preference reload, Arabic/dark customer/vendor UI using isolated on-device fixtures |
| Hosted platform workflow | Passed, run 40460073970, including existing OCPP simulation checks |

The device fixture test and actual-Laravel protocol test are distinct. They do not prove physical-device/deployed-HTTPS integration. Ordinary application artifacts are archived/uploaded **before** the integration-test app is built. Neither an AAB nor a signed IPA/store release is claimed. Exact head/step/retention metadata and runner output are in docs/evidence/native-catalog-*.

## Reproduce

Use existing /workspace/EV checkout; do not create worktrees unless explicitly requested. Read AGENTS.md, current progress/QA and latest Git/PR checkpoints. Preserve backend work and never force-push.

```sh
export PATH="/workspace/.tools/flutter/bin:$PATH"
export PUB_CACHE=/workspace/.cache/ev-pub-cache
export XDG_CONFIG_HOME=/workspace/.cache/ev-config
export ANALYZER_STATE_LOCATION_OVERRIDE=/workspace/.cache/ev-analyzer
./apps/mobile/tool/bootstrap
cd apps/mobile
flutter test --dart-define=EV_NATIVE_SMOKE_URL=http://127.0.0.1:8000 test/live_api_test.dart --reporter expanded
```

The opt-in test requires the running disposable local Laravel service (`./scripts/start` from root) and rejects remote hosts. It creates synthetic records and uses the operator-only role CLI. Standard web checks from root: EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e; npm run test:frontend with the same browser variable; scripts/php artisan test --compact. See apps/mobile/README.md for SDK pin/build commands. Artifact-log host additions are saved in the environment draft; draft persistence is separate from publication/fresh-task restoration.

## Remaining full-scope work

1. Native recovery/deep links and deeper lifecycle/accessibility QA; richer inventory/staff/KYC once APIs are available. Fleet and vendor simulator tools plus encrypted session persistence are implemented in the current continuation.
2. Maps/authorized live availability, tariffs, invoices/payments/settlements, subscriptions/loyalty/promotions, complete customer/vendor/admin workflows. Do not invent successful integration responses or overwrite Agent 1's backend contracts.
3. Full web English/Arabic resources, formal native/web accessibility/translation/performance review and cross-browser/device matrix; replace development launcher/splash assets with owned branding.
4. Actual phones and authorized HTTPS service testing, real push/mail delivery, signing/store pipelines, partner/hardware/PSP and production acceptance gates. Admin remains web-only. Current simulator and pricing fixtures are never physical charging or processed payments.
