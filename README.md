# Crea-Biz EV platform

Commercial platform under development. The full scope is in [PRODUCT_REQUIREMENTS.md](PRODUCT_REQUIREMENTS.md); actual delivery status is in [docs/PROGRESS.md](docs/PROGRESS.md).

## Development

Prerequisites: Linux Docker daemon, Bash, curl; Node 24 for browser tests. No host PHP installation is needed. From this checkout:

```sh
./scripts/setup
./scripts/start
./scripts/php artisan test --compact
npm ci --cache /tmp/ev-npm-cache
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e
```

If system Chromium is unavailable, install Playwright's browser with `npx playwright install --with-deps chromium`, then omit `EV_CHROMIUM_PATH`. Browser tests create synthetic accounts and simulator inventory; run against a disposable development database, never production.

The app uses port 8000 locally. Register customer accounts through the web interface. For operator-controlled local testing, promote an existing account using `./scripts/php artisan platform:role account@example.test vendor` (or `admin`). Never expose this shell access to end users. There are no default passwords or seeded live stations.

Customer, vendor and basic admin views share one Laravel web app. Each station currently represents **one test connector**. Reservations occupy fixed 30-minute UTC slots. Simulated sessions use a fixed test rate of 100 currency minor units/kWh; totals are estimates, not invoices or processed payments. The development simulator is not an OCPP simulator.

## Verified boundaries

Implemented: session authentication/CSRF, signed email-ownership verification with explicit send/status controls (local log transport), Light/Dark/System web themes, customer registration, restricted roles, station filters/provenance, vehicle APIs, fixed-slot reservations/cancellation, tenant-scoped views, simulated session commands/meters/totals, audit events, basic admin counts/audit view, regional configuration, fleet invitations/cost centers, support tickets/internal-note privacy, in-app notifications, profile/recovery, simulator-only CSMS outbox, continuous signed source-event/invoice reconciliation, shared native clients and English/Arabic web workflows including company review/staff/inventory. Current revision-scoped evidence: docs/PROGRESS.md and docs/QA_REPORT.md.

Not delivered: full production security/2FA/KYC, multi-connector inventory, full CitrineOS event/session integration, OCPI roaming, maps, live availability, real payments/legal invoicing, complete commercial/native workflows, signed store artifacts, commercial modules and production deployment. See the complete module register and next milestones before planning a release.

The isolated CitrineOS development stack and tested OCPP subset are documented in [services/csms/README.md](services/csms/README.md). Full internal checkpoint evidence and release blockers are in [docs/QA_REPORT.md](docs/QA_REPORT.md).

See [API contract](docs/API.md), [decisions](docs/DECISIONS.md), and [operations](docs/OPERATIONS.md).
