# CREA-BIZ EV — MASTER CONTINUATION COMMAND FOR AGENT 2

## Active ownership — final owner directive, 28 October 2026 (Dubai)

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