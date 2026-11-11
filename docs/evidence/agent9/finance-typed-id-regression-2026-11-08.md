# Agent 9 — signed integer finance route boundaries and regression checkpoint

**Date:** 8 November 2026. **Tested candidate:** PR #64 (`development/agent9-payment-return-csp-20261107`) at exact Git commit `967566848ec00319efb4c704edb7b3ff324e8ce7`. The code is not merged or production accepted.

## Scope and threat model

Laravel's unbounded `->whereNumber('id')` permits numeric path strings longer than a signed PHP `int`. Payment/settlement controllers declare integer route parameters; dispatch of a syntactically numeric but overflowing identifier can result in a PHP type conversion exception and HTTP 500 rather than a fail-closed 404. The earlier PR64 hardening covered primary gateway routes and charger operations. This increment covers the remaining particularly sensitive finance groups:

- `services/business-api/routes/payouts.php`: **7** existing numeric route constraints (payout account, vendor, settlement, payout).
- `services/business-api/routes/settlements.php`: **7** (vendor, adjustment, settlement).
- `services/business-api/routes/subscription_gateway.php`: **3** (subscription and checkout).
- `services/business-api/routes/financial_documents.php`: **2** (private document read/download).

**Total: 19 existing routes constrained** to `[0-9]{1,18}`, rejecting malformed and 19+ digit IDs *before* controller dispatch. Existing `auth`/`auth:sanctum`, tenant checks, payment action allowlists and document `pdf|csv` format allowlist are unmodified. The 18-digit ceiling is deliberately below the PHP signed-64-bit maximum; although this rejects some theoretical 19-digit integer IDs within that maximum, it is not expected to affect auto-incrementing real-world database identifiers. Reevaluate if the domain schema ever supports values that high.

## Commits

- `06b2b9ff3340d609dfc05158bd532a002c8b960a`: payout route limits.
- `2a8ab68d2265fd464588c1479f857a047107e42d`: settlement route limits.
- `4c199378a5b9e88a1a873fcc97a0cef0dfb079bf`: subscription checkout route limits.
- `a4c5aaebb4aaf9c916f44838c31f218c7aedff04`: private financial-document route limits.
- `2b0156199294b9b1f3e51b34426e282c32251c0f`: new `FinanceTypedRouteBoundaryTest.php`, exercising 19 positive matches across **both** authenticated `/api` and `/api/v1` route mounts and negative strings that must fail routing.
- `967566848ec00319efb4c704edb7b3ff324e8ce7`: new `agent9-finance-typed-route-boundary.test.cjs`, tracking all 19 bounded route declarations, old action allowlists and malformed/overflow patterns.

## Executed local QA

Authorized `host.crea-biz.com`; disposable, detached sparse Git checkout `/tmp/ev-agent9-finance-qa` at the exact SHA above. Original agent-owned QA checkout was not modified. Node v24.22.1, PHP 8.4.24, PHPUnit 11.5.57; local copies of dependencies with PSR-4 App autoload verified to point to this checkout. APP_ENV=testing, SQLite in-memory, random test APP_KEY, array cache/mail/session, sync queue, no external payment hardware/provider calls.

| Command/test selection | Result |
| --- | --- |
| `node --test tests/agent9-finance-typed-route-boundary.test.cjs` | **3/3 PASS** |
| `node --test tests/agent9-*.test.cjs` (all security source guards) | **22/22 PASS** |
| `phpunit --testsuite Feature --filter FinanceTypedRouteBoundaryTest` | **1 test, 39 assertions PASS** |
| `phpunit --testsuite Feature --filter 'VendorPayoutTest|SubscriptionGatewayTest|FinancialDocumentsTest|FinanceTypedRouteBoundaryTest'` | **42 tests, 527 assertions PASS** |
| `phpunit --testsuite Feature --filter 'SettlementTest::(?!test_precision_migration_backfills_existing_adjustments_without_changing_economics)'` | **9 tests, 93 assertions PASS** |
| `pint --test` on four modified route PHP files + the new Laravel Feature file | **5 files PASS** |
| `php -l tests/Feature/FinanceTypedRouteBoundaryTest.php` | **PASS** |

**Important inherited blocker:** The excluded settlement precision migration test is not newly passing; the prior untouched PR64 full Feature suite ran **593 tests, 8,333 assertions, 1 known SQLite trigger error** (see `pr64-exact-qa-2026-11-08.md`). A disposable one-line `DROP TRIGGER IF EXISTS` migration probe made that previous full 593/8,335 suite pass and was then reverted; canonical owner Agent1 needs to integrate and validate that fix. No **full 594-test** (new test added) Feature suite execution was attempted at this exact head because the shared QA filesystem is near inode exhaustion. There are no claims of PostgreSQL, hosted CI, native builds, physical hardware, live PSP or production acceptance.

## Integration handoff

Agent1 controls canonical finance source; Agent4 controls integration. Both should check these four route files against their own PRs, preserve Agent4's stronger CI concurrency/merge gates, verify the full merged source on PostgreSQL and the hosted Platform/Finance/Native runners, and re-run financial web/mobile workflows. Do not merge PR64 as green while canonical settlement migration error and queued hosted runs remain.
