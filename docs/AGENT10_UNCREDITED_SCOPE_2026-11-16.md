# Agent 10 — Original software-coverage gap ledger

**Exact integrated source:** `48f136d750689b3fccb93727748006428a3912bb` (Agent 4 PR #131 merge). **Scope:** original 42 categories / 312 software atoms.

This is an **independent, read-only classification** of the committed requirement inventory against its committed coverage report. It does not assert that implementations are absent merely because they lack credit; uncredited work can be unfinished, insufficiently proven, awaiting external approval, or scoped differently. It is **not** a delivery approval, replacement product specification, source commit inspection, or workload estimate.

**Raw stage counts:** 186 coded / 185 tested / 185 merged / 0 production accepted. **Weighted:** 58.20% coded, 57.81% tested, 57.81% merged, 0.00% production accepted.

**Software gaps:** 126 not coded-credit; 1 coded-without-tested-credit; 0 tested-without-merge-credit. All 312 lack verified production acceptance.

## Category overview

| PRD | Original category | Atoms | Coded credit | Uncoded credit | Tested & merged credit |
|---|---|---:|---:|---:|---:|
| 2.1 | Identity, verification, 2FA, privacy | 9 | 8 | 1 | 8 |
| 2.2 | EV garage and driver preferences | 7 | 6 | 1 | 6 |
| 2.3 | Search, maps, routes, favorites, reviews | 9 | 5 | 4 | 5 |
| 2.4 | Location/connector details and pricing terms | 8 | 6 | 2 | 6 |
| 2.5 | Owned and network discovery | 6 | 3 | 3 | 3 |
| 2.6 | Reservations and occupancy policy | 7 | 6 | 1 | 6 |
| 2.7 | Remote charging and monitoring | 9 | 6 | 3 | 6 |
| 2.8 | Checkout, wallet, invoices, disputes | 10 | 6 | 4 | 6 |
| 2.9 | Loyalty/rewards/promotions/referrals | 7 | 3 | 4 | 3 |
| 2.10 | Localization/accessibility/formatting | 8 | 4 | 4 | 4 |
| 3.1 | Vendor onboarding/KYC/staff | 8 | 7 | 1 | 7 |
| 3.2 | Vendor inventory/provisioning | 8 | 5 | 3 | 5 |
| 3.3 | Vendor live operations and controls | 6 | 3 | 3 | 3 |
| 3.4 | Vendor calendar/blocks/tariffs | 8 | 5 | 3 | 5 |
| 3.5 | Vendor finance/settlements | 9 | 6 | 3 | 6 |
| 3.6 | Vendor analytics/exports | 6 | 3 | 3 | 3 |
| 3.7 | Vendor plans/marketing/ads | 7 | 3 | 4 | 3 |
| 3.8 | Vendor support/notifications/maintenance | 6 | 3 | 3 | 3 |
| 4.1 | Admin accounts/KYC/moderation/roles | 7 | 3 | 4 | 3 |
| 4.2 | Admin map/integration operations | 7 | 4 | 3 | 4 |
| 4.3 | Admin finance/plans/commissions/tax | 9 | 6 | 3 | 6 |
| 4.4 | Admin enterprise/campaigns/partners | 6 | 2 | 4 | 2 |
| 4.5 | Admin observability/compliance | 7 | 3 | 4 | 3 |
| 4.6 | Country/localization/feature gates | 6 | 4 | 2 | 4 |
| 5.1 | Separate compatible CSMS | 6 | 4 | 2 | 4 |
| 5.2 | Complete charger protocol operations | 11 | 5 | 6 | 5 |
| 5.3 | Reliable events/state/reconciliation | 8 | 5 | 3 | 5 |
| 5.4 | Simulator/hardware compatibility QA | 5 | 3 | 2 | 3 |
| 6.1 | Normalized catalog/freshness | 6 | 5 | 1 | 4 |
| 6.2 | Licensed discovery providers | 4 | 0 | 4 | 0 |
| 6.3 | Complete CPO/eMSP OCPI | 8 | 3 | 5 | 3 |
| 6.4 | Partner access/capability/error handling | 8 | 5 | 3 | 5 |
| 6.5 | Truthful partner availability/controls | 3 | 3 | 0 | 3 |
| 7.1 | Subscriptions/commissions/contracts/ads | 10 | 5 | 5 | 5 |
| 7.2 | PSP/tokenization/webhooks/legal invoices | 7 | 4 | 3 | 4 |
| 7.3 | End-to-end ledgers/reconciliation | 9 | 6 | 3 | 6 |
| 7.4 | Wallet/stored value | 2 | 0 | 2 | 0 |
| 8.1 | Enterprise organizations/billing | 8 | 4 | 4 | 4 |
| 9.1 | RBAC/privacy/security/operations | 13 | 9 | 4 | 9 |
| 9.2 | Accessible responsive release surfaces | 10 | 5 | 5 | 5 |
| 9.3 | Maintainable architecture/tests/licensing | 8 | 6 | 2 | 6 |
| 9.4 | International readiness | 6 | 4 | 2 | 4 |

## Remaining original software scope

Only missing **coded credit** is listed below, with the precise original atom ID and name. Each item requires actual code/evidence review by the responsible product agent; none should be indiscriminately marked complete or implemented solely to raise a score.

### 2.1 — Identity, verification, 2FA, privacy (1 without coded credit)

- `2.1.7` — Approved social login

### 2.2 — EV garage and driver preferences (1 without coded credit)

- `2.2.7` — Authorized vehicle API SOC

### 2.3 — Search, maps, routes, favorites, reviews (4 without coded credit)

- `2.3.4` — Interactive station maps
- `2.3.5` — Address/operator/access/price filters
- `2.3.6` — Supported live availability filters
- `2.3.8` — Favorites

### 2.4 — Location/connector details and pricing terms (2 without coded credit)

- `2.4.7` — Customer pre-charge estimate/fee acceptance
- `2.4.8` — Reservation terms and per-connector freshness

### 2.5 — Owned and network discovery (3 without coded credit)

- `2.5.4` — Unified provider search
- `2.5.5` — Licensed discovery-provider adapter
- `2.5.6` — Authorized actionable roaming

### 2.6 — Reservations and occupancy policy (1 without coded credit)

- `2.6.6` — Complete no-show/occupancy policy

### 2.7 — Remote charging and monitoring (3 without coded credit)

- `2.7.7` — Production charger registration and rider authorization
- `2.7.8` — Production certificate/IdToken lifecycle
- `2.7.9` — Complete real-source incident/live-cost experience

### 2.8 — Checkout, wallet, invoices, disputes (4 without coded credit)

- `2.8.7` — Full native checkout/refund/dispute actions
- `2.8.8` — Provider dispute submission and automatic reconciliation
- `2.8.9` — Legal tax invoice artifacts
- `2.8.10` — Legally approved stored-value wallet

### 2.9 — Loyalty/rewards/promotions/referrals (4 without coded credit)

- `2.9.4` — Coupons
- `2.9.5` — Referrals
- `2.9.6` — Promotions
- `2.9.7` — Partner reward governance

### 2.10 — Localization/accessibility/formatting (4 without coded credit)

- `2.10.5` — Full workflow translation audit
- `2.10.6` — Full accessibility/device acceptance
- `2.10.7` — Complete regional time presentation
- `2.10.8` — Additional language rollout

### 3.1 — Vendor onboarding/KYC/staff (1 without coded credit)

- `3.1.8` — External verification-service software and legal workflows

### 3.2 — Vendor inventory/provisioning (3 without coded credit)

- `3.2.6` — Production upstream charger registration
- `3.2.7` — Certificate authority lifecycle
- `3.2.8` — Complete connection health/model/firmware management

### 3.3 — Vendor live operations and controls (3 without coded credit)

- `3.3.4` — Complete live connector fleet monitoring
- `3.3.5` — Expanded safe command set
- `3.3.6` — Complete operator/staff session control parity

### 3.4 — Vendor calendar/blocks/tariffs (3 without coded credit)

- `3.4.6` — Availability blocks and operational notices
- `3.4.7` — Dynamic-rate policies
- `3.4.8` — Full commercial tariff acceptance

### 3.5 — Vendor finance/settlements (3 without coded credit)

- `3.5.6` — Full native financial actions
- `3.5.8` — External dispute evidence/deadlines
- `3.5.9` — Complete earnings/settlement reporting

### 3.6 — Vendor analytics/exports (3 without coded credit)

- `3.6.2` — Revenue dashboard
- `3.6.3` — Uptime/downtime analysis
- `3.6.4` — Energy/demand analysis

### 3.7 — Vendor plans/marketing/ads (4 without coded credit)

- `3.7.4` — Complete native plan actions
- `3.7.5` — Marketing subscriptions
- `3.7.6` — Loyalty participation economics
- `3.7.7` — Advertising/promotions and permissions

### 3.8 — Vendor support/notifications/maintenance (3 without coded credit)

- `3.8.4` — Operational escalation/delivery preferences
- `3.8.5` — External notification delivery
- `3.8.6` — Complete incident lifecycle

### 4.1 — Admin accounts/KYC/moderation/roles (4 without coded credit)

- `4.1.4` — Complete customer/vendor account administration
- `4.1.5` — Permission administration
- `4.1.6` — Moderation/privacy/deletion
- `4.1.7` — Admin 2FA and impersonation governance

### 4.2 — Admin map/integration operations (3 without coded credit)

- `4.2.5` — Global operations map
- `4.2.6` — Complete production provisioning/health UI
- `4.2.7` — Full incident response operations

### 4.3 — Admin finance/plans/commissions/tax (3 without coded credit)

- `4.3.7` — Production bank/PSP reconciliation operations
- `4.3.8` — Legal invoice/tax documents
- `4.3.9` — Expanded finance approval governance

### 4.4 — Admin enterprise/campaigns/partners (4 without coded credit)

- `4.4.3` — Corporate contracts and billing governance
- `4.4.4` — Loyalty/campaign control
- `4.4.5` — Advertising management
- `4.4.6` — Retail partner administration

### 4.5 — Admin observability/compliance (4 without coded credit)

- `4.5.4` — Complete uptime/operational analytics
- `4.5.5` — Alert delivery and incident escalation
- `4.5.6` — Suspicious activity controls
- `4.5.7` — Compliance/privacy workflows

### 4.6 — Country/localization/feature gates (2 without coded credit)

- `4.6.5` — Complete regional feature administration
- `4.6.6` — Country-specific rollout validation

### 5.1 — Separate compatible CSMS (2 without coded credit)

- `5.1.5` — Complete model/firmware compatibility matrix
- `5.1.6` — Production operational validation software

### 5.2 — Complete charger protocol operations (6 without coded credit)

- `5.2.6` — Production upstream registration/JWT
- `5.2.7` — Rider RFID/IdToken lifecycle
- `5.2.8` — Certificate authority lifecycle
- `5.2.9` — Diagnostics
- `5.2.10` — Firmware management
- `5.2.11` — Authorized safe reset operations

### 5.3 — Reliable events/state/reconciliation (3 without coded credit)

- `5.3.6` — Complete production transport operations
- `5.3.7` — Terminal incident/alert lifecycle
- `5.3.8` — Broader outage/load hardening

### 5.4 — Simulator/hardware compatibility QA (2 without coded credit)

- `5.4.4` — Expanded fault/load/model test matrix
- `5.4.5` — Complete approved hardware-pilot software/runbook evidence

### 6.1 — Normalized catalog/freshness (1 without coded credit)

- `6.1.6` — Complete provider capability mapping

### 6.2 — Licensed discovery providers (4 without coded credit)

- `6.2.1` — Licensed discovery-provider adapter
- `6.2.2` — Attribution/rate/cache policies
- `6.2.3` — Provider-specific frontend integration
- `6.2.4` — Coverage-quality monitoring

### 6.3 — Complete CPO/eMSP OCPI (5 without coded credit)

- `6.3.4` — Complete CPO/eMSP roles and push APIs
- `6.3.5` — Credential/token lifecycle
- `6.3.6` — Authorized remote commands
- `6.3.7` — Roaming reservations
- `6.3.8` — Full reconciliation/conformance

### 6.4 — Partner access/capability/error handling (3 without coded credit)

- `6.4.6` — Full credential rotation lifecycle
- `6.4.7` — Network-specific authorized adapters
- `6.4.8` — Contractual onboarding software

### 7.1 — Subscriptions/commissions/contracts/ads (5 without coded credit)

- `7.1.6` — Complete commercial eligibility/proration enforcement
- `7.1.7` — Corporate contracts/consolidated billing
- `7.1.8` — Promotions/loyalty economics
- `7.1.9` — Advertising billing
- `7.1.10` — Full native commercial action parity

### 7.2 — PSP/tokenization/webhooks/legal invoices (3 without coded credit)

- `7.2.5` — Complete approved live-mode adapter software
- `7.2.6` — Complete SCA/native checkout parity
- `7.2.7` — Legal numbered tax invoice documents

### 7.3 — End-to-end ledgers/reconciliation (3 without coded credit)

- `7.3.7` — Partner-CDR financial reconciliation
- `7.3.8` — Automatic PSP/bank reconciliation operations
- `7.3.9` — External dispute evidence/deadlines

### 7.4 — Wallet/stored value (2 without coded credit)

- `7.4.1` — Legally approved stored-value design
- `7.4.2` — Approved wallet software and all required surfaces

### 8.1 — Enterprise organizations/billing (4 without coded credit)

- `8.1.5` — Plan eligibility and policy enforcement
- `8.1.6` — Consolidated corporate billing
- `8.1.7` — Enterprise contracts
- `8.1.8` — Complete fleet reporting and administration

### 9.1 — RBAC/privacy/security/operations (4 without coded credit)

- `9.1.9` — Privacy deletion workflows
- `9.1.10` — Verified backup/restore
- `9.1.11` — Alerting/telemetry operations
- `9.1.13` — Verified production deployment/rollback

### 9.2 — Accessible responsive release surfaces (5 without coded credit)

- `9.2.6` — Complete workflow parity
- `9.2.7` — Comprehensive accessibility/visual audit
- `9.2.8` — Signed Android release/store package
- `9.2.9` — Signed iOS release/store package
- `9.2.10` — Real-device release acceptance

### 9.3 — Maintainable architecture/tests/licensing (2 without coded credit)

- `9.3.7` — Comprehensive performance/security/restore validation
- `9.3.8` — Complete dependency/release inventory audit

### 9.4 — International readiness (2 without coded credit)

- `9.4.5` — Complete international workflow UX
- `9.4.6` — Country-specific legal/PSP/telecom/operator rollout software

## Implemented but lacking tested / merged credit

- `6.1.5` (Normalized catalog/freshness) — Operational freshness policy; checkpoint: `pr120_catalog_policy`

## Independent external acceptance gates

Even when individual software atoms become coded, tested and merged, **customer delivery remains blocked** without separately authenticated production-level evidence for physical OCPP 1.6/2.0.1 charging, authorized contracted OCPI partners, activated PSP/bank and legal/tax controls, source-linked private staging and rollback, independent security/performance/accessibility review, and Android/iOS signed production delivery. Simulators, offline manifests, unsigned archives, and CI smoke checks do not satisfy these gates.

## Update discipline

- Review this snapshot against the latest integrated SHA before assigning or closing an atom. New PRs or pending checks do not alter integrated coverage by themselves.
- Keep the original atom and category IDs/denominators stable; reference a specific merged source, separate automated test evidence and external acceptance records.
- Do not commit real credentials, customer KYC, live bank/PSP identifiers, private host state or charger secrets as QA evidence.
- Agent 4 controls integration and source-of-truth coverage updates. Agent 10 independently cross-checks and reports, without changing original requirements or unearned stage flags.
