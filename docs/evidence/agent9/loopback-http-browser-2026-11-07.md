# Agent 9 — live loopback HTTP and Chromium QA (2026-11-07)

This is **isolated source-level/runtime QA**, not production, external hosted PSP, live hardware, staging acceptance, or merge authorization.

## Exact code provenance

- Agent 9 PR #64: `development/agent9-payment-return-csp-20261107`.
- PHPUnit focused checks verified on exact source SHA `64218e160786d664448e198dade62c1fed5b5b25`: **22 tests, 164 assertions, pass**.
- Read-only live loopback HTTP test code and hostile-Origin check verified at exact source SHA `4b2c0233b1602e08b26a0dcb3afca2f8fadea3d9`: **7/7 tests pass**.
- Chromium test code verified on the same SHA: **4/4 headless browser tests pass**.
- QA runner safety/target negative tests verified at SHA `64218e160786d664448e198dade62c1fed5b5b25`: **2/2 test groups pass**, checking **28 invalid URL / no-URL attempts** across two runners. No external request was made.
- The source checkout stayed clean after all tests. Only the temporary `/tmp/ev-agent9-qa-20261107` workspace and a PHP 8.4 Artisan server bound to **127.0.0.1:18764** were used.

## What was actually exercised

1. HTTP `GET /payments/hosted-return` with spoofed success/query payment status: page returned 200 but could not expose or honor the forged payment result; strict `base-uri 'none'` CSP, private no-store and no-referrer were present.
2. Corporate hosted return with `checkout_family=invoice` and fake success: the server remained locked to corporate family.
3. Anonymous `GET /api/me`, vehicles, reservations, fleets, Admin overview, charger commands, support: seven 401 denials with no-store response headers.
4. Cross-origin `Origin: https://attacker.invalid` requests: neither public CSRF nor private identity API reflected the hostile origin as an authorized credentialed caller. Laravel's wildcard public API CORS had **no credentialed wildcard** behavior.
5. Anonymous test-simulator URLs refused controls; redirects were restricted to the same loopback origin.
6. Headless Chromium ran standard and corporate payment-return pages, including valid-looking but unauthorized original checkout UUIDs. No POST request, popup, third-party resource, false capture indicator or JavaScript page error occurred.
7. Browser layout was checked at 390px / 768px / 1440px in dark mode and Arabic RTL at mobile width, with no horizontal overflow.

## Reproduction on a **disposable** checkout

Install PHP 8.4 dependencies from `services/business-api/composer.lock` and pinned Node dependencies in the throwaway checkout, then start only a loopback Laravel testing process:

```sh
export APP_ENV=testing APP_DEBUG=false SESSION_DRIVER=array CACHE_STORE=array
export DB_CONNECTION=sqlite DB_DATABASE=:memory:
export APP_KEY="base64:$(openssl rand -base64 32)"
php artisan serve --host=127.0.0.1 --port=18764 --no-reload
```

In another shell at the repository root (with the project's Playwright Chromium dependency installed):

```sh
node tests/agent9-security-http.cjs http://127.0.0.1:18764
node tests/agent9-payment-return-browser.cjs http://127.0.0.1:18764
node --test tests/agent9-qa-target-guard.test.cjs
```

The QA programs reject other hosts, non-HTTP, privileged ports, URL credentials, non-root URL paths, fragments and query parameters *before* network access. They are operator invoked and must not silently target a deployed service. Shut down the isolated Artisan process after execution.

## Full Laravel Feature-suite note — not green on unmodified source

With pristine repo source, the Feature suite had **553 tests, 5,505 assertions, two errors + two failures**: three SQLite geodistance cases because the host lacks `sqrt/sin/cos/asin` SQL functions and one historic settlement precision migration case because SQLite had already removed an old trigger during a schema rebuild.

To isolate the underlying causes, Agent 9 temporarily registered the four SQLite scalar functions **in the local CatalogGeoTest setup only**, and used `DROP TRIGGER IF EXISTS` **in the local SQLite-only migration copy**. On checkout `3117e28683e48bb8a3e883e0a6bdfcbbb9c42408` with those two **uncommitted diagnostic changes**, full Feature suite passed **553 tests / 5,527 assertions**. The modifications were then restored, and the checkout was verified clean.

**Do not count that temporary workaround run as a passing exact-source PR/CI.** Agent 8 owns the catalog SQLite QA fixture and Agent 1 owns the finance migration path. These source fixes require independent ownership review and commits before full-suite pass may be claimed. The unmodified PR #64 remains DRAFT; Agent 4 owns integration and merge gates.

## Lockfile security audit and runtime compatibility

- Ran **read-only** `composer audit --locked --format=json` using Composer 2.11.1 and PHP 8.4.24 in the disposable checkout: exit 0, **0 advisory packages** reported. This is a point-in-time advisory lookup, not a claim of code security or complete supply-chain review.
- Ran **read-only** `npm audit --json` against the checked-in Node dependency tree: exit 0 and **0 vulnerabilities** (including 0 critical/high/moderate/low/info) reported at the time of execution.
- **Deployment compatibility caution:** the checked-in PHP manifest declares `php: ^8.2`, but the locked `symfony/clock`, `symfony/css-selector`, `symfony/event-dispatcher`, `symfony/string`, `symfony/translation`, `symfony/yaml` and other resolved packages require PHP >=8.4.1. Composer install with the host's default PHP 8.2.33 fails on the exact lockfile. The separate PHP 8.4.24 binary succeeded. Agent 4 and canonical backend owner must choose and verify a supported deployment/runtime version before release; **no change to runtime defaults, dependencies or production hosting was made**.
