# Active parallel ownership — owner restored, 28 October 2026

Effective immediately the two-agent model is restored. Agent1 exclusively owns finance/commercial rules, financial controllers/services/migrations and financial web/Admin screens. Agent2 owns EV/OCPP/OCPI, discovery/maps/GPS/routing/favorites/reviews, customer/vendor Flutter, nonfinancial web/Admin, fleet operations, security/deployment and the main integration branch. Agent2 consumes verified finance APIs in Flutter and integrates tested finance PRs without recreating their business logic. This supersedes temporary sole-owner statements below; preserve historical records and all merged financial invariants.

Fetched current main is e6f20f0, beyond the owner's cited PR16 checkpoint: PR17 staging-runtime fixes are also verified/merged, with sourcecbed320 and merge6a9b404 evidence. Do not rebuild PR16 favorites/maps or PR17. No new finance PR was visible at this ownership fetch. Agent2 continues one discovery-routing vertical batch independently; see ACTIVE_FILE_OWNERSHIP.md for additive shared-file reservations.

# Verified integrated checkpoint — 28 October 2026

PR14/15/16/17 are merged. Latest verified integration is **6a9b4046914111c760625bac016f9b7d26e2a6a5**, exactly matching tested **cbed3209abe71577e2f401e71a41f50c98f8a5bc**. All eight PR17 push/PR checks passed: full platform/browser/OCPP/OCPI, finance and connector PostgreSQL, and both complete staging-image builds/audits/PostgreSQL17 HTTP/storage smokes. Evidence: [staging-image/hosted-checkpoint.json](evidence/staging-image/hosted-checkpoint.json). Application source is unchanged from **e942017**, whose ten Android/iOS/platform checks and eight connected Laravel suites passed in PR16; those native runs are retained as application evidence, not misreported as new PR17 runs.

The customer-discovery batch adds private canonical favorites and consent-gated web/Flutter current-page maps, clustering, manual nearby centres, opt-in one-shot web GPS, safe navigation and existing charging workflows. The deployment batch fixes omitted public map assets, excluded local databases/environment/test files, built-in PHP8.5 OPcache, authoritative application/provider autoloading, worker-readable root-owned source and fresh private-volume ownership. CI positively tests exclusion canaries and actual Apache/Laravel responses after all PostgreSQL17 migrations; no production deployment occurred. The first image HTTP-readiness failure and local32GiB VFS build-space failure are retained honestly in STAGING_IMAGE_VALIDATION.md. Only this task's identified cache records were cleaned; original images, containers, keys, databases and volumes remain preserved.

**Coverage remains unchanged:** original42 categories/312 atoms, coded50.70%, tested50.51%, merged50.51%, production0.00%. Partial canonical maps/favorites receive no broad-atom credit while partner scope remains incomplete. The whole platform is unfinished. Next implementation remains unified authorized partner discovery/favorites/maps, native one-shot GPS, approved address/routing integration, reviews/moderation and the full remaining original customer/vendor/Admin/finance/OCPP/OCPI/security/release requirements. External hardware, live PSP/partner, real-device GPS, operational restore/deployment and signing/store acceptance remain unverified. Historical records below retain their original checkpoint status; later record-only commits may follow this verified source.

# Verified customer-discovery integration — 28 October 2026

PR16 is merged at **1bee9c94d1a14290c38364361bf1fdc20312acb0**, with the exact application tree tested at **e9420176fe82494a2cf43f5bb9809acb90291c54**. All ten current push/PR checks passed: full platform/browser/OCPP/OCPI, finance and connector PostgreSQL, both Android builds/connected Laravel suites, and both iOS simulator/UI runs. Actual runtime logs confirm favorites, map privacy navigation, Keychain, Arabic pricing and private PDF rendering. One iOS run used the existing bounded pre-Dart-only startup recovery; no connected assertion was retried. Evidence: [customer-discovery/hosted-checkpoint.json](evidence/customer-discovery/hosted-checkpoint.json).

Implemented: private canonical saved stations across Laravel/web/Flutter, scoped pagination and idempotent desired-state writes, public/private response separation, lost-ack refresh without replay, current-page web/Flutter maps, clustering, source/capability preservation, explicit tile/navigation consent, manual nearby centre selection, one-shot opt-in web GPS, EN/AR/themes and customer-only charging actions. Laravel263/7644; default Flutter116 passes/nine deliberate live-only skips, clean analysis; all eight connected native suites and affected browser/protocol/concurrency checks passed in hosted CI. No physical-device, live hardware/PSP/OCPI, signing/store or production acceptance is claimed.

The original42 categories/312 atoms and weights are unchanged: coded50.70%, tested50.51%, merged50.51%, production0.00%. Canonical maps/favorites are documented partial scope; authorized partner maps/favorites, native GPS, autocomplete, routes/stops, reviews/moderation, saved places and the full remaining platform remain unfinished. The staging image follow-up is in progress: exclude local databases, retain public map assets, fix PHP8.5 OPcache/build trust and verify runtime storage. It is not yet tested/merged; local full image build reached COPY but exhausted the32GiB VFS workspace. Only this task's identified failed-build cache records were cleaned; existing images, databases and volumes remain intact. Exact next action: finish isolated hosted staging-image build/runtime validation, then continue unified discovery and the remaining full requirements. Historical records below retain their original status at the time.

# Customer discovery batch — candidate update, 28 October 2026

The single `development/customer-discovery` branch now combines private canonical favorites across Laravel/web/Flutter with interactive web and Flutter maps. See [CUSTOMER_FAVORITES.md](CUSTOMER_FAVORITES.md) and [CUSTOMER_DISCOVERY_MAPS.md](CUSTOMER_DISCOVERY_MAPS.md) for exact behavior, privacy, tests and missing scope. The e7fc2e1 favorites push passed Android and iOS (run40638155431); its browser failure was a missing synthetic favorites/status response in web-session.cjs, corrected without weakening assertions. Maps/new native assertions await exact-source hosted verification. This candidate is not merged or production accepted.

PR14 and15 remain verified/merged; no original branch was overwritten. The original42-category/312-item inventory, denominator and weights remain unchanged: coded50.70%, tested50.51%, merged50.51%, production0.00%. Unfinished partner favorites/maps, native GPS, autocomplete, routes, reviews, saved places and the other original requirements receive no invented credit. Continue the connected discovery batch and verification from this branch; preserve finance and EV integrations.

# Canonical customer favorites candidate — 28 October 2026

PR14/15 integration backlog is closed and its evidence is pushed on main822532a (runtime sourcebb75151, merge8573081). Current single branch development/customer-discovery implements backend/web/Flutter personal saved stations with protected preference lookups, capability preservation, idempotent writes, session fences, paginated/EN/AR/theme flows and real-Laravel tests. See CUSTOMER_FAVORITES.md. This is a new unmerged candidate; Android/iOS/extended device assertions await exact-source hosted verification.

Local: Laravel263/7644; PostgreSQL54/814 plus14/197 connector races; preserved finance146/6381 plus41/539 races; Flutter111 passes/nine deliberate live-only skips and clean analysis; seven new favorite tests, actual native topology/favorite smoke and real browser/CSRF/privacy/lost-ack/pagination/RTL/theme checks pass. Public catalog data contains no private preference metadata. Partner-location favorites, actual interactive maps/routes/reviews and the full original remaining commercial/EV/Admin/security/release requirements remain unfinished. No production or external acceptance is inferred.

The latest owner directive preserves the existing42-category/312-subfeature weighting unchanged. The canonical favorites subset earns no whole-subfeature credit while partner favorites remain unfinished. Continue this single development/customer-discovery branch with the larger map/discovery/customer batch; do not create overlapping unfinished PRs.

# Verified integration backlog closed — 28 October 2026

PR14 verified source **ae8a10f3bb3758efb072bcea73fe0688197e6e53** passed all10 checks and merged at6d995d2. PR15 safely merged that base, reran all affected checks at **bb75151b1572e073f63f8b664336983a94198b2c**, passed all8 checks (two full platform/PostgreSQL matrices and Android/iOS native validation), and merged at **8573081d3712b1fd2249a9da9030d96f3aa82351**. Both merge trees exactly equal their tested source trees. Actual iOS runtime PDF-render/Keychain/Arabic markers and artifact/log digests are retained in `docs/evidence/native-vendor/hosted-checkpoint.json` and `docs/evidence/catalog-nearby/hosted-checkpoint.json`.

The full42-category/312-atom weighted tracker now credits both verified merges. Reproduce coded/tested/merged/production-accepted coverage with `python3 scripts/requirement-coverage --check`; no commit/test-count percentage or production acceptance is inferred. Agent2 temporarily owns the entire original product. The temporary branch freeze has been fulfilled; resume remaining customer/EV/commercial/native/Admin software from this verified base, preserving canonical services. Interactive maps/navigation/favorites/reviews, full production OCPP/OCPI, remaining native commercial/vendor/Admin/security/deployment/release work are still unfinished. Physical chargers, live PSP/roaming partners, signing/stores and real operational acceptance remain external gates, not excuses for independent missing software.

Earlier candidate/failed/pending records below are historical and superseded by this exact verified checkpoint.

# Verified PR14 integration and pending PR15 rerun — 28 October 2026

PR14 source **ae8a10f3bb3758efb072bcea73fe0688197e6e53** passed all ten hosted checks. Both iOS runs include the actual native PDF raster/preview, Keychain and Arabic-pricing runtime assertions. Merged main is **6d995d2806a688f8f92c094f671b2ebd1d3567ad**; its Git tree exactly equals the verified source. Evidence: `docs/evidence/native-vendor/hosted-checkpoint.json`. The prior callback-event defect is fixed without removing assertions or retrying connected failures.

Existing PR15 branch development/catalog-nearby now safely merges this verified main; no new feature branch was created. Fresh local integration rerun passes Laravel258/7563, finance PostgreSQL146/6381 plus41/539 process races, connector PostgreSQL49/733 plus14/197 races, Flutter104 with9 deliberate live-only skips and clean analysis, nearby/frontend/localization browser suites, and5 coverage-evidence guards. Complete the exact-source hosted protocol/browser/Android/iOS rerun before PR15 merge. The312-atom/42-category weighted tracker separates coded/tested/merged/production acceptance and is checked by scripts/requirement-coverage. Agent2 temporarily owns the entire product under the latest owner directive. Full production OCPP/OCPI/maps/commercial/native/Admin/security/release scope remains unfinished; do not rebuild completed systems or infer physical/merchant/partner/store acceptance.

## Latest owner directive — temporary full-project Agent2 ownership, 28 October 2026

The newly uploaded FULL PROJECT OWNERSHIP command supersedes the preceding parallel split: Agent1 is unavailable; Agent2 temporarily owns all remaining financial/commercial and EV/non-financial software. Reuse canonical finance services and preserve verified work. Coordinate before simultaneous shared-file edits if Agent1 returns; ownership changes only on an owner directive. Earlier split/sole-owner records remain historical.

Verified integrated main is6388642827781d4572a8fb91a83f392b26d461c4. PR12 recurring mandates/collections and PR13 charger admission lifecycle are merged. PR14 source1b72354 passed backend/PostgreSQL/Android but failed a premature native PDF-ready assertion after successful company/upload flow. Corrected source5b258f1 awaits actual native load with a bounded30-second/fail-fast error check; exact-source iOS rerun is required before merge. PR15 source2909566 adds cross-platform nearby search; its local evidence is in docs/evidence/catalog-nearby/local-checkpoint.json and hosted checks are pending. Do not rebuild these modules, equate coordinate search with an interactive map, or claim physical/merchant/partner/store acceptance.

# Agent2 nearby-catalog continuation — 28 October 2026 (Dubai)

Verified integrated main remains63886428 (PR12/13). Native-vendor PR14 source1b72354 includes the real iOS keyboard-interaction correction; hosted validation must finish before merge. New independent EV branch development/catalog-nearby extends canonical catalog radius search across Laravel/web/Flutter without modifying finance rules. See CATALOG_NEARBY.md and current QA_REPORT. Preserve parallel ownership: Agent1 exclusively finance/commercial/backend/web/Admin; Agent2 EV/native/nonfinancial/security/main integration. After these exact-source checks, merge through PRs, update checkpoint evidence, then implement interactive map/provider/location-consent workflows and remaining EV/OCPI modules; do not label coordinate search an interactive map.

# CREA-BIZ EV — MASTER CONTINUATION COMMAND FOR AGENT 2

## Latest owner directive — restored parallel ownership, 28 October 2026 (Dubai)

Agent1 exclusively owns financial/commercial business rules, subscriptions, payouts/settlements, refunds/disputes, enterprise billing, loyalty economics, advertising billing and their backend/web/Admin interfaces. Agent2 owns OCPP/OCPI, EV infrastructure, maps, reservations, customer/vendor Flutter, non-financial Admin, security/deployment integration and the main integration branch. Consume verified finance APIs in Flutter; do not recreate financial logic. Both accounts work on separate branches and integrate tested PRs without force-pushing. Earlier sole-owner instructions below are historical and superseded by this owner update.

Active Agent2 source: `development/native-vendor-operations` from verified main63886428. No financial controller/service/migration/web/Admin rule is modified by this slice. Shared paths to coordinate before editing: `apps/mobile/lib/api.dart`, `apps/mobile/lib/main.dart`, mobile dependencies/CI, `scripts/start`, `infra/staging/Dockerfile`, shared progress/API documentation. Non-financial VendorOps controllers/routes/models and tests are Agent2's current work. No new open finance PR was visible on the ownership-update fetch; continue independent EV work while monitoring for verified finance delivery. PR12/13 are already integrated; preserve their history.



Current recovery record: [ACTIVE_DEVELOPMENT_CHECKPOINT.md](ACTIVE_DEVELOPMENT_CHECKPOINT.md). PR12 saved-mandate/automatic-collection source dfab1ae is fully hosted-tested and integrated at f0080373. Credential-expiry/audit continuation follows on a separate review branch. Agent2 remains sole full-platform owner; older account restrictions below are historical. Do not rebuild completed topology, reservation, finance, consent or recovery modules. No hardware/merchant/partner/store acceptance is implied.

Latest implementation `eae79cb41677056890d33625d0c2700bcf94bcf4` includes canonical source-namespace ownership fences and production bound-identity requirements, plus guarded pre-Dart-only iOS startup recovery. Local Laravel231/7233 and PostgreSQL connector42/620 +12 process races/166 passed. TLS/mTLS, connected charger/topology browsers and all six connected native fixture suites passed; separate generic native API smoke also passed. Flutter80/default7 live-only skips and three Python startup safety tests are verified. Financial126/6164 +31 earlier process races/403 remain independently recorded; the current hosted financial runner also covers the newly added namespace race. See PR #10 current checks for the final hosted result; no skipped, cancelled or pre-Dart-failed run is counted as passing.


## Historical sole-owner directive — superseded by restored parallel ownership

Agent 2 is the sole active owner of all remaining Crea-Biz EV development, including financial and non-financial software, until the project owner explicitly changes the assignment. This supersedes previous exclusive finance ownership; the records below remain historical. Continue the canonical financial architecture, preserve both agents’ verified work and merge tested changes through pull requests. Full original scope remains required; simulator/contract tests do not establish hardware, merchant, roaming-partner or store acceptance.


## Historical restored parallel ownership — superseded by the final directive above

The owner restores two active workstreams. Agent1 exclusively owns financial/commercial business logic, controllers, accounting services, financial migrations and financial web/Admin workflows. Agent2 owns EV/OCPP/OCPI, all customer/vendor web and Flutter integration, non-financial Admin, operations/security, engagement and fleets, consuming verified finance APIs without modifying their source of truth. Agent2 owns development/fullstack-continuation and integration QA. Agent1 works on a separate finance branch; inspect its new PR and coordinate shared-file edits before integration. Merge only tested changes through PRs; preserve both branches and never force-push/reset another account's work. Earlier sole-owner instructions below are historical and superseded by this explicit reassignment. Shared EV route/client edits in this session are additive; finance routes/controllers/services/migrations are excluded. No new finance PR was visible on the initial fetch; do not wait for it to continue EV work.

Continue from6d0e33c without rebuilding canonical mapping, parallel reservations, arrival/expiry or applied-source command reconciliation. Immediate new work: secure charger admission/provisioning and no-source-evidence operations. No hardware/live PSP/partner/store acceptance is inferred. Full original requirements remain in force.


## FULL PROJECT OWNERSHIP — COMPLETE ALL REMAINING SOFTWARE

**Project:** Crea-Biz EV Charging Aggregator & Management System

**Repository:** https://github.com/creabizsalonspa-create/EV

**Active development branch:** `development/fullstack-continuation`

**Latest verified starting checkpoint:** `ccd5e2e`

**Existing full-stack PR:** #3

**Already merged:** Finance PR #4 and Integration PR #5

## 1. NEW OWNER DIRECTIVE — IMPORTANT

Agent 1 has reached its usage limit and is temporarily unavailable.

Effective immediately, **Agent 2 is the sole active development agent responsible for completing the entire Crea-Biz EV platform.**

Your responsibility now includes:

- Complete Laravel backend and database.
- Complete customer web application.
- Complete customer Android and iOS applications.
- Complete charging-company/vendor web application.
- Complete charging-company/vendor Android and iOS applications.
- Complete Super Admin web application.
- Complete direct OCPP charger management.
- Complete third-party OCPI network integration.
- Complete payments, subscriptions, billing and settlements.
- Complete fleets, enterprise and corporate billing.
- Complete maps, navigation and reservations.
- Complete loyalty, advertising and promotions.
- Complete reporting, analytics and notifications.
- Complete security, privacy, localization and testing.
- Complete application build and deployment preparation.

The previous exclusive finance assignment to Agent 1 is temporarily superseded by this instruction.

**Do not wait for Agent 1 to return.**

Do not restart the project. Do not duplicate existing functionality. Continue from the current integrated codebase.

If Agent 1 becomes available again, continue owning all modules until the project owner explicitly reassigns responsibilities.

## 2. MANDATORY RULES

1. Deliver the **entire original product requirements**, not an MVP.
2. Preserve all existing verified code and database migrations.
3. Preserve financial accounting integrity and immutable journals.
4. Preserve working OCPP/OCPI simulations and security boundaries.
5. Do not replace existing architecture unnecessarily.
6. Do not overwrite, reset or force-push existing branches.
7. Do not change production security limits to make tests pass.
8. Do not fabricate hardware availability, payment success or OCPI authority.
9. Implement backend, frontend and mobile features together where required.
10. Continue autonomously through independently implementable tasks throughout your active coding session.
11. Do not stop merely to report progress after one small change.
12. Never claim software completion, production readiness or actual integration without evidence.

## 3. RESTORE AND VERIFY PROJECT STATE

First inspect:

- `AGENTS.md`
- `PRODUCT_REQUIREMENTS.md`
- `docs/REQUIREMENT_STATUS.md`
- `docs/PROGRESS.md`
- `docs/QA_REPORT.md`
- `docs/UNIVERSAL_CONTINUATION.md`
- `docs/NON_FINANCE_CONTINUATION.md`
- `docs/FINANCE_COMMERCIAL_CONTINUATION.md`
- `docs/FINANCE_MOBILE_CONTRACT.md`
- `docs/PAYMENT_GATEWAYS.md`
- `docs/SUBSCRIPTIONS.md`
- `docs/SETTLEMENTS.md`

Verify the latest remote branch, merged pull requests and CI status.

Inspect any existing uncommitted changes, especially `SessionBridge.php` and `mobile.yml`, before modifying or discarding them.

Update continuation documentation to reflect the new single-agent ownership without deleting historical records.

Preserve the current canonical Flutter application in `apps/mobile`.

Use the existing Laravel and CitrineOS architecture.

## 4. DEVELOPMENT PRIORITY 1 — COMPLETE CHARGING ARCHITECTURE

This is the first major unfinished technical requirement.

Implement complete canonical identity mapping:

- Charging company.
- Physical charging site.
- Charging station.
- Charge point.
- EVSE.
- Connector.
- OCPP charger identity.
- Protocol-specific connector identifiers.
- OCPI location and EVSE identities.
- Source network/provider.
- Company ownership and permissions.

Implement safe multi-connector operation.

A station with several connectors must support independent availability, reservations and charging sessions without accidental cross-connector conflicts.

Complete:

- Parallel connector reservations.
- PostgreSQL concurrency protection.
- Connector-specific occupancy.
- Booking expiry and no-show rules.
- Check-in workflows.
- Maintenance blocking.
- Per-connector source state and timestamps.
- Authorized remote starts and stops.
- Idempotent commands.
- Source event correlation.
- Fault and offline behavior.
- Safe recovery after service interruption.
- Correct final meter reconciliation.

Preserve the existing verified unsent-start expiry mechanism.

Do not manufacture session completion or invoices when charging never started.

Deliver corresponding customer/vendor/admin web and mobile functionality.

## 5. DEVELOPMENT PRIORITY 2 — PRODUCTION OCPP SOFTWARE

Complete the supported software implementation of OCPP 1.6 and OCPP 2.0.1 through the existing separately deployed CitrineOS CSMS.

Required functionality:

- Secure charger onboarding and provisioning.
- Charger identity and authentication.
- Approved charger credentials and certificates.
- Boot and registration.
- Heartbeats and connectivity.
- Connector status synchronization.
- Authorization and token lifecycle.
- Transaction start/stop.
- Remote start/stop commands.
- Meter values and energy reconciliation.
- Fault and incident handling.
- Supported reset commands with appropriate authorization.
- Charger diagnostics.
- Firmware/version capability handling.
- Command timeout and retry.
- Pending/uncertain transaction reconciliation.
- Event deduplication and replay protection.
- Durable queues and restart recovery.
- Operational alerts and monitoring.

Implement complete management surfaces and tests.

Use simulators and available protocol test harnesses when physical hardware is unavailable.

Document actual hardware/model compatibility testing as a separate external acceptance requirement.

## 6. DEVELOPMENT PRIORITY 3 — COMPLETE OCPI AND ROAMING

Complete OCPI 2.2.1 and supported authorized network adapters.

Implement:

- CPO and eMSP roles.
- Partner onboarding.
- Credential exchange.
- Version negotiation.
- Token exchange and rotation.
- Locations and EVSE synchronization.
- Tariff synchronization.
- Token authorization.
- Sessions.
- Charge Detail Records.
- Remote commands where authorized.
- Reservation capabilities where supported.
- Provider capability negotiation.
- Push/pull synchronization.
- Incremental updates.
- Provider-specific adapters.
- API rate-limit protection.
- Data freshness.
- Partner outage handling.
- Reconciliation and incident logging.

Build a unified catalog of managed and partner charging locations.

Clearly distinguish:

- Directly controlled locations.
- Authorized actionable partner locations.
- Discovery-only locations.

Never enable booking, payment or remote charging at a location without verified capability and authorization.

Add supported discovery-provider adapters only where API usage and licensing permit them.

Complete Admin and vendor roaming-management interfaces.

## 7. DEVELOPMENT PRIORITY 4 — CUSTOMER APPLICATIONS

Finish complete customer functionality on:

**Web, Android and iOS.**

Required modules:

### Identity and Profile

- Registration and login.
- Email/phone verification.
- Approved social login integration.
- Two-factor authentication.
- Secure account recovery.
- Profile settings.
- Privacy controls.
- Account deletion requests.

### EV Garage

- Add/edit/delete vehicles.
- Vehicle model and connector compatibility.
- Charging preferences.
- Battery details and authorized SOC input.
- Vehicle-specific discovery compatibility.

### Discovery and Maps

- Interactive charging-station maps.
- Current-location permission handling.
- Station search.
- Route planning and navigation.
- Distance filters.
- Connector/power filters.
- Price filters.
- Network/operator filters.
- Supported live availability.
- Favorites.
- Reviews and ratings.
- Station details, amenities, opening hours and accessibility.
- Source freshness indicators.

### Reservations and Charging

- Connector-specific availability.
- Reservation booking.
- Cancellation.
- Booking history.
- Check-in where supported.
- Remote start/stop where authorized.
- Charging monitoring.
- Energy and elapsed time.
- Pricing estimates.
- Charging notifications.
- Charging history.
- Faults and incident reporting.

### Payments and Loyalty

- Secure payment checkout.
- Invoices and receipts.
- Transaction history.
- Refund and dispute requests.
- Subscription packages.
- Loyalty Silver/Gold/Platinum tiers.
- Reward points.
- Coupon redemption.
- Referral programs.
- Promotions.

### Customer Experience

- Support tickets.
- Push notifications.
- English/Arabic.
- Proper RTL layouts.
- Dark/Light/System themes.
- Responsive layouts.
- Clear offline/error states.
- Accessibility.

All important customer journeys must work against the same authoritative Laravel backend.

## 8. DEVELOPMENT PRIORITY 5 — COMPLETE VENDOR APPLICATIONS

Finish the full charging-company/vendor experience on:

**Web, Android and iOS.**

Required modules:

- Company registration and KYC.
- Company profile management.
- Legal documents and approvals.
- Staff invitation and role management.
- Company locations.
- Charger and connector inventory.
- Charger provisioning workflows.
- Maintenance operations.
- Charger health and uptime.
- Session monitoring.
- Connector availability.
- Operational controls.
- Reservation calendars.
- Availability blocks.
- Tariff management.
- Dynamic prices where permitted.
- Taxes and fees.
- Customer session history.
- Invoice history.
- Refund handling.
- Financial reports.
- Earnings and commissions.
- Settlement requests and status.
- Subscription packages.
- Promotions and advertising.
- Loyalty participation.
- Analytics and exports.
- Support and incidents.
- Notifications.

Existing mobile inventory navigation and maintenance must be extended into full vendor functionality.

Do not stop at read-only mobile screens.

## 9. DEVELOPMENT PRIORITY 6 — COMPLETE FINANCIAL AND COMMERCIAL SYSTEM

Agent 1's existing financial code is already merged.

**Preserve and extend it; do not create a second finance system.**

Finish all remaining financial requirements.

### Payment Gateway

- Durable outbound payment intent recovery.
- Unknown payment result reconciliation.
- Safe retries and idempotency.
- Approved PSP adapter contracts.
- Hosted payment tokenization.
- 3DS/SCA workflows.
- Authorized captures and refunds.
- Signed webhooks and replay defense.
- Provider event reconciliation.
- External dispute handling and evidence.
- Failed-payment recovery.

### Subscriptions

- Complete plan management.
- Monthly/yearly billing.
- Automated renewals.
- Saved payment mandates where authorized.
- Off-session payment processing.
- Failure retry and dunning.
- Upgrade/downgrade rules.
- Cancellation and proration policies.
- Subscription credit notes.
- Package entitlements enforced in consuming modules.
- Subscription notifications.

### Vendor Finance

- Commission calculation.
- Vendor earnings.
- Settlement scheduling.
- Settlement approval.
- Payout-provider adapter.
- Refund/dispute settlement holds.
- Financial adjustments and audit trails.
- Reconciliation.
- Financial reports and exports.
- Multi-currency precision.

### Corporate Finance

- Corporate contracts.
- Fleet consolidated billing.
- Organization invoices.
- Cost-center allocation.
- Credit-note workflows.
- Configurable regional finance policies.

Maintain mock/sandbox modes until real merchant credentials and authorization are available.

Never store raw card details.

Do not activate real payouts or stored-value wallets without appropriate approvals.

Implement all financial API-consuming Flutter interfaces without duplicating backend accounting logic.

## 10. DEVELOPMENT PRIORITY 7 — FLEET AND ENTERPRISE

Complete:

- Enterprise organizations.
- Fleet owners and administrators.
- Corporate drivers.
- Vehicle assignment.
- Driver invitations.
- Organization permissions.
- Cost centers.
- Charging policies.
- Subscription eligibility.
- Fleet usage monitoring.
- Corporate charging reports.
- Consolidated billing.
- Agreements and billing contracts.
- Enterprise dashboards.

Support appropriate customer/vendor/admin surfaces.

## 11. DEVELOPMENT PRIORITY 8 — SUPER ADMIN WEB

Finish the complete Super Admin management system.

Required modules:

- Admin accounts and permissions.
- Required administrator 2FA.
- Customer management.
- Vendor/company management.
- KYC approvals.
- Moderation and privacy workflows.
- Charger inventory.
- Global station maps.
- OCPP management.
- OCPI partner management.
- Network health.
- Provider incidents.
- Reservations and session controls.
- Authorized command history.
- Payment gateway administration.
- Subscriptions and plans.
- Commissions and settlements.
- Refunds and disputes.
- Tax/currency configuration.
- Fleet and corporate accounts.
- Loyalty tiers and rewards.
- Coupons and referrals.
- Campaigns and in-app advertising.
- Vendor analytics.
- Platform revenue reporting.
- Operational dashboards.
- Support and notifications.
- Audit logs.
- Country configuration.
- Language and regional feature gates.
- System diagnostics and monitoring.

Implement full server-side authorization and tenant isolation.

Admin UI visibility alone is not an authorization control.

## 12. DEVELOPMENT PRIORITY 9 — LOYALTY, MARKETING AND ADVERTISING

This is still a major uncompleted product area.

Implement:

- Silver/Gold/Platinum loyalty tiers.
- Authoritative points ledger.
- Earn-and-redeem policies.
- Points expiration.
- Promotional coupons.
- Referral programs.
- Campaign management.
- Vendor-sponsored promotions.
- Advertisement placements.
- Campaign scheduling.
- Budget and eligibility controls.
- Redemption history.
- Anti-abuse and duplicate-reward protections.
- Vendor/admin campaign analytics.

Connect supported workflows across customer, vendor and Admin interfaces.

## 13. DEVELOPMENT PRIORITY 10 — ANALYTICS AND REPORTING

Build complete authorized analytics and reports for all relevant roles.

Include:

- Station utilization.
- Energy delivered.
- Revenue and commission.
- Charging sessions.
- Reservations.
- Availability.
- Uptime/downtime.
- Failed charging sessions.
- Provider errors.
- Charger faults.
- Customer activity.
- Vendor performance.
- Subscription revenue.
- Settlement status.
- Loyalty activity.
- Campaign performance.
- Fleet consumption.
- Organization costs.

Implement pagination, date filters, currency separation, exports and permission checks.

Do not present simulated operational data as real network performance.

## 14. DEVELOPMENT PRIORITY 11 — SECURITY, QUALITY AND INTERNATIONAL READINESS

Complete:

- Strong RBAC and tenant isolation.
- Secure authentication and 2FA.
- API security.
- Encryption and secret management.
- Rate limiting.
- CSRF/replay protection.
- Audit logging.
- Sensitive-data privacy.
- Account deletion workflows.
- Database backups and restore tests.
- Alerting and observability.
- Staging/production isolation.
- Safe deployment and rollback.
- Background job monitoring.
- Performance and load testing.
- Concurrency tests.
- Accessibility.
- RTL layout verification.
- Currency and timezone handling.
- UAE regional configuration.
- GCC/global-ready architecture.
- Dependency and license checks.
- API documentation.
- Error and offline recovery.

Do not claim legal, tax or security certification without authorized independent review.

## 15. DEVELOPMENT PRIORITY 12 — COMPLETE APPLICATION DELIVERY

Complete the software delivery requirements for:

### Customer
- Web.
- Android.
- iOS.

### Vendor
- Web.
- Android.
- iOS.

### Super Admin
- Web.

Ensure customer/vendor web and mobile have appropriate feature parity and use the same authoritative backend records.

Verify:

- Authentication.
- Navigation.
- APIs.
- Permissions.
- Error handling.
- Localization.
- Loading states.
- Responsive UI.
- Payment interfaces.
- Charging workflows.
- Privacy.
- Secure native storage.
- Build reproducibility.
- App signing configuration.
- App store release preparation.
- Production configuration and environment templates.

Generate supported release artifacts when signing credentials are legitimately available.

Do not claim store publication or physical-device acceptance without completing it.

## 16. MANDATORY TESTING AND ACCEPTANCE

For every major module:

1. Implement database changes safely.
2. Implement Laravel APIs and authorization.
3. Implement the required web interfaces.
4. Implement the required Flutter interfaces.
5. Add unit and integration tests.
6. Add PostgreSQL transaction/concurrency tests where relevant.
7. Run browser end-to-end tests.
8. Test both English and Arabic RTL.
9. Test Light/Dark/System themes.
10. Verify no previous completed functionality regressed.
11. Run Android and iOS build validation.
12. Run OCPP/OCPI protocol tests when applicable.
13. Fix failures before marking requirements verified.
14. Commit and push tested checkpoints.
15. Update requirements, progress and QA evidence.

Use the existing project testing commands and CI workflows.

Maintain compatibility with the installed tooling and do not modify release/security controls merely to satisfy a test.

## 17. AUTONOMOUS DEVELOPMENT EXECUTION

You are authorized to work through all independently implementable remaining software requirements without requesting approval for each routine development step.

Follow this cycle:

**Read current requirements → choose highest-priority unfinished dependency → implement complete vertical slice → test → fix → commit → push → update evidence → continue to the next requirement.**

Do not stop after one minor fix.

Do not ask the owner to repeatedly type "next."

Do not use lengthy status-only replies as substitutes for implementation.

When one module requires external credentials, an actual charger, a contractual partner or legal approval:

- Complete every independently implementable software component.
- Implement safe mock/sandbox integration tests.
- Document the exact external requirement.
- Do not fabricate successful external acceptance.
- Continue to another available task.

Continue throughout the active coding session. Before the session ends, commit completed tested work and leave exact restoration instructions.

## 18. SOURCE CONTROL AND PROGRESS MANAGEMENT

Continue using:

`development/fullstack-continuation`

Respect existing GitHub PR history.

Keep the working tree clean at verified checkpoints.

Use meaningful commits.

Do not delete or reset completed work.

Update:

- `docs/REQUIREMENT_STATUS.md`
- `docs/PROGRESS.md`
- `docs/QA_REPORT.md`
- `docs/UNIVERSAL_CONTINUATION.md`
- `docs/FINANCE_COMMERCIAL_CONTINUATION.md`

Use the original PRD requirements as the definitive functional checklist.

Track each requirement using:

- NOT_STARTED
- IN_PROGRESS
- SIMULATED_TESTED
- INTEGRATED_TESTED
- HARDWARE_TESTED
- PRODUCTION_READY
- BLOCKED_EXTERNAL

Include supporting evidence for every claimed state.

Distinguish code-complete software from externally blocked acceptance.

Do not use arbitrary commit counts or test counts to claim a percentage of completion.

## 19. FINAL OBJECTIVE

Deliver the entire original Crea-Biz EV Charging Aggregator & Management System software.

**Not an MVP. Not a demo. Not backend only. Not web only.**

The objective is complete, integrated and internally verified software for customer, vendor and Super Admin, including direct charging management, authorized third-party aggregation and commercial functions.

External production acceptance must be reported separately and truthfully.

Agent 2 now owns the full remaining project until explicitly reassigned.

## IMMEDIATE EXECUTION COMMAND

START NOW FROM THE LATEST VERIFIED `development/fullstack-continuation` BRANCH.

FIRST COMPLETE CANONICAL CONNECTOR/EVSE/SOURCE MAPPING AND MULTI-CONNECTOR RESERVATIONS.

THEN CONTINUE AUTOMATICALLY THROUGH ALL REMAINING ORIGINAL REQUIREMENTS, INCLUDING FINANCE, BACKEND, WEB, FLUTTER, ADMIN, OCPP, OCPI, MAPS, LOYALTY, FLEET, SECURITY AND DELIVERY.

DO NOT REBUILD ALREADY COMPLETED MODULES.

DO NOT REDUCE SCOPE.

IMPLEMENT SUBSTANTIAL WORK, VERIFY IT, COMMIT IT, PUSH IT, AND CONTINUE THROUGHOUT YOUR ACTIVE SESSION WITHOUT WAITING FOR ANOTHER USER COMMAND.