# Continue the complete platform

All authorized accounts are full-stack maintainers. Continue existing verified work; do not restart or permanently divide backend/frontend ownership. Preserve all changes and push each verified checkpoint. No background execution is promised after a task stops.

## Resume task

> Fetch every remote branch and inspect current commits/PRs in creabizsalonspa-create/EV. Safely continue development/fullstack-continuation (safe successor of development/universal-integration), retaining other account changes. Read all project Markdown and current PROGRESS/QA/FRONTEND_INTEGRATION/CHARGING/FINANCE/VENDOR_OPS/OCPI docs. Canonical native client is apps/mobile; do not regenerate duplicate apps. Continue the verified source consumer and linked-session/invoice clients with safe outbox provisioning/dispatch/reconciliation and full vendor/connector workflows; preserve the passing PostgreSQL finance concurrency tests and the remaining full commercial requirements. Run all affected backend/browser/native/OCPP/finance/OCPI/security tests, fix failures, preserve evidence and commit/push each verified step. Never force-push or claim simulation as hardware/PSP/partner verification. Keep missing implementation separate from genuinely external prerequisites.

```sh
cd /workspace/EV
git status --short
git fetch origin '+refs/heads/*:refs/remotes/origin/*'
git branch -avv
git log --all --graph --oneline -25
gh pr list --state all
```

The current branch began at frontend92a8c8b and integrated interrupted finance/vendor/OCPI/native-token/charging work. Existing PRs#1/#2 remain preserved. Fetch current refs rather than assuming an older source SHA is still latest. Source/Git preservation backups in this instance are under `/workspace/scratch/ev-preservation`; incomplete drafts there are not active modules.

## Reproduce

```sh
./scripts/setup
npm ci --cache /tmp/ev-npm-cache
./scripts/start
./scripts/csms-setup
./scripts/csms-start
./scripts/charging-setup
./scripts/charging-bridge-setup
./scripts/php vendor/bin/pint --test
./scripts/php artisan test --compact
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:frontend
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:localization
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:web-session
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:vendor
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:roaming
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:invoices
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:tariffs
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:managed-gate
node tests/charging-session.cjs
node tests/charging-business.cjs
```

These tests create synthetic local data; never target production. Setup must preserve `.env`, keys and existing data. The event setup creates matching private local signing keys only when absent and preserves existing bindings. Source ingestion/dispatch are production-disabled. Processes restart after restore. Docker-volume/toolchain restoration is unverified.

Native: use pinned Flutter3.35.7/sourceadc901062556672b4138e18a4dc62a4be8f4b3c2 per apps/mobile/README.md, not the retired experimental SDK/client. `apps/mobile/tool/bootstrap` enforces lock, analyzes and runs tests. Set writable PUB_CACHE/XDG_CONFIG_HOME/ANALYZER_STATE_LOCATION_OVERRIDE without changing HOME. For actual local API coverage, run `flutter test --dart-define=EV_NATIVE_SMOKE_URL=http://127.0.0.1:8000 test/live_api_test.dart --reporter expanded` from apps/mobile. Keep default skipped live test separate from the opted-in pass.

`tests/Postgres/run-finance` is the isolated PostgreSQL runner. Read its instructions, ensure enough disk and use only disposable resources; current local and hosted integrated finance/concurrency suites pass. Historical storage/readiness failures are retained as earlier evidence, not the current result. Prefer a hosted Linux runner if capacity is insufficient. No global Docker prune or existing database removal.

## Unfinished priorities

1. Extend the verified simulator-only continuous authenticated source observer and linked client controls into safe production command provisioning/dispatch/reconciliation and terminal failure policies. The daemon now passes both protocol restart tests; do not recreate it.
2. Complete connector-level booking and maintenance/company gates, preserving passing PostgreSQL immutable migrations/concurrent finance proof.
3. Continue full native company/staff/KYC and operator roaming UX, secure admin connection onboarding and complete finance workflows. Web company/staff/inventory, web/native read-only partner catalogs and web OCPI operations are implemented. Preserve themes/RTL/private views and the shared backend.
4. 2FA/privacy, PSP sandbox/webhooks/disputes, authoritative subscriptions/loyalty/settlements/fleet billing and all remaining PRD features.
5. Premium visual/screenshot/accessibility/device/restore/load/security/staging QA; real release-ready APK/AAB/IPA and final source package only after implementation/verification. Hardware/merchant/operator/signing/legal prerequisites remain explicit.

Current integrated branch preserves b0c43be and frontend through 0fe9e75. Source observer: ./scripts/csms-consume --interval=2 after charging setup + bridge setup. It must restart after restore. Both-protocol daemon restart simulation and isolated PostgreSQL finance/concurrency are now verified locally; current evidence and remaining gates are in PROGRESS/QA. Do not resume retired duplicate native paths from historical CONTINUATION/PROJECT_STATUS; apps/mobile is canonical.

Read REQUIREMENT_STATUS.md for the complete PRD bullet mapping and the connector-identity/maintenance findings. The owner’s MASTER_CONTINUATION.md remains the standing objective; no claim of complete commercial software or inactive background execution is made.

Native CI now uses macos-15/Xcode16.4/iOS18.5 and passed at 890b081. Do not infer latest iOS26/store readiness from this baseline. Web generation isolation and pending logout use no persistent credentials; delayed private responses must never repopulate a changed account. Keep the dedicated web-session ordering tests in CI.
