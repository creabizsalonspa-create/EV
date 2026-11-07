# Crea-Biz EV Charging Aggregator & Management System — Complete Product Requirements

## Active ownership — final owner directive, 28 October 2026 (Dubai)

Agent 2 is the sole active owner of all remaining Crea-Biz EV development, including financial and non-financial software, until the project owner explicitly changes the assignment. This supersedes previous exclusive finance ownership; the records below remain historical. Continue the canonical financial architecture, preserve both agents’ verified work and merge tested changes through pull requests. Full original scope remains required; simulator/contract tests do not establish hardware, merchant, roaming-partner or store acceptance.


## 1. Scope
Develop a complete commercial SaaS/mobility ecosystem with both **direct management of authorized physical charging stations via OCPP** and **aggregation of third-party charging networks via OCPI and authorized operator APIs**. Initial market: UAE (seven emirates); expansion-ready for GCC/international markets. The owner explicitly requires the **full platform**, not an MVP-only deliverable.

**Platforms:** Customer Web + Android + iOS; Vendor Web + Android + iOS; Super Admin Web. Customer and vendor must share the same authoritative backend records and support their respective workflows on all listed surfaces.

## 2. Customer features — web, Android, iOS
- Account registration, email/phone verification, secure sign-in, social login if approved, 2FA options, account/privacy settings, support and deletion requests.
- EV garage/vehicles: model, connector compatibility, preferred charging speed, driver preferences; battery SOC from manual input or authorized vehicle API only (never claim to read SOC from phone alone).
- Charging station search, map, lists, filters (distance, connector, power, price, operator, access, live availability where supported); routes, favorites, reviews and ratings.
- Detailed location, availability source, connector capabilities, hours, amenities, accessibility, transparent tariffs/fees, estimates, reservation terms and live-source timestamps.
- Search/discover across owned chargers and external networks; clearly distinguish discovery-only locations from actionable remote stations.
- Reservations where supported; concurrency control, cancellation/no-show policies, expiry, check-in and occupancy handling.
- Authorized remote start/stop (OCPP or supported roaming interface), status updates, energy readings, elapsed time, estimated/final cost, charging incidents and retry states.
- Card/payment gateway, secure checkout, optional wallet (subject to local regulation), invoices/receipts, refunds, disputes, transaction history and notification preferences.
- Silver/Gold/Platinum loyalty tiers, points, redemption, coupons, referral/promotions and partner rewards.
- English/Arabic, accessibility, clear RTL layout, currency and time zone formatting; future region/localization framework.

## 3. Vendor/operator features — web, Android, iOS
- Business onboarding and verification, legal/KYC documents, agreements, status and managed staff roles.
- Locations, chargers, connectors, hardware details, OCPP provisioning/onboarding and connection health; location/connector inventory and maintenance.
- Charger status (fault, idle, charging, unavailable), live session monitoring, allowed remote commands with control permissions and audit log.
- Reservations/calendar, availability blocks, opening schedules, operational notices, tariffs, prices, fees, taxes and dynamic-rate rules where permitted.
- Session details, invoices, transaction records, refunds/escalations, payout calculations, settlements and finance reconciliation.
- Dashboards for utilization, revenue, uptime, downtime, energy, demand, customer feedback and exports.
- Subscriptions/plans, marketing, loyalty participation, ads/promotions and staff permissions.
- Vendor support/notifications and operational maintenance workflows.

## 4. Super Admin web
- Customer/vendor accounts, verification, KYC, moderation, support ticketing, impersonation restrictions and role permission administration.
- Global map and station operations; charger/provider provisioning, integration credentials, OCPP/OCPI health and incident response.
- Plans, commissions, country-level tariff/tax configuration, settlement scheduling, invoices, refund/dispute controls, ledger/reconciliation.
- Fleet/enterprise accounts and B2B agreements; loyalty, campaigns, in-app ads and retail partners.
- Operational analytics, network uptime, observability, suspicious activity controls, audit trails and compliance workflows.
- Country setup, supported languages, currencies, time zones and regional feature gates.

## 5. Direct physical charging management
- Use a suitable separately deployed OCPP CSMS, evaluating CitrineOS. Validate OCPP 1.6 and 2.0.1 protocol behavior and vendor/firmware compatibility.
- Charger connection/bootstrap, authorization, connector state, heartbeats, transaction events, meter values, faults, remote start/stop, remote reset only with safety/authorization, firmware capabilities and diagnostics where supported.
- Event handling, duplicate/replay defense, outage behavior, state-machine correctness, monitoring and alerts, billing-grade meter/transaction reconciliation.
- Simulator and compatibility tests; physical hardware pilot only with approved, compatible devices and appropriate safety controls.

## 6. Third-party network aggregation
- Normalize a station/provider/connector catalog across direct-managed and partner/indirect stations with provenance and data freshness.
- Support *discovery-only* data sources like Open Charge Map under their API/licensing/attribution requirements.
- Implement OCPI CPO/eMSP interoperability or authorized provider APIs for locations, tariffs, tokens, sessions, CDRs, commands and reservations only where supported.
- Partner credentials, bilateral contracts, version negotiation, capability flags, error handling, reconciliation, and network-specific adapters.
- Never display fake availability, support remote charging on discovery-only locations or claim full roaming without real agreements and validated interoperation.

## 7. Finance / billing
- Configurable vendor subscription and per-session commission models, corporate contracts, settlement agreements, promotions and ads.
- Payment tokenization via approved PSP (do not store raw cards); authorized refunds, idempotent webhook processing, invoice numbering and tax treatments governed by legal/finance review.
- Vendor and platform ledgers; transactional reconciliation between charger transaction, partner CDR, invoice, payment, refund and vendor settlement.
- Wallet/stored-value only after regulatory/legal suitability is confirmed.

## 8. Fleet and enterprise
- Organization accounts, drivers, fleet vehicles, cost centers, plan eligibility, consolidated billing, reports and administrative permissions.

## 9. Cross-cutting requirements
- Strong RBAC, tenant isolation, admin 2FA, audit logs, encryption, backup and restore, staging/production isolation, alerting, telemetry and safe rollback.
- Responsive accessible web, mobile native builds/signing, app store readiness, localization, error states, offline degradation where relevant.
- Scalable but maintainable modular architecture, tested migration procedures, documented APIs, event contracts, CI/CD and dependency/license inventory.
- International readiness (multi-country/region/currency/tax/time zone/language) is required in domain design; actual rollout requires country-specific legal, PSP, tax, telecom and charging partner checks.

## 10. Sources, precedence and truth labels
Original 7-page 2025 feasibility document: `reference/CreaBiz_UAE_EV_Feasibility_Plan.pdf`. Owner's amendments supersede: (a) **customer and vendor each get web, Android, iOS**; (b) **both aggregation and direct charger control**; (c) **full system, not MVP-only**; (d) reuse secure and properly licensed OSS/CodeCanyon where economical.

Feature states must be recorded as `NOT_STARTED`, `IN_PROGRESS`, `SIMULATED_TESTED`, `INTEGRATED_TESTED`, `HARDWARE_TESTED`, `PRODUCTION_READY`, or `BLOCKED_EXTERNAL`, with evidence. `PRODUCTION_READY` requires applicable operational/legal/safety validations, not just code and UI.
