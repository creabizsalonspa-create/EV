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

### Persistent local charging demonstration

After the existing local application, CitrineOS, charging bridge, rider authorization
and remote-session authorization setup, run:

```sh
./scripts/charging-workers start
./scripts/client-demo prepare
./scripts/client-demo start
./scripts/client-demo status
```

`prepare` creates separate synthetic customer, vendor and Admin accounts and two
owned simulator stations (OCPP 1.6 and 2.0.1). It prints the path of an owner-only
`artifacts/client-demo/<run>/access.json` file. Read that file locally for sign-in;
never publish it, its passwords or the station control files. Normal preparation
does not create reservations. Use the existing customer web or configured mobile
app to discover the named **LOCAL SIMULATOR** stations and book a future half-hour.
Start during the confirmed window. Vendor/Admin charging dashboards show canonical
source operations, live meter readings, stop status and the resulting invoice.
Keep the application, CSMS and workers running while demonstrating.

For a card demonstration, register an RFID/IdToken in customer Rider access,
request station access, then approve and provision it from the owning vendor
company. During a current reservation, submit the private token through stdin:

```sh
./scripts/client-demo swipe 1.6 ISO14443 < /path/to/private-card-value
# or: ./scripts/client-demo swipe 2.0.1 Central < /path/to/private-card-value
```

The token is not a command-line argument. The single swipe expires after ten
seconds, requires an idle device, managed source provisioning and current online
Laravel authorization. Revoking a card does not fabricate a transaction stop.
Stop an active session through its existing authorized application control.

`./scripts/client-demo stop` disconnects only the owned device processes. It never
invents a source stop, issues a refund or deletes a pending command. Idle restarts
retain the meter. An interrupted/uncertain transaction is held for operator/source
reconciliation; do not delete its journal or replay its start. Devices reconnect
only while idle. Synthetic energy advances at 18 kW and passes through actual
CitrineOS source ingestion and the existing invoice ledger. This proves local
software integration, not physical charging, live roaming or real payments.

For focused automated lab proof only, `prepare --qa-current-slot` explicitly seeds
current-slot reservations; `node tests/client-demo.cjs` exercises remote charging,
and `node tests/client-demo.cjs --rfid` exercises managed cards on a fresh fixture.
This does not prove customer booking creation. CI runs the owned lifecycle wrapper
after the existing charging regressions and retains only sanitized test output;
private demo access files are excluded from uploaded artifacts.

### Managed OCPI session/CDR reception

For an explicitly approved CPO connection and local EMSP identity,
`OCPI_CONNECTIONS_JSON` can opt in with `"incoming_modules":["sessions","cdrs"]`.
The default is disabled. This permission is part of the credential scope:
changing it invalidates existing credentials and requires a new approved exchange.
After active credential exchange and OCPI 2.2.1 sender-module negotiation, local
version details advertise the corresponding receiver endpoints. Session GET/PUT/
PATCH and CDR POST/GET require the active opaque OCPI token; browser sessions,
bootstrap/provisional tokens and other partner identities cannot write records.

Accepted writes retain an authenticated generation/content receipt. Exact retries
are safe; conflicting immutable CDRs, stale updates and completed-session rollback
are rejected. Partial sessions preserve other source fields and require a new
source timestamp. Vendor/Admin web and Flutter record inspection compares CDRs
against explicitly linked, completed sessions and reports discrepancies. Records
with missing references, duplicate claims, credits, unsupported precision or
unlinked legacy history require review. Even matching records remain partner
reported and do not create local charging sessions, invoices or payment authority.

Customers can save published partner locations from web or Flutter roaming
discovery and browse their own saved list. Preferences are private and separate
from the public catalog. A revoked/unpublished location is redacted in the saved
list and can still be removed. Lost write acknowledgements require an explicit
preference refresh. Saving does not change partner availability, booking, charging
or payment permissions.
