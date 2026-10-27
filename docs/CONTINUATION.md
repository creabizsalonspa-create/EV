# Exact continuation instructions

The complete commercial platform remains the objective. Resume existing work; do not recreate the repository, discard changes, reduce scope to an MVP or request approval after each module. No task is configured to keep running automatically after its session ends.

## Resume prompt

> Continue the complete Crea-Biz EV platform in `creabizsalonspa-create/EV` on `development/platform-foundation`, preserving all existing tracked, untracked and local work. Read `AGENTS.md`, `PRODUCT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/ACCEPTANCE_TESTS.md`, `docs/OWNER_DIRECTIVE.md`, `docs/PROJECT_STATUS.md`, `docs/FEATURE_MATRIX.md`, `docs/INTEGRATION_GAPS.md`, `docs/TEST_EVIDENCE.md`, `docs/PROGRESS.md` and `docs/DECISIONS.md`. The owner requires all customer/vendor web/Android/iOS, admin web, Laravel, CitrineOS OCPP, OCPI eMSP/CPO, finance/payments/fleet/loyalty/subscriptions/international/operations features, not an MVP. Inspect actual source and latest Git/PR status before choosing the next dependent slice. Complete routine implementation, setup, tests and fixes autonomously. Commit/push related changes and update draft PR #1 where authorized; never force-push or deploy/charge real infrastructure without authorization. Preserve exact tested versus simulated versus unverified states. Ask only for genuinely external credentials, commercial/hardware/signing access or essential authorization after finishing independent work. Final owner review is only after independently executable implementation and QA are complete. Report remaining external dependencies honestly.

## First inspection commands

Use the existing isolated cloud checkout. Do not create a Git worktree unless the owner explicitly requests it.

```sh
cd /workspace/EV
git status --short
git log -5 --oneline
git branch --show-current
git ls-remote --symref origin HEAD refs/heads/EV refs/heads/development/platform-foundation
gh pr view 1 --repo creabizsalonspa-create/EV --json url,isDraft,headRefOid,baseRefName,statusCheckRollup
```

Read both current source and evidence. The last committed checkpoint recorded when this file was first prepared was `e7e4833`; mobile/Sanctum and parallel finance/OCPI work were active and not all committed. Do not reset to that old SHA or assume these documents automatically describe later commits. Integrate any still-active independent work before making overlapping edits. The official Flutter checkout is a toolchain dependency under `/workspace/.toolchains`, not another product repository.

## Reproduce the current development workflow

```sh
cd /workspace/EV
./scripts/setup
npm ci --cache /workspace/.npm-cache
./scripts/start
./scripts/csms-setup
./scripts/csms-start
./scripts/php /usr/bin/composer validate --strict
./scripts/php vendor/bin/pint --test
./scripts/php artisan test --compact
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e
npm run test:ocpp
./scripts/php /usr/bin/composer audit --format=json
npm audit --json --cache /workspace/.npm-cache
```

Use a disposable development database: browser/OCPP tests create synthetic accounts, simulator stations and other records. Never target production. `scripts/setup` preserves existing `.env`, keys and DB contents; retain that behavior. Named Docker volumes and live processes must not be assumed restored after a cloud snapshot. `scripts/csms-start` checks the API; `npm run test:ocpp` provides the functional protocol check. Tokens/keys must stay out of output and Git.

GitHub API and Composer advisory requests previously failed but later succeeded. Retest the concrete operation before requesting access changes. No fresh token/reconnection is currently indicated. Use a system Chromium via `EV_CHROMIUM_PATH` when present; otherwise install the maintained Playwright browser normally and preserve TLS/artifact verification.

## Mobile continuation

Flutter 3.47.8 / Dart 3.13.7 was installed from the official stable Git source at `/workspace/.toolchains/flutter`; `scripts/flutter` points to it and isolates Pub/Gradle caches. Customer/vendor projects are `apps/customer` and `apps/vendor`, sharing `packages/ev_app`. Android/iOS source presence is not build success.

Historical analyzer and Android-download failures were corrected. `scripts/flutter` uses `ANALYZER_STATE_LOCATION_OVERRIDE=/workspace/.dart-analyzer`; shared analysis and 7 API/widget tests pass. Official Android command-line tools 23 were checksum-verified, with platform 36/build-tools 36.0.0/platform-tools installed. Inspect the latest build logs before repeating setup. Preserve checksum/TLS verification and supported workspace cache/state paths; do not repurpose `$HOME` or repeat the failed full Flutter container pull unchanged.

Run the real shared/client checks when affected source changes, and capture current results:

```sh
cd /workspace/EV/packages/ev_app
../../scripts/flutter pub get
../../scripts/flutter analyze
../../scripts/flutter test
cd ../../apps/customer
../../scripts/flutter pub get
../../scripts/flutter analyze
../../scripts/flutter test
../../scripts/flutter build apk --debug
cd ../vendor
../../scripts/flutter pub get
../../scripts/flutter analyze
../../scripts/flutter test
../../scripts/flutter build apk --debug
```

Add meaningful tests before interpreting a test run; an empty test directory is not success. Release builds need a real HTTPS staging API configuration, full features and distribution signing. Do not hardcode secrets or ship debug cleartext settings in release. iOS compilation/signing requires a macOS/Xcode runner; use an authorized CI runner when available and report the exact result/artifact.

## Dependency order and integration obligations

1. Finish current native API/client work and integrate active finance and OCPI tasks without overwriting unrelated changes. Register their route files deliberately, run migrations, and rerun the full relevant tests.
2. Complete identity verification/2FA, vendor KYC/staff/organization permissions and the sites/chargers/EVSEs/connectors hierarchy; finish all customer/vendor/admin UX and API parity rather than adding standalone demo screens.
3. Join CitrineOS transaction/meter/command/fault events to canonical business sessions using authenticated, idempotent contracts. Finish a full OCPP simulated start/meter/stop-to-invoice path, negative/replay/timeout/reconnect cases and truthful availability. Never replay an uncertain remote-start just to get a passing test.
4. Complete finance/tariff snapshots, immutable journals/invoices, test PSP checkout/refund/dispute/webhook reconciliation and tenant scopes; then subscriptions, settlements, fleet consolidated billing, loyalty/rewards/referrals and marketing/ads.
5. Complete outbound OCPI 2.2.1 role/capability negotiation, authorization, location/tariff/token/session/CDR/command adapters and contract/replay/reconciliation tests. The inspected upstream CPO-oriented component does not by itself fulfill outbound eMSP aggregation.
6. Complete maps/navigation/discovery attribution, notifications/delivery, accessibility/English-Arabic parity, reporting/observability, OpenAPI contracts, CI/mobile builds, staging/restore/load/security/rollback checks and final source/artifact packaging.
7. Use authorized actual devices, operators, payment providers and signing/store infrastructure only for the corresponding external gates. Local simulation is not physical, partner, merchant or legal verification.

Preserve a test/evidence-to-feature mapping, exact commands, source revision, environment, failed/unrun checks and next action in the status docs. Use GitHub Actions if Docker infrastructure becomes unavailable locally. Complete tests in the actual selected environment; do not manufacture URLs, artifacts, certifications, passes or unattended-continuation claims.

## Final response and optional-source policy

Only label the platform `100% COMPLETE` when every required acceptance criterion is implemented and verified. `SOFTWARE BUILD COMPLETE; EXTERNAL VALIDATION PENDING` also requires all independently executable software/build/integration QA to be complete; it does not apply while modules or builds are missing. If task/session/usage limits prevent further work, provide a short factual checkpoint and this exact continuation task, without implying background work or demanding module reviews. The owner performs final acceptance after internal QA, not routine development QA.

Do not buy CodeCanyon or subscribe to proprietary software. A later supplied licensed Naasmind/commercial ZIP is optional: audit source, legal end-product/subscription/extraction rights, security and data-model fit first. Retain Laravel as canonical business backend and CitrineOS as protocol service; do not introduce the package's MongoDB backend as a second authority. Reject reuse when it fails checks or adds work, and continue without it.

The owner explicitly requires real customer/vendor APKs and release-ready AABs, iOS projects and IPA when macOS/signing access permits, plus complete web builds and retained build evidence. Produce each real artifact, not screenshots or claims based on source scaffolds. Do not reuse debug signing for an artifact described as release-ready. Keep exact artifact filenames/checksums and commands in the test evidence.
