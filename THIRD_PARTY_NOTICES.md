# Third-party software

- Laravel skeleton v12.12.2 and Laravel framework v12.70.1: MIT, Taylor Otwell and contributors. Framework license retained in third_party/Laravel-MIT.txt; locked package license inventory in docs/evidence/composer-licenses.json.
- CitrineOS v2.1.0: Apache-2.0, CitrineOS contributors. Used as a separately deployed container, not copied into business domain tables. License retained in third_party/CitrineOS-Apache-2.0.txt; upstream container retains its source notices. See services/csms/compose.yaml for exact image digest.
- Composer runtime container, PostGIS/PostgreSQL and RabbitMQ: separate development dependencies, digest pins in scripts and compose. Their images retain their own notices; full image SBOM/security review remains a release gate.
- Playwright 1.62.1 and Prettier 3.9.10: development dependencies (Apache-2.0 and MIT respectively), pinned by package-lock.json. Browser binaries retain their upstream licenses.

This inventory does not assign an open-source license to the owner's business documents or proprietary application code. No CodeCanyon source or license was supplied or used. Commercial release requires the complete dependency and image license/SBOM review described in docs/QA_REPORT.md.

- Flutter SDK/framework 3.35.7, official source `adc901062556672b4138e18a4dc62a4be8f4b3c2`: BSD-3-Clause, The Flutter Authors. Used for the shared native client and generated Android/iOS project templates; notice retained in third_party/Flutter-BSD-3-Clause.txt. Default generated launcher/launch assets remain development placeholders and are not approved Crea-Biz release branding. Dart/engine/native transitive license notices and complete binary SBOM/distribution review remain release requirements.
- shared_preferences 2.5.3: BSD-3-Clause, The Flutter Authors; retained notice in third_party/SharedPreferences-BSD-3-Clause.txt. Used only for non-secret theme/locale preferences. Platform implementations are locked in apps/mobile/pubspec.lock; release dependency/SBOM review remains required.

- flutter_secure_storage 9.2.4: BSD-3-Clause, German Saprykin and contributors; retained notice in third_party/FlutterSecureStorage-BSD-3-Clause.txt. Opt-in native session-cookie storage uses iOS Keychain and Android encrypted preferences/Keystore. Transitive/platform packages are pinned in apps/mobile/pubspec.lock; full release SBOM review remains required.
