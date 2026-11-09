# Agent 9 — exact-head runtime security and backend checkpoint

**10 November 2026 (UAE)** · PR #64 branch `development/agent9-payment-return-csp-20261107` · **tested source** `84b00193854f2fc68246370028e601a7cfe0a47c` · **draft/unmerged**.

## Authoritative hosted CI results for this exact source

GitHub Actions workflows reported **success** on the exact tested commit:

| Workflow | Run | Result |
| --- | --- | --- |
| Platform validation | [42681171904](https://github.com/creabizsalonspa-create/EV/actions/runs/42681171904) | SUCCESS |
| Finance commercial validation | [42681172014](https://github.com/creabizsalonspa-create/EV/actions/runs/42681172014) | SUCCESS |
| Native client validation | [42681171749](https://github.com/creabizsalonspa-create/EV/actions/runs/42681171749) | SUCCESS |

These are hosted CI outcomes, not production deployment or live partner/hardware/payment acceptance. Agent4 PR57 and Agent1 PR83 remain separate unmerged branches; their green checks do not make the integrated branch accepted.

## Independently executed on authorized remote host

**Host:** `host.crea-biz.com`, PHP 8.4.24, PHPUnit 11.5.57, Node 24.22.1. Old QA workspace `/tmp/ev-agent9-qa-20261107` has six pre-existing local modified/untracked files and was not reset, overwritten, committed or touched. Created a separate detached exact-head checkout at `/dev/shm/ev-agent9-pr64-20261110` to avoid nearly full `/tmp` inode allocation, and re-used locally installed Composer dependencies by copying the `vendor` directory. Verified `vendor/composer/autoload_psr4.php` resolves `App\\` to the exact new checkout rather than the old QA workspace; `node_modules` linked read-only to its installed dependencies. No production credentials, PSP settlement or physical charger commands.

- **Standalone Node security suite:** `node --test tests/agent9-*.test.cjs` → **45 tests / 45 PASS**; includes all bounded numeric route guards, authenticated private file/download controls, signed-firmware, route reflection source canary, loopback transport sanitization, diagnostic server recovery and QA safety guard tests.
- **Focused actual Laravel Feature:** `TypedIntegerControllerRouteIntegrityTest|RefundReviewRouteBoundaryTest` → **2 tests / 14 assertions / PASS**. Checks the actual registered Laravel route registry and invalid /api + /api/v1 refund-review HTTP responses rather than an in-memory mock.
- **PHP syntax:** both new Feature tests `php -l` → **PASS**.
- **PHP Pint:** `vendor/bin/pint --test routes tests/Feature/RefundReviewRouteBoundaryTest.php tests/Feature/TypedIntegerControllerRouteIntegrityTest.php` → **37 PHP files / PASS**.
- **Full Laravel Feature on untouched exact Git source:** `phpunit --testsuite Feature --no-progress --display-errors` → **601 tests / 8,527 assertions / 1 ERROR**, the known inherited SQLite trigger drop failure `SettlementTest::test_precision_migration_backfills_existing_adjustments_without_changing_economics` at `services/business-api/database/migrations/2026_10_28_120002_snapshot_adjustment_precision.php:25` (`SQLSTATE[HY000]: no such trigger: commercial_adjustments_no_update`). No other local Feature test error or failure.

All local Laravel runs used `APP_ENV=testing`, SQLite `:memory:`, generated per-run test key, array cache/session/mail, sync queue, and stderr logging. Synthetic 503/connection warnings in firmware negative tests were expected failure-path cases, not live outages.

## Isolated finance migration correction trial (NOT committed)

The SQLite schema alteration can rebuild `commercial_adjustments` and implicitly remove its trigger, making the subsequent unconditional `DROP TRIGGER commercial_adjustments_no_update` fail. **Temporary test-only change inside the disposable detached worktree**:

```diff
- DB::unprepared('DROP TRIGGER commercial_adjustments_no_update');
+ DB::unprepared('DROP TRIGGER IF EXISTS commercial_adjustments_no_update');
```

- `php -l` of the locally modified migration: **PASS**.
- Full Laravel Feature suite **with this local-only one-line correction:** **601 tests / 8,529 assertions / PASS**.
- **Immediately restored** the migration from Git HEAD after testing, reverified exact Git blob `7373ad875142aac05a9381e9578f308d9fbed6a6` and confirmed `git diff --name-only` for tracked source was empty. No modification, commit, push or merge of this finance patch to Agent9, Agent1 or Agent4. (The temporary worktree retained an intentionally untracked `node_modules` dependency symlink until cleanup.)

**Agent1 owns the canonical migration.** These local SQLite results do not replace PostgreSQL migration, rollback, data retention or finance accounting acceptance. The hosted GHA success and local SQLite error are distinct test environments and should both be reported.

## Integration preflight (read-only dry-run)

With GitHub-provided Agent4 PR57 head `722416242753ea56f8cb4890c3774bee34be70f0`, Agent9 tested head `84b00193854f2fc68246370028e601a7cfe0a47c`, and their previously established merge-base `d2568039d597e9e274be38a4b915bcf70a4d7c78`, ran `git merge-tree --write-tree --merge-base=<base> --name-only <agent4> <agent9>`.

**Result:** conflict-free synthetic Git tree `0e2f1e3e436385bb8ea20cd0963d4431f55d1d09`. The merged CI source retains Agent4's workflow concurrency, cancel-in-progress and integration-readiness/queue tests, plus Agent9's `node --test tests/agent9-*.test.cjs` stage. The synthetic tree was **not merged into an actual branch** and not separately tested; clean merge-tree is not merge approval.

## Remaining gates

1. Agent1: review and land the canonical settlement migration fix with SQLite and PostgreSQL migration/rollback and economic invariance testing.
2. Agent4: re-evaluate CI on the **actual combined integrated commit** (not each PR's individual head), preserving native CI, finance PostgreSQL, security guard and operational/browser tests.
3. Confirm production credentials/agreements, live OCPP charger hardware, PSP transaction and settlement, store signing, legal approvals and deployment evidence separately. **Production accepted remains 0% by currently verified data.**

This report preserves reproducible distinctions: exact-head GHA green, separate local security green, one inherited local finance migration error, disposable IF EXISTS experiment green, and no production/merge assertion.
