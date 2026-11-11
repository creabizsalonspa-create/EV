# Agent 9 — Controller-reflection route guard and refund HTTP regression

**8 November 2026** · PR #64 `development/agent9-payment-return-csp-20261107` · tested source commit `9ddff7d2e9c894796ffede231440dbe17eced1e9` · **draft/unmerged**.

## Motivation

A source-only audit of prior `whereNumber()` rules misses routes that never had any `where()` constraint, as confirmed by `POST /finance/refund-requests/{request}/review` with a typed `int $request` argument. Agent9 previously corrected that route by adding `[0-9]{1,18}` and new focused Node/Laravel tests. This increment adds a **runtime registered-route reflection audit** so similar future gaps can be detected automatically.

## New code

1. `5adffc11eb3b6fb4876dc4f043f87897dd560135` — new `services/business-api/tests/Feature/TypedIntegerControllerRouteIntegrityTest.php`. For each registered Laravel route whose action is a named `App\\Http\\Controllers\\...` method, uses `ReflectionMethod`, checks whether a controller parameter typed as built-in `int` matches a URI parameter name, and verifies its route constraint is exactly `[0-9]{1,18}`. Requires more than 50 inspected integer route parameters to guard against a vacuous pass. It reports the exact HTTP method, URI and controller when a route lacks a bound. Does not constrain Eloquent model-bound or validated string arguments; it also cannot comprehensively audit every invokable/closure action or unmatched positional parameter.
2. `83d6ba363a3a8a3e6e72fddb35cae096c11369bf` — new `tests/agent9-typed-controller-route-integrity.test.cjs`. Standalone Node CI canary prevents silently removing or diluting the PHP reflection audit. It is automatically picked up by the already committed `node --test tests/agent9-*.test.cjs` CI stage.
3. `9ddff7d2e9c894796ffede231440dbe17eced1e9` — extend `RefundReviewRouteBoundaryTest.php` beyond direct route-matcher assertions: for invalid IDs under both `/api` and `/api/v1`, issue real `postJson($invalid, [])->assertNotFound()` requests so a framework-level HTTP error is caught, not just a synthetic route match. Test only sends invalid paths; does not submit a valid finance review request.

## Actually executed without the authorized test host

`host.crea-biz.com` is **Offline**, so real Laravel test execution against the full registered route tree is **not yet performed**.

**Local container checks with exact GitHub blob hashes:**

- New Laravel reflection test: `php -l` **PASS**; local file SHA `efffd6bfa43a2a7242fc1f7a9b30617ab253071e`, verified identical to the live PR64 Git blob.
- Standalone Node test: `node --test` **2 tests / 2 PASS**; local file SHA `7b7545183914f994d4d65f53ae856e0f5447e974`, verified identical to the live PR64 Git blob.
- Updated refund-review PHP test: `php -l` **PASS**; local file SHA `367eb11c303bfc2e39c6f639370115c93fda74a2`, verified identical to live PR64 Git blob.
- Executed the actual reflection test method in an **isolated synthetic PHP harness** (not Laravel) using stubbed TestCase, Route, and controller classes: 60 correctly constrained `int`-typed mock routes + one validated-string favorite route passed, and an intentionally unconstrained mock `int` route was correctly detected as a failure. This proves the reflection detection mechanism and excludes the validated string case; **it is not a real application-route or PHPUnit suite pass**.

**Not run on this newest head:** complete Laravel Feature suite, real refund HTTP requests, real registered Laravel route reflection, full Node CI stage, PostgreSQL, web/native, hosted Platform/Finance/Native GitHub Actions, physical charging, real payment or production acceptance. The host's Offline status and the earlier exact-head queued GitHub jobs remain deployment/acceptance blockers.

## Agent4/Agent1 integration handoff

- Agent4 should retain the new PHPUnit Feature reflection test in the integrated backend suite, run it against the actual merged Laravel route registry, resolve any newly surfaced typed route gaps, preserve its existing CI concurrency/cancel/merge-readiness gates and Agent9 Node security stage, and rerun the full integrated suite and PostgreSQL/native jobs.
- Agent1 should review the refund-review route constraint within canonical finance ownership and land its independently validated SQLite settlement trigger idempotency fix; a previous Agent9 *local-only* `DROP TRIGGER IF EXISTS` probe passed the former full Feature suite, then was reverted. The prior green result cannot be attributed to this latest head.

**Status:** development changes pushed and isolated guard logic validated; Laravel application integration and production acceptance remain pending.
