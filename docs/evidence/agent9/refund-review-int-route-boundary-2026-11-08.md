# Agent 9 — unconstrained refund review integer route

**8 November 2026 — candidate commit `057afa09e3773cc2ced65924b24f7925b03720eb`**, draft/unmerged PR #64.

## Source-confirmed issue

The preceding global numeric constraint inventory verified that `whereNumber()` had only two remaining documented favorites exceptions. That inventory could not catch URI routes that had **no route-level numeric constraint** to begin with. An additional manual source review found:

```php
// Previous code: no numeric ID constraint.
Route::post('/finance/refund-requests/{request}/review', [C::class, 'review']);
```

`CommercialFinanceController::review(Request $r, int $request, FinanceService $finance)` takes `$request` as a signed PHP integer. A 19+-digit numeric URI parameter could pass route matching and fail at the typed action boundary instead of returning a route-level 404.

The correction is scoped to this existing operation and does not change its HTTP method, URI, authenticated group, role/tenant checks or finance logic:

```php
Route::post('/finance/refund-requests/{request}/review', [C::class, 'review'])->where('request', '[0-9]{1,18}');
```

## Commits and verification

- `00b078931053a78128713169d1fbd3e0db518586` — update `services/business-api/routes/commercial_finance.php` to bound refund review request IDs. Exact Git blob `3a97a4cf9f0fca0e6e53c90d390c85000d60e713`.
- `ac11044ad7d9498d3cda1e570395bad1b07c71db` — new `RefundReviewRouteBoundaryTest.php`: real Laravel route-matcher test for both `/api` and `/api/v1`, checking ordinary positive IDs still match and invalid, negative, scientific, 19-digit and 120-digit IDs cannot dispatch. Exact Git blob `0468333baddc82fd83d82674cffce96d55ea0a90`.
- `057afa09e3773cc2ced65924b24f7925b03720eb` — new `agent9-refund-review-route-boundary.test.cjs` standalone Node source guard pins the controller `int` signature and bounded route policy. Exact Git blob `f742d19cc90ce81e4f52c3fe5fb576243ff502ba`.

**Executed verification:**
- Two source-guard test callbacks were executed under V8 against the exact current-head GitHub Node test, route and controller source contents using controlled `node:fs`/`node:path`/`node:assert/strict` shims: **2/2 pass**. This is executable source-contract verification, not a real `node --test` process.
- The new PHP Feature file was reconstructed byte-for-byte in the local container, matched its Git blob hash `0468333baddc82fd83d82674cffce96d55ea0a90`, then **`php -l` passed**. This is syntax only, not application-runtime validation.

**Not yet verified:** actual `phpunit` execution, exact-head complete Node security suite, full Laravel Feature, PostgreSQL, browser/native, hosted Platform/Finance/Native CI, live finance/PSP and deployment. Authorized remote development host initially appeared Online, but its command relay repeatedly timed out during attempted isolated-checkout setup; no host-side copy, test result, cleanup or incident resolution is asserted. GitHub Actions associated with prior PR64 head remained queued when checked.

## Integration actions

Agent4: integrate the new route constraint and Laravel/Node tests into the canonical branch, preserve PR57 CI concurrency/queue/merge-readiness and run the authenticated /api + /api/v1 matcher tests on exact merged source. Agent1: review finance ownership and still resolve the independently reproduced SQLite settlement precision migration `DROP TRIGGER commercial_adjustments_no_update` failure. A prior *local-only* `DROP TRIGGER IF EXISTS` probe made an older PR64 full Feature suite green and was reverted; that does not establish the latest source as fully passing or production-ready.

Keep PR64 in draft until the integrated CI and QA gates actually pass.
