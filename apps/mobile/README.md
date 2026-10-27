# Shared customer/vendor native client — development checkpoint

One Flutter package serves customer and vendor roles. Admin remains web-only. This is an incomplete native application, not a final mobile release.

## Verified capabilities and limits

Flutter **3.35.7 / Dart 3.9.2**, official source SHA `adc901062556672b4138e18a4dc62a4be8f4b3c2`. Local analyzer passed with no issues; **3 tests passed** (origin security, actual local HTTP cookie/CSRF/rotation protocol, unavailable-service widget). Initial and repeated bootstrap passed. Platform projects and the dependency lock are retained in source.

Hosted [native run 40454501304](https://github.com/creabizsalonspa-create/EV/actions/runs/40454501304), source `a547379aa623a229b919dbbd6595424783ebc2bb`, passed Linux/macOS analysis/tests, Android **debug APK** compilation and an unsigned **iOS simulator** build. These builds had no deployment URL: the unavailable-service state is expected. They do not prove device login or business integration. They did not produce an AAB or signed IPA; signing/store delivery remains external. Artifact retention is added to the following workflow revision; only claim downloadable files after that run uploads them.

Source workflows: register/sign in, role-aware shell, first-page catalog with provenance, confirmed UTC half-hour simulator reservations, scoped reservation/session lists, cancellation and simulator start/stop, garage creation, support-ticket creation/list, vendor simulator-station creation, sign-out and in-memory Light/Dark/System selection. The native HttpClient smoke test passed against the actual disposable local Laravel service: customer/vendor registration/login, operator-provisioned vendor role, station creation, idempotent reservation, vendor visibility, garage/support creation, outsider privacy, cancellation and logout. This is protocol-level evidence; workflows **have not been exercised on a physical device or against a deployed HTTPS service**. Full filters/pagination, recovery/verification, fleet UI, support threads, notification feed, vendor meter entry, persistent settings, Arabic, maps, commerce/KYC/staff/analytics/native integrations and device accessibility remain unfinished.

## Reproduce

The cloud SDK is at `/workspace/.tools/flutter`; use writable caches without changing HOME:

```sh
export PATH="/workspace/.tools/flutter/bin:$PATH"
export PUB_CACHE=/workspace/.cache/ev-pub-cache
export XDG_CONFIG_HOME=/workspace/.cache/ev-config
export ANALYZER_STATE_LOCATION_OVERRIDE=/workspace/.cache/ev-analyzer
./apps/mobile/tool/bootstrap
cd apps/mobile
flutter run --dart-define=EV_API_BASE_URL=https://your-authorized-development-origin.example
flutter build apk --debug --dart-define=EV_API_BASE_URL=https://your-authorized-development-origin.example
# On macOS with Xcode:
flutter build ios --simulator --no-codesign
```

The example hostname is a configuration placeholder, not an endpoint. Provide an authorized development deployment with HTTPS and reachable APIs. Debug-only HTTP is loopback-only; Android emulators need an HTTPS development origin or explicit local port forwarding. No certificate bypass, default password or public test backend exists. Never use production for synthetic test flows.

`tool/bootstrap` preserves existing platforms/source, creates official platform shells in a temporary template only if missing, uses `flutter pub get --enforce-lockfile` when the lock exists, then runs analysis/tests. To format in this sandbox use `dart --suppress-analytics format lib test`; direct Dart analytics otherwise attempts to write to the read-only home directory. Android SDK/Gradle and Xcode builds were verified on hosted runners, not installed as host capabilities in this Linux cloud machine.

## Actual local API smoke test

With the local Docker-backed Laravel service running, from apps/mobile:

```sh
flutter test --dart-define=EV_NATIVE_SMOKE_URL=http://127.0.0.1:8000 test/live_api_test.dart --reporter expanded
```

This explicitly opted-in test creates synthetic accounts/station/records and uses the local operator CLI to provision a vendor; it rejects non-loopback hosts and must not target production. **1 passed** in this session. Normal flutter test runs keep this test skipped without the define: report 3 passed / 1 skipped separately. It is not a phone/device UI test. Evidence is in docs/evidence/native-live-api.txt.

## Security and remaining work

The native same-origin HttpClient uses existing session/CSRF APIs. Cookies/passwords/tokens remain in memory; passwords clear after sign-in. Redirects and remote plaintext origins are rejected, normal TLS validation remains enabled, and mutations are never replayed automatically. Release mode requires HTTPS and Android INTERNET permission is declared. Session persistence is not implemented; secure native authentication/storage is a further milestone. Generated launcher/splash assets are Flutter defaults and must be replaced with approved owned branding before release.

Earlier SDK metadata requests returned 403, then 404 after network changes. Lightweight official Git source bootstrap successfully retrieved engine artifacts; that route resolved the SDK blocker without a large container pull or verification bypass. Environment settings contain additive GitHub/Flutter/artifact-host requirements; saving a draft is separate from publication. Local and hosted evidence is in docs/evidence/native-*; full acceptance is still outstanding.
