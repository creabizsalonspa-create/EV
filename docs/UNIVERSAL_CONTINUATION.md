# Continue the complete platform

All authorized accounts are full-stack maintainers. Continue existing verified work; do not restart or permanently divide backend/frontend ownership. Preserve all changes and push each verified checkpoint. No background execution is promised after a task stops.

## Resume task

> Fetch every remote branch and inspect current commits/PRs in creabizsalonspa-create/EV. Safely continue development/universal-integration (or its integrated successor), retaining other account changes. Read all project Markdown and current PROGRESS/QA/FRONTEND_INTEGRATION/CHARGING/FINANCE/VENDOR_OPS/OCPI docs. Canonical native client is apps/mobile; do not regenerate duplicate apps. Complete the continuous authenticated CSMS consumer and client routing for linked sessions next, then PostgreSQL finance concurrency validation and the remaining full commercial requirements. Run all affected backend/browser/native/OCPP/finance/OCPI/security tests, fix failures, preserve evidence and commit/push each verified step. Never force-push or claim simulation as hardware/PSP/partner verification. Keep missing implementation separate from genuinely external prerequisites.

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
node tests/charging-session.cjs
node tests/charging-business.cjs
```

These tests create synthetic local data; never target production. Setup must preserve `.env`, keys and existing data. The event setup creates matching private local signing keys only when absent and preserves existing bindings. Source ingestion/dispatch are production-disabled. Processes restart after restore. Docker-volume/toolchain restoration is unverified.

Native: use pinned Flutter3.35.7/sourceadc901062556672b4138e18a4dc62a4be8f4b3c2 per apps/mobile/README.md, not the retired experimental SDK/client. `apps/mobile/tool/bootstrap` enforces lock, analyzes and runs tests. Set writable PUB_CACHE/XDG_CONFIG_HOME/ANALYZER_STATE_LOCATION_OVERRIDE without changing HOME. For actual local API coverage, run `flutter test --dart-define=EV_NATIVE_SMOKE_URL=http://127.0.0.1:8000 test/live_api_test.dart --reporter expanded` from apps/mobile. Keep default skipped live test separate from the opted-in pass.

`tests/Postgres/run-finance` is the isolated PostgreSQL runner. Read its instructions, ensure enough disk and use only disposable resources; prior local storage/readiness failures do not count as passed. Prefer a hosted Linux runner if local capacity is insufficient. No global Docker prune or existing database removal.

## Unfinished priorities

1. Continuous authenticated event/command adapter, explicit CSMS-linked client controls, terminal failure/reconciliation and operational retry policies. The current passing end-to-end driver forwards actual source observations but is not a daemon.
2. PostgreSQL immutable migrations/concurrent finance proof; complete connector-level booking and maintenance/company gates.
3. Full existing web/native UX for the mounted finance/vendor/OCPI APIs and staff personas. Preserve themes/RTL/private views and the shared backend.
4. 2FA/privacy, PSP sandbox/webhooks/disputes, authoritative subscriptions/loyalty/settlements/fleet billing and all remaining PRD features.
5. Premium visual/screenshot/accessibility/device/restore/load/security/staging QA; real release-ready APK/AAB/IPA and final source package only after implementation/verification. Hardware/merchant/operator/signing/legal prerequisites remain explicit.
