# Hasone customer integration candidate

Reuses the owner's purchased EV FastTrack Flutter source (7 November 2026), preserving screens, controllers, models and locked dependencies. This client targets the existing Express `/api/v1` API at `https://api.hasone.io`; it does not target Laravel routes. Laravel/CitrineOS and its ledger remain intact. No backend, gateway or deployment source is included or overwritten, and this client creates no independent ledger.

Flutter 3.35.7 / Dart 3.9.2. Run `flutter pub get --enforce-lockfile`, `flutter test`, then `flutter build apk --debug --dart-define=EV_API_BASE_URL=https://api.hasone.io`. Workflow `hasone-customer.yml` also builds an unsigned iOS simulator application. Neither is an App Store release. Android development package and iOS bundle are `com.creabiz.hasone.customer.dev`.

Source fixes: HTTPS origin configuration; exact-origin credential boundary; redirects prohibited; old-origin secure tokens/private user cache are not reused; stale successful responses discarded after session change; metadata-only network logs; legacy payment route corrected by supplied source; cleartext disabled; payment and remote start/stop writes blocked pending verified server test/hardware approval. A development banner discloses those unavailable operations. No flag bypasses the gate.

Android Maps key must be supplied as restricted Gradle property `MAPS_API_KEY`; purchased embedded key removed. Maps configuration and iOS maps support still need verification. Preserve original dependencies; no upgrades performed.

Pending: authenticated login/OTP, vehicles, discovery, booking, notification and invoice end-to-end verification against authorized test accounts; deployed-source comparison and verified backup through the owner's Desktop Commander connection; payment adapter/save-card contract verification; authoritative metering/reconciliation and physical charger acceptance. No production mutation has been performed. Purchased app redistribution rights must cover this reuse before external release.

Source analyzer currently reports inherited style/deprecation warnings; no compiler errors. Do not infer complete application validation from focused transport tests or successful native compilation.
