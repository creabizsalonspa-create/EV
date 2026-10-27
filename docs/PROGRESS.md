# Full platform delivery register

Updated 2026-10-27. Full scope remains mandatory. No production release or final acceptance requested.

## Evidence checkpoint

Requirements committed as `c8c2dd5`; implementation commit is the Git commit containing this document. Exact revisions are available from Git history. Current local environment: Linux/Docker, pinned PHP 8.5.12/Composer 2.10.3, Laravel 12.70.1, SQLite, Node 24.19, system Chromium driven by Playwright 1.62.1.

- `./scripts/setup`: passed, including repeat installation; new migrations applied incrementally.
- `./scripts/php artisan test --compact`: 31 tests, 226 assertions passed at the expanded checkpoint.
- `./scripts/php /usr/bin/composer validate --strict`: passed.
- `EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e`: passed registration, vendor inventory, customer reservation/cancel, vendor visibility, tenant privacy, admin overview, private garage, fleet invitation acceptance, support internal-note privacy, RTL and 390px width; zero browser page errors.
- `npm run test:ocpp`: both protocols passed boot/heartbeat/status, unauthenticated-command rejection, remote-start transport, and Laravel-outbox command transport against running CitrineOS. SIMULATED only.
- HTTP health and DB-backed station catalog: passed.
- Git push: passed. GitHub API/PR operations blocked by `Forbidden`.
- GitHub Actions workflow written; hosted run not verified.

## Dependency-ordered roadmap

| Module | Status | Current evidence / next work |
|---|---|---|
| Repo, dependency lock, migrations, local startup | INTEGRATED_TESTED | scripts and migrations; production deploy/rollback drill outstanding |
| Identity and access | IN_PROGRESS | sessions, CSRF, roles tested; profile editing, reset broker, session revocation and security headers tested; verification, 2FA, granular staff permissions and native auth remain |
| Regions/currencies/taxes/timezones | IN_PROGRESS | admin country/currency/minor-unit/timezone/tax configuration tested; commerce disabled, legal/tax activation remains |
| Vehicles/profiles/privacy | IN_PROGRESS | private vehicle API and garage/profile UI tested; complete garage details and privacy/deletion workflows remain |
| Vendor onboarding/KYC/staff | NOT_STARTED | role provisioning is local operator-only; not KYC |
| Station/charger/connector inventory | IN_PROGRESS | single test connector per station; full hierarchy/configuration remains |
| Discovery, maps/navigation/favorites/reviews | IN_PROGRESS | filterable catalog, truthful provenance; maps/data adapters and engagement remain |
| Reservations | SIMULATED_TESTED | fixed-slot uniqueness/idempotency/privacy/cancel tested; occupancy/no-show/expiry policies remain |
| Charging sessions | SIMULATED_TESTED | application simulator only, start/meter/stop/scoped visibility tested; separate OCPP transport tests now pass; app sessions are not yet linked to OCPP transaction events |
| CitrineOS/OCPP | IN_PROGRESS | digest-pinned v2.1.0 stack, protocol simulator tests and restricted Laravel outbox tested; physical controls/event reconciliation remain |
| OCPI CPO/eMSP/partner adapters | IN_PROGRESS | CPO-oriented upstream role assessment documented; outbound eMSP adapter, conformance and authorized partner remain |
| Payments/refunds/disputes | NOT_STARTED | no merchant integration or payment captured |
| Invoices/ledger/reconciliation/settlements | NOT_STARTED | simulator estimate is not an invoice |
| Wallet | NOT_STARTED | legal/regulatory approval required before activation |
| Tariffs/commissions/subscriptions | NOT_STARTED | simulator fixed rate only |
| Loyalty/rewards/promotions/referrals/ads | NOT_STARTED | implement after authoritative billing |
| Fleet/enterprise/cost centers | IN_PROGRESS | invitation/accept/revoke, tenant privacy, vehicle assignment and cost centers tested; consolidated billing/reports remain |
| Notifications/support/maintenance | IN_PROGRESS | ticket state machine, internal-note privacy, notifications/read and support UI tested; delivery providers/maintenance remain |
| Customer web | IN_PROGRESS | account/catalog/reservation/simulation/garage/fleet/support UI, partial Arabic |
| Vendor web | IN_PROGRESS | basic inventory/reservation/simulation UI |
| Admin web | IN_PROGRESS | counts/audit, regional configuration and support workflow; full administration remains |
| Customer/vendor Flutter Android+iOS | BLOCKED_EXTERNAL | SDK archive domain denied; full Flutter image unpack ran out of storage; no source/build artifacts yet |
| CI/CD/security/performance/observability/backups | IN_PROGRESS | expanded CI definition, CSP and scoped limits; hosted CI and independent security/operational QA remain |
| Physical charger acceptance | BLOCKED_EXTERNAL | approved site/device/firmware and safety authorization needed; not hardware tested |
| Actual roaming acceptance | BLOCKED_EXTERNAL | bilateral operator access and credentials required |
| Production payment/store/legal activation | BLOCKED_EXTERNAL | merchant, signing/store and regional approvals required |

## Next implementation stages

1. Complete multi-connector inventory, verification/2FA/native authentication, and vendor organization/staff/KYC; extend regional validation and fleet reporting.
2. Extend the deployed/tested CitrineOS command path into authenticated transaction/meter event ingestion, command acknowledgment correlation, full simulated session/billing reconciliation and stop/fault/reconnect tests.
3. Add tariff snapshots, immutable journal/invoices and test PSP integration; then subscriptions, settlements, consolidated fleet billing and loyalty.
4. Complete all web surfaces and shared Flutter applications, localization/accessibility and automated builds.
5. Complete OCPI roles and contract tests, operational deployment/restore/performance/security QA; real hardware/partner/PSP gates require supplied external access.

No feature has PRODUCTION_READY or HARDWARE_TESTED status. A foundation checkpoint is not full platform delivery.

## Current external and resource blockers

- GitHub API: `Get "https://api.github.com/repos/creabizsalonspa-create/EV": Forbidden`; Git pushes work. API egress/access is needed for PR creation/hosted-run inspection. No blind token reconnection requested.
- Flutter direct SDK metadata request to storage.googleapis.com failed with `CONNECT tunnel failed, response 403`. The full ghcr.io/cirruslabs/flutter:stable image downloaded but failed unpacking with `no space left on device`; it was not used. A slimmer official SDK path is the next supported route once archive egress is available. Disk has ~13 GiB free after the failed pull; do not repeat the full image pull unchanged.
- Draft saved: installation/startup scripts and additive custom domains api.github.com + storage.googleapis.com + packagist.org; preset domains preserved. Saved is not applied or published. Owner action: review/save those environment settings and publish the prepared environment when desired. No secret values were requested.
- Linux cannot produce a signed iOS artifact; a macOS/Xcode runner and signing access are later external requirements. No mobile build has passed.

Next coding work remains substantial and is not all blocked by SDK access: multi-connector hierarchy, email verification/native auth/staff/KYC, full CSMS event/session reconciliation, financial ledger/tariffs/PSP, outbound OCPI, loyalty/promotions, complete client applications and production operations. These are unfinished implementation stages, not validated integrations.

Composer advisory audit is additionally blocked by packagist.org egress (exit 100); npm audit completed with zero reported advisories. See docs/QA_REPORT.md for exact coverage, fixes and unrun release gates.
