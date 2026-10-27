# Full platform delivery register

Updated 2026-10-27. Full scope remains mandatory. No production release or final acceptance requested.

## Evidence checkpoint

Requirements committed as `c8c2dd5`; implementation commit is the Git commit containing this document. Exact revisions are available from Git history. Current local environment: Linux/Docker, pinned PHP 8.5.12/Composer 2.10.3, Laravel 12.70.1, SQLite, Node 24.19, system Chromium driven by Playwright 1.62.1.

- `./scripts/setup`: passed, including repeat installation with nothing to migrate.
- `./scripts/php artisan test --compact`: 14 tests, 59 assertions passed at foundation checkpoint.
- `./scripts/php /usr/bin/composer validate --strict`: passed.
- `EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e`: passed registration, vendor inventory, customer reservation/cancel, vendor visibility, tenant privacy, admin overview, RTL and 390px width; zero browser page errors.
- HTTP health and DB-backed station catalog: passed.
- Git push: passed. GitHub API/PR operations blocked by `Forbidden`.
- GitHub Actions workflow written; hosted run not verified.

## Dependency-ordered roadmap

| Module | Status | Current evidence / next work |
|---|---|---|
| Repo, dependency lock, migrations, local startup | INTEGRATED_TESTED | scripts and migrations; production deploy/rollback drill outstanding |
| Identity and access | IN_PROGRESS | sessions, CSRF, roles tested; verification, recovery, 2FA, granular staff permissions and native auth remain |
| Regions/currencies/taxes/timezones | IN_PROGRESS | station metadata only; managed config and tax rules remain |
| Vehicles/profiles/privacy | IN_PROGRESS | private vehicle API; complete garage, profile editing and deletion workflows remain |
| Vendor onboarding/KYC/staff | NOT_STARTED | role provisioning is local operator-only; not KYC |
| Station/charger/connector inventory | IN_PROGRESS | single test connector per station; full hierarchy/configuration remains |
| Discovery, maps/navigation/favorites/reviews | IN_PROGRESS | filterable catalog, truthful provenance; maps/data adapters and engagement remain |
| Reservations | SIMULATED_TESTED | fixed-slot uniqueness/idempotency/privacy/cancel tested; occupancy/no-show/expiry policies remain |
| Charging sessions | SIMULATED_TESTED | application simulator only, start/meter/stop/scoped visibility tested; no OCPP transport |
| CitrineOS/OCPP | IN_PROGRESS | source/license assessment only; release pin, isolated deployment, adapter and protocol tests next |
| OCPI CPO/eMSP/partner adapters | NOT_STARTED | no conformance implementation or authorized partner |
| Payments/refunds/disputes | NOT_STARTED | no merchant integration or payment captured |
| Invoices/ledger/reconciliation/settlements | NOT_STARTED | simulator estimate is not an invoice |
| Wallet | NOT_STARTED | legal/regulatory approval required before activation |
| Tariffs/commissions/subscriptions | NOT_STARTED | simulator fixed rate only |
| Loyalty/rewards/promotions/referrals/ads | NOT_STARTED | implement after authoritative billing |
| Fleet/enterprise/cost centers | NOT_STARTED | depends on identities and finance |
| Notifications/support/maintenance | NOT_STARTED | no delivery provider configured |
| Customer web | IN_PROGRESS | basic account/catalog/reservation/simulation UI, partial Arabic |
| Vendor web | IN_PROGRESS | basic inventory/reservation/simulation UI |
| Admin web | IN_PROGRESS | counts/audit view only; full administration remains |
| Customer/vendor Flutter Android+iOS | NOT_STARTED | no source or build artifacts yet |
| CI/CD/security/performance/observability/backups | IN_PROGRESS | CI definition only; independent security and operational QA remain |
| Physical charger acceptance | BLOCKED_EXTERNAL | approved site/device/firmware and safety authorization needed; not hardware tested |
| Actual roaming acceptance | BLOCKED_EXTERNAL | bilateral operator access and credentials required |
| Production payment/store/legal activation | BLOCKED_EXTERNAL | merchant, signing/store and regional approvals required |

## Next implementation stages

1. Strengthen multi-connector inventory, international configuration, organization/staff authorization, authentication recovery and verification.
2. Deploy pinned CitrineOS separately, validate both protocol paths using simulators, then authenticated event/command integration with durable retry states.
3. Add tariff snapshots, immutable journal/invoices and test PSP integration; only then subscriptions, settlement, fleet and loyalty.
4. Complete all web surfaces and shared Flutter applications, localization/accessibility and automated builds.
5. Complete OCPI roles and contract tests, operational deployment/restore/performance/security QA; real hardware/partner/PSP gates require supplied external access.

No feature has PRODUCTION_READY or HARDWARE_TESTED status. A foundation checkpoint is not full platform delivery.
