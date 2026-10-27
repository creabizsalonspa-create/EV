# Shared customer/vendor native client

The Android/iOS client shares the existing Laravel session/CSRF APIs. Admin remains web-only. This is a tested development checkpoint; full product, physical-device, signing and store acceptance remain unfinished.

## Implemented

- Native catalog uses real server pagination, name search, connector type, country and minimum power. Queries are encoded, applied filters/page survive tab changes, obsolete responses are ignored, failed pages retain current records and retry the requested page. No local slicing or invented availability/pricing filters.
- Light/Dark/System and English/Arabic persist through `shared_preferences` (only non-secret `theme`/`locale` fields). Preferences load before the first production Flutter frame. System brightness follows OS changes. Failed storage writes are reported and do not claim a saved preference.
- English/Arabic resource dictionaries cover the implemented native UI. Flutter localization delegates handle Material dialogs, directionality and controls. Narrow phone and tablet navigation support RTL. User-entered station/message content is preserved verbatim.
- Customer/vendor sign-in/registration, local form validation, recovery-link requests, verification-link requests with accurate verified status, profile update, session-expiry cleanup and sign-out. Cookies/passwords/CSRF tokens remain in memory; persistent secure authentication and native recovery/deep-link consumption are unfinished.
- Simulator booking/cancellation/start/stop, paginated reservation/session/garage lists, vehicle creation and vendor station creation preserve existing contracts and provenance labels.
- Support lists/threads/message pagination/public replies/closed-ticket behavior and notifications/read actions use real APIs. Internal notes are never rendered in the native client. Localized loading, empty, retry and session-expiry states are included.

## Code map

`lib/api.dart`: same-origin HTTP, CSRF/cookies, typed catalog/filter/page models. `lib/catalog.dart`: request ordering, filters and catalog UI. `lib/preferences.dart`: persistent non-secret preferences. `lib/strings.dart`: aligned English/Arabic resources and localized error classes. `lib/support.dart`: customer/vendor tickets and inbox. `lib/main.dart`: responsive navigation, authentication, settings and remaining implemented workflows.

Flutter **3.35.7 / Dart 3.9.2**, source SHA `adc901062556672b4138e18a4dc62a4be8f4b3c2`. Android/iOS projects and pubspec.lock are checked in; no default credentials, certificate overrides or production test backend are included. Release mode requires HTTPS. Android INTERNET permission is declared. Generated launcher/splash assets still require owned release branding.

## Run and validate

```sh
export PATH="/workspace/.tools/flutter/bin:$PATH"
export PUB_CACHE=/workspace/.cache/ev-pub-cache
export XDG_CONFIG_HOME=/workspace/.cache/ev-config
export ANALYZER_STATE_LOCATION_OVERRIDE=/workspace/.cache/ev-analyzer
./apps/mobile/tool/bootstrap
cd apps/mobile
flutter run --dart-define=EV_API_BASE_URL=https://your-authorized-development-origin.example
flutter build apk --debug --dart-define=EV_API_BASE_URL=https://your-authorized-development-origin.example
# macOS + Xcode:
flutter build ios --simulator --no-codesign
```

The hostname is a configuration placeholder. Native authenticated deployment/device QA needs a reachable authorized HTTPS development service. Debug HTTP is loopback-only. Bootstrap preserves existing platform/source files, enforces the lock, then runs analyzer/tests. Format with `dart --suppress-analytics format lib test integration_test` in the read-only-home cloud sandbox.

Normal tests run isolated unit/widget fixtures; the live test is explicitly skipped without its define. With the disposable local Laravel app running:

```sh
flutter test --dart-define=EV_NATIVE_SMOKE_URL=http://127.0.0.1:8000 test/live_api_test.dart --reporter expanded
```

That test rejects non-loopback destinations, creates synthetic users/26 stations, and uses the local operator CLI for fixture roles. It validates real search/combined filters/page boundaries, authentication, booking retry/privacy, support replies, inbox/read state, cancellation and logout. No physical charging or payment occurs.

## CI and device evidence

`.github/workflows/mobile.yml` runs frozen installation, analyzer, unit/widget tests, Android debug and unsigned iOS simulator builds, and artifact retention. The macOS job additionally boots an iPhone simulator and executes `integration_test/native_flow_test.dart`: real native HTTP/cookie/CSRF plumbing against an isolated on-device synthetic server, pagination, platform-persisted preferences after reload, and Arabic/dark customer/vendor UI. Fixtures exist only in integration_test and are not shipped. This is distinct from the separate real-Laravel protocol smoke; neither is a physical-device/deployed-HTTPS acceptance claim. Current exact results/build SHAs are in docs/QA_REPORT.md and docs/evidence.

Still unfinished: native fleet workflows, maps/authorized availability, full vendor inventory/staff/KYC and all commerce/loyalty/analytics integrations, secure persistent auth, deep links, push delivery, approved branding, comprehensive accessibility/performance/device QA, release signing and stores. Backend-dependent gaps are documented in docs/API.md rather than implemented as fake successes.
