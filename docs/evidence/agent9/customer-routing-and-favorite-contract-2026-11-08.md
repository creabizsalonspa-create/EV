# Agent 9 — customer review/catalog, favorites and hosted-simulator route QA

**Date:** 8 November 2026. **Exact tested source:** Agent9 draft PR #64 `development/agent9-payment-return-csp-20261107` at Git commit `de1ce2a259110a7eb39666873463bb5a428aed25`. **Unmerged**. No production, live charger, bank, card PSP, partner or store acceptance is claimed.

## Scope

The earlier security review identified numeric route patterns accepting arbitrarily long positive digit strings. This batch prevents malformed/19+-digit values from reaching **five charging-review action routes**, both **public station-review catalog routes**, and two authenticated **hosted payment-simulator pages** by using `[0-9]{1,18}`. Existing catalog throttles, session and role authorization, simulation page login requirement, CSP and referrer controls were retained.

Two customer **favorite** actions were initially changed in the same batch, but a real Laravel regression correctly caught an established API contract difference: `StationFavoriteTest::test_favorites_paginate_with_stable_order_and_missing_stations_are_not_invented` expects **HTTP 422** for an enormous all-digit ID, not 404. The `StationFavoriteController::stationId(string $station)` method explicitly runs `required|integer|min:1` validation **before** the integer cast, safely rejecting invalid values. Consequently, `routes/favorites.php` was restored to its original `whereNumber('station')` behavior, and the new Node/Laravel regression contracts were updated to enforce that distinction rather than incorrectly changing a published response code.

**Net result:** 9 customer/web route constraints inspected; **7 newly bounded** (five private charging-review actions and two public catalog GET routes) plus **2 hosted simulator GET constraints** bounded; two existing favorite routes retain explicit controller validation and 422 behavior. This is **9 newly bounded route declarations**, not 11, because the two favorites were reverted.

## Commits

- `42a4ae0b0b7f9c81bde39a8d4188be290e022fef`: five charging review routes bounded.
- `4e3bdd82a64ca603ee6ccb43fad9ceceb888b7ab`: initial attempt to bound favorites; later reverted after real test failure.
- `a55cc32105d174f96fbe0387666c3595d4c212ae`: authenticated v1 public-review catalog route bounded.
- `58f5f7dbc1c7eb682bb0abb9fb9eb9789a3623a3`: web public-review and both authenticated hosted simulator GET routes bounded.
- `d67f64073e0ba2f3746ace6a16d1ea58eb715bb5`, `9f5f1b93b6acb878b7cdaad9c4fe4a1aacf9d7bc`: new Laravel and Node route regressions.
- `8a359f5e7da4610063a69ccc529963f8c4fcffda`: restore favorites' explicit validator's 422 API contract.
- `18de4cfe03ba7814ed7ad46f8e662231f5ea8b9f`, `de1ce2a259110a7eb39666873463bb5a428aed25`: updated Node and Laravel tests to assert the correct favorite exception.

## Actually executed QA on authorized development host

A fresh, disposable, detached sparse checkout `/tmp/ev-agent9-customer-qa` was created from the exact Git commit. Local Composer dependencies were copied so the PSR-4 `App\\` path pointed into that checkout. PHP 8.4.24, PHPUnit 11.5.57, Node v24.22.1. Isolated `APP_ENV=testing`, SQLite `:memory:`, array cache/session and a generated temporary key; no actual device/PSP actions.

Initial Laravel test result *before the corrective favorite revert*: **16 tests, 176 assertions, 1 FAIL**, failure solely at `StationFavoriteTest.php:60` from unexpected 404 vs required 422.

After correcting the route and test:
- `node --test tests/agent9-review-favorite-route-boundary.test.cjs`: **4/4 PASS**.
- `node --test tests/agent9-*.test.cjs`: **26/26 PASS** (all Agent9 standalone security guards).
- PHPUnit Feature selection `ChargingReviewsTest|StationFavoriteTest|HostedCheckoutPageTest|ReviewAndFavoriteRouteBoundaryTest`: **17 tests, 209 assertions, PASS**.
- `vendor/bin/pint --test` on `routes/api.php`, `routes/web.php`, `routes/charging_reviews.php`, `routes/favorites.php` and the new Feature test: **5/5 files PASS**.
- PHP syntax of the new Laravel Feature test: **PASS**.

**Important limits:** This incremental batch has **not** rerun the entire Laravel Feature suite, PostgreSQL, Android/iOS native, hosted GitHub Actions or physical-device/PSP acceptance; shared host inode pressure was high. Previous PR64 exact backend work ran 593 tests, 8,333 assertions with one inherited settlement SQLite trigger error, and a local-only one-line `DROP TRIGGER IF EXISTS` probe made all 593/8,335 pass before that temporary patch was reverted. That inherited issue is still assigned to canonical Agent1.

## Integration owner review

Agent4 must preserve its PR57 CI concurrency/queue/merge-readiness changes in `.github/workflows/ci.yml`, verify the Agent9 security CI step, reconcile route changes (including `api.php` and `web.php`) without removing login or throttling, and run the full integrated source and hosted CI after Agent1 repairs the settlement migration. This batch does not justify production-ready status.
