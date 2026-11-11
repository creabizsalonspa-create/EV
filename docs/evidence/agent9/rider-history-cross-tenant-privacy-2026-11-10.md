# Agent 9 — Rider history cross-tenant privacy and fail-closed integration QA

**Date:** 10 November 2026 (UAE). **Agent 9 PR:** [#64](https://github.com/creabizsalonspa-create/EV/pull/64), functional SHA `88a94a7688dec60e0a9c8e9e315bb41143c76f56` (draft/unmerged). This is security regression evidence, not production acceptance or a real multi-PR merge.

## Objective and code delivered

Agent1 PR83 introduces `RiderAccessHistory` and authenticated customer/operator token and grant activity endpoints. Agent9's prior PR64 test `RiderHistoryRouteIntegrationBoundaryTest` covers the numeric grant route compatibility with Agent1's feature; an earlier three-way merge simulation identified and resolved a single `routes/rider_access.php` textual conflict **only inside a disposable test tree** (details in `rider-history-three-agent-merge-2026-11-10.md`).

The new `RiderHistoryCrossTenantIntegrationTest.php` adds **an actual Laravel request-based privacy matrix** to the integrated feature, testing both `/api` and `/api/v1`:
- Customer token activity history is limited to the owning customer; another customer, a vendor and unrelated actors must not read it.
- Operator grant activity history is visible to the correct company vendor and admin; unrelated vendors fail whether trying the legitimate company or their own unrelated company.
- Neither customer nor operator history returns the private synthetic card value, encrypted review note, actor_id, token digest, review_note, or other private internal fields.
- Authenticated responses must include `no-store`; unsupported `kind=raw` and excessive `page=100001` query parameters must return 422.
- Oversized (19- and 120-digit), negative and scientific-notation grant IDs must fail closed before any activity read.
- The read-only test verifies no charger commands or finance invoices are created.

**Fail-closed feature detection:** On Agent9-only source, `RiderAccessHistory` does not exist and the new feature-specific test is explicitly **skipped** (not reported as passed). Once the history service is present after integration, the test requires both `activity` and `grantActivity` controller methods and fails if either disappears. `RiderHistoryRouteIntegrationBoundaryTest.php` was similarly tightened to treat missing activity methods as a regression when the service exists. The new PHP test is automatically collected by the existing Laravel Feature suite when merged.

## Exact Agent9 commits

- `33e4ae13da00eb232c114b6c1d3deb30e71f2dd0`: new `RiderHistoryCrossTenantIntegrationTest.php`.
- `94e23ba3657be54d266b091cb0f14b71723acf0b`: adds no-store, audit redaction and query bounds coverage.
- `b4b8cb24ee9f60174e5067a0581dd60709e4bd5a`: fail if integrated history service remains but its controller methods disappear.
- `62d6df76650aff5628306dc2bb386e0ca3ce5ff6`: apply same fail-closed guarantee to `RiderHistoryRouteIntegrationBoundaryTest.php`.
- `88a94a7688dec60e0a9c8e9e315bb41143c76f56`: Laravel Pint import sorting.

**Final Git blob checks:** `RiderHistoryCrossTenantIntegrationTest.php` → `80cabfe45e334a5224ff4cd76c2a7d7105fa41d2`; `RiderHistoryRouteIntegrationBoundaryTest.php` → `94635165b4a6aaffebbad87e0dc45fd92266d0bc`.

## Actual execution evidence

All runs executed on authorized `host.crea-biz.com` with PHP 8.4.24, PHPUnit 11.5.57, isolated in-memory SQLite, `APP_ENV=testing`, random temporary app keys, array cache/session and sync queues. No production account, physical charger, card authorization, live PSP or vendor network was contacted. Separate temporary `/dev/shm` worktrees and Composer vendor dependencies kept the pre-existing six dirty files in `/tmp/ev-agent9-qa-20261107` untouched.

### Agent9 standalone exact head `88a94a7`

- Selected `RiderHistoryCrossTenantIntegrationTest|RiderHistoryRouteIntegrationBoundaryTest|TypedIntegerControllerRouteIntegrityTest`: **4 tests, 15 assertions; 1 expected skip** for new Agent1 history service not yet in PR64; all executed checks passed.
- Laravel Pint **2/2** new/updated Feature test files PASS; PHP syntax PASS.

### Actual three-agent synthetic code plus Agent9 test overlay

Base synthetic three-agent Git commit `6adcba4f48394d858a125b4f5b3797307034fa02` represents Agent4 PR57 `7224162`, Agent9 earlier PR64 `74848ed` and Agent1 PR83 `fa699aa` with the **single rider route conflict manually resolved in an unreferenced disposable tree**. New privacy test files from the exact latest Agent9 Git blob SHAs above were overlaid in a separate temporary worktree. Consequently this **is not a single pushed, tagged, CI-tested merged Git SHA**.

- Selected integration suite `RiderHistoryCrossTenantIntegrationTest|RiderHistoryRouteIntegrationBoundaryTest|RiderAccessHistoryTest|TypedIntegerControllerRouteIntegrityTest`: **8 tests /185 assertions PASS** on final updated test files.
- Laravel Pint **2/2** updated integration Feature tests PASS; PHP syntax PASS.
- **Negative control:** in the disposable combined checkout, intentionally changed `RiderAccessController::activity` method name to `activityRemovedForQANegativeControl`. The new privacy regression correctly **FAILED** with `Failed asserting that false is true` and exit code 1, rather than silently skipping. Restored the original controller and verified its exact Git blob before the later full green run.
- Complete Laravel `--testsuite Feature` with the **separate, temporary local-only** SQLite `DROP TRIGGER IF EXISTS commercial_adjustments_no_update` workaround: **631 tests, 8,868 assertions, PASS**. Earlier test versions at the same candidate produced 631/8,840 and then 631/8,866; **631/8,868** is the final enhanced test suite result.
- The separate temporary SQLite migration workaround was **restored to original Git blob** `7373ad875142aac05a9381e9578f308d9fbed6a6` after testing. The original canonical migration retains unconditional `DROP TRIGGER` and the known local SQLite settlement test failure. No migration patch was pushed to Agent9/Agent1/Agent4.
- The complete Agent9 Node security suite passed **45/45** on the previous synthetic combined candidate, before the above PHP-only changes; it was not rerun after these latest PHP-only test edits.

Both disposable QA worktrees were cleaned up after the runs; the original QA worktree remained unchanged with its existing six tracked/untracked changes.

## Integration owner actions and remaining blockers

1. **Agent4:** Resolve the actual `routes/rider_access.php` merge conflict following `docs/evidence/agent9/rider-history-three-agent-merge-2026-11-10.md`; preserve Agent1's two activity routes and apply `[0-9]{1,18}` to the newly introduced grant activity route along with existing integer grant operations. Include both new Agent9 Feature tests and run them on **the real final combined commit**, not only the disposable simulation.
2. **Agent1:** Own and review the independently reproduced SQLite settlement precision migration correction, plus PostgreSQL migration and rollback/economics testing. No canonical finance source was modified by Agent9.
3. **CI/release:** Latest Agent9 head's hosted Platform/Finance/Native workflows were queued when checked; the older Agent9 head `84b0019` had all three hosted workflows SUCCESS. Neither individual-PR CI nor the disposable combined SQLite suite is proof of hosted CI on the eventual merge, live hardware/PSP settlement, native store approvals or a production deployment.

**Verdict:** the latest rider activity tenant-privacy guards and regression tests are implemented and locally validated on the synthetic integrated source; the real merge and production acceptance are still outstanding.
