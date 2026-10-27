# Shared customer/vendor native client — unverified source checkpoint

This is the first shared Android/iOS client source, not completed applications. The existing Laravel web application remains the tested customer/vendor/admin surface. No admin mobile app is provided: admin accounts are directed to the web workspace. No APK/AAB/IPA has been built or verified.

The source uses the existing session/CSRF API through a same-origin `dart:io` HttpClient. Cookies, passwords and CSRF tokens are kept in memory; passwords are cleared after sign-in. Redirects and remote plaintext origins are rejected, normal TLS validation is retained, and mutations are never automatically replayed. Release mode requires HTTPS. A session ends when the process closes; secure persistent native authentication remains a backend/client milestone.

Source workflows: register/sign in, role-aware customer/vendor shell, first-page catalog with truthful provenance, confirmed UTC half-hour simulator reservation, scoped reservation/session lists, cancellation and simulator start/stop, garage creation, support-ticket creation/list, vendor simulator-station creation, sign-out and in-memory Light/Dark/System selection. These workflows **have not been compiled, analyzed, widget-tested or run on a device** in this cloud task. API/widget test sources are included for execution when Flutter is available. Full filters/pagination, recovery/verification, fleet UI, support threads, notification feed, vendor meter entry, persistent settings, Arabic, maps, all commerce/KYC/staff/analytics/native integrations and device accessibility acceptance remain unfinished.

## Reproduce when SDK access is available

Use official Flutter **3.35.7**, source tag verified by `git ls-remote` at `adc901062556672b4138e18a4dc62a4be8f4b3c2` (Dart >=3.9). Do not pull the previously oversized Flutter container. `tool/bootstrap` creates official Android/iOS shells in a temporary template and copies only missing platform directories, preserving lib/test/pubspec; it then runs pub get, analysis and tests. Platform shells are generated and ignored at this source checkpoint. The pubspec uses only Flutter SDK dependencies; commit the generated pubspec.lock after the first successful toolchain run. Bootstrap/CI are supplied but **unexecuted** here.

```sh
./apps/mobile/tool/bootstrap
cd apps/mobile
flutter run --dart-define=EV_API_BASE_URL=https://your-authorized-development-origin.example
flutter build apk --debug --dart-define=EV_API_BASE_URL=https://your-authorized-development-origin.example
# On macOS with Xcode:
flutter build ios --simulator --no-codesign
```

The example hostname is a configuration placeholder, not an endpoint. Provide an authorized development deployment with HTTPS and reachable APIs. A configuration-free build displays an unavailable-service state and cannot sign in. Debug-only HTTP is loopback-only; Android emulators need an HTTPS tunnel/authorized development origin or explicit local port forwarding. No certificate bypass, default password or public test backend is included. Never use production for synthetic test flows.

## Current blocker and CI

2026-10-27: official SDK metadata at `storage.googleapis.com/flutter_infra_release/flutter/releases/releases_linux.json` returned HTTP 403. Host Flutter and Dart are absent; ~15 GiB disk is available. Saved an additive request for `storage.googleapis.com` in environment settings while preserving `api.github.com` and package-manager presets. Save/review must apply the domain before retrying. No SDK download/build is claimed.

`.github/workflows/mobile.yml` prepares the pinned official SDK on Linux/macOS, generates platform shells, runs analyzer/tests and attempts Android debug + unsigned iOS simulator builds. Hosted execution is not verified because GitHub API access remains Forbidden. Release signing, App Store/Play permissions, device/network integration and production QA are separate external requirements; no signing credentials belong in source.
