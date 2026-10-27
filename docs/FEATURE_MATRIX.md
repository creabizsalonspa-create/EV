# Feature and acceptance matrix

Updated 2026-10-27. Full product scope remains in force. Status applies to the narrow behavior in its row, not automatically to the entire module or every client surface.

- **Not started**: the required behavior is absent or its completion gate has not been implemented; any partial source is described separately.
- **Implemented**: the stated scope has code; its evidence and remaining integration/client limits must still be read.
- **Simulator tested**: the stated scope passed controlled synthetic tests. This never means physical hardware or an actual partner/payment was verified.
- **Live verified**: reserved for the actual authorized external integration and operation demonstrated. No charging/payment/roaming row currently has this status.
- **Blocked external dependency**: the named external prerequisite cannot be supplied by routine code changes. It does not excuse independent missing implementation.

`PROGRESS.md` retains the original requirement-document status vocabulary. These labels provide the newer owner-requested view of the same scope. Tests are linked in [TEST_EVIDENCE.md](TEST_EVIDENCE.md).

## Identity, customers and shared foundations

| Required behavior | Status | Implementation / evidence | Remaining scope |
|---|---|---|---|
| Web registration, login and logout | Implemented | `PlatformController`; `PlatformTest`; browser workflow | Full verification/social/2FA workflows remain |
| Customer-only self-registration and role boundaries | Implemented | `PlatformTest`, `MobileAuthTest` | Granular staff permissions and production admin 2FA remain |
| Profile name/language editing | Implemented | `AccountController`, `AccountTest`; web form | Complete preferences/privacy/account deletion remain |
| Password broker, expiry/single use and prior-session removal | Implemented | `AccountTest` | Real email delivery is unverified; native-token integration must retain regression coverage |
| Native bearer-token issue, expiry, logout and role restrictions | Implemented | `MobileAuthController`, `MobileAuthTest`; active source | Final client integration/build and security review remain |
| Email/phone verification and approved social sign-in | Not started | — | Implement actual delivery and verification policies |
| Customer/admin 2FA and recovery controls | Not started | — | Implement and test before production administration |
| Tenant-scoped records and authenticated rate limits | Implemented | `PlatformTest`, `EnterpriseTest`, `SupportTest`, `ChargerCommandTest` | Full organizational/staff policy matrix and distributed-load validation remain |
| Vehicle name/connector garage | Implemented | `Vehicle`, `PlatformController`; private-vehicle tests and browser coverage | Vehicle model/battery/preferences/authorized SOC and native feature parity remain |
| Account deletion/data export/privacy request workflow | Not started | — | Add retention, authorization and regional policies |

## Discovery, inventory and charging

| Required behavior | Status | Implementation / evidence | Remaining scope |
|---|---|---|---|
| Station catalog and name/connector/power/country filters | Implemented | `PlatformController`; `PlatformTest`; browser workflow | Distance/route/price/operator/access filters and full detail metadata remain |
| Truthful discovery-only/simulator provenance and unknown availability | Implemented | `PlatformTest`; catalog response flags | No live network availability is claimed |
| Map, navigation and discovery-provider adapter | Not started | — | Implement provider/attribution/capability boundaries; external map keys may be needed |
| Favorites, reviews and ratings | Not started | — | Add ownership, moderation and genuine-session provenance |
| Vendor-created station with one test connector | Implemented | `PlatformTest`; vendor browser form | This is not the complete site/charger/EVSE/connector hierarchy |
| Full sites/chargers/connectors, schedules and maintenance | Not started | — | Implement multi-connector inventory, lifecycle, outages and permissions |
| Fixed 30-minute UTC simulator reservation and cancellation | Simulator tested | `PlatformTest`; browser workflow | Arbitrary booking policies, occupancy, no-show/expiry and production DB concurrency remain |
| Application simulator start/meter/stop and frozen estimate | Simulator tested | `SimulationController`, `SimulationTest` | Not linked to authoritative OCPP transaction/meter events; no final invoice implied |
| Separate pinned CitrineOS/OCPP deployment | Simulator tested | `services/csms/compose.yaml`; OCPP evidence | Secure staging JWT/TLS/mTLS and full upstream-service topology remain |
| OCPP 1.6 and 2.0.1 boot/heartbeat/status | Simulator tested | `tests/ocpp.cjs`; `evidence/ocpp-expanded.txt` | Device/firmware compatibility is unverified |
| Authenticated remote-start delivery and simulator acknowledgment | Simulator tested | Direct API and Laravel-outbox paths in `tests/ocpp.cjs` | Submitted is not physical charging or transaction completion |
| Outbox tenant scope, idempotency and uncertain-outcome protection | Simulator tested | `ChargerCommandTest`; `CsmsDispatcher` | Durable event/acknowledgment correlation and operational reconciliation UI remain |
| OCPP transaction start/end, signed meters and billing reconciliation | Not started | Separate simulator and transport exist | Implement the full correlated lifecycle and failure cases |
| OCPP remote stop, fault/reconnect/outage recovery and diagnostics | Not started | No passing complete protocol evidence | Test declared subsets before claiming support |
| Live charging hardware pilot | Blocked external dependency | No hardware tests executed | Approved site/device/firmware, safety authorization and monitored acceptance |

## Roaming, commercial and international modules

| Required behavior | Status | Implementation / evidence | Remaining scope |
|---|---|---|---|
| CitrineOS CPO versus outbound eMSP assessment | Implemented | `services/csms/README.md`; inspected upstream role checks | Assessment is not OCPI conformance |
| OCPI 2.2.1 outbound provider/version/capability boundary | Not started | Active independent implementation task; not yet integrated evidence | Register adapter, run contract tests, and expose correct provenance |
| OCPI locations/tariffs/tokens/sessions/CDRs/commands/reservations | Not started | Active adapter work must be reviewed by capability | Complete role-specific contracts, state synchronization and reconciliation |
| Actual network roaming partnership | Blocked external dependency | No live partner test | Bilateral agreement, provider credentials, sandbox/access and agreed scope |
| Country/currency/minor-unit/timezone/tax configuration | Implemented | `EnterpriseController`, `EnterpriseTest` | Commerce remains disabled; regional legal/tax rules are not approved by a config row |
| Tariff snapshots and invoice/ledger calculations | Not started | Active finance implementation task; not yet integrated evidence | Complete schema/service/authorization/tests and end-to-end billing |
| Payment sandbox, refunds, disputes and duplicate-webhook defense | Not started | Active finance work must remain simulated unless provider-tested | Implement approved PSP integration and reconciliation |
| Actual PSP activation or live money movement | Blocked external dependency | No real payment performed | Approved merchant/sandbox credentials and explicit production authorization |
| Wallet/stored value | Blocked external dependency | No activated wallet | Legal/regulatory suitability and approved provider; code is also incomplete |
| Vendor commissions, settlements and subscriptions | Not started | — | Implement finance-backed calculations, schedules, plans and reports |
| Fleet creation/invitation/acceptance/revocation | Implemented | `EnterpriseController`, `EnterpriseTest`; browser invitation flow | Full corporate administration and client parity remain |
| Own-vehicle fleet assignment and same-fleet cost centers | Implemented | `EnterpriseTest` | Complete management UI, budgets and financial reporting remain |
| Fleet consolidated billing/contracts/cost reporting | Not started | — | Depends on authoritative invoice/payment/settlement records |
| Loyalty Silver/Gold/Platinum, rewards/redemption/referrals | Not started | — | Implement after authoritative financial/session events |
| Coupons, promotions, advertising and marketing administration | Not started | — | Implement eligibility, audit, budgets and all surfaces |
| Support tickets, state transitions and internal-note privacy | Implemented | `SupportController`, `SupportTest`; browser privacy workflow | Complete service assignment/escalation/maintenance integrations remain |
| In-app notification feed and read state | Implemented | `SupportTest`, web/API | Push/SMS/email delivery and preference management remain |
| Secure external notification delivery | Blocked external dependency | No delivery provider verified | Provider setup/credentials plus outstanding adapter/queue implementation |

## Applications, operations and final delivery

| Required behavior | Status | Implementation / evidence | Remaining scope |
|---|---|---|---|
| Customer responsive web foundation (not full web release) | Implemented | `platform.blade.php`, `platform.js`; browser workflow | Every customer requirement and full localization/accessibility remain required |
| Vendor responsive web foundation (not full web release) | Implemented | Shared role-scoped web forms and tests | Complete operator/KYC/staff/financial/analytics workflows remain |
| Super Admin web foundation | Implemented | Counts/audit, region and support workflows | Complete RBAC, vendor/customer/financial/commercial/operational administration remains |
| Customer native client foundation | Implemented | Android/iOS project and shared client; shared analysis and 7 API/widget tests passed | APK/iOS compile, full feature coverage and device integration remain unverified |
| Vendor native client foundation | Implemented | Android/iOS project and shared client; role/RTL/security/provenance widget tests passed | APK/iOS compile, full feature coverage and device integration remain unverified |
| Customer/vendor Android APKs | Not started | Customer build in progress; no successful artifact recorded yet | Build both actual apps, retain artifacts/checksums/logs and test them |
| Customer/vendor release-ready Android AABs | Not started | No accepted AAB artifact | Full client features, release API configuration and genuine release signing required |
| Customer/vendor iOS projects | Implemented | Generated iOS source and shared client foundation | Full functionality and macOS compilation remain unverified |
| Signed IPA/store release artifacts | Blocked external dependency | No iOS build executed | macOS/Xcode runner, signing/store access and full client implementation |
| English/Arabic direction foundation | Implemented | Web RTL/390px checks; native localization source | Full translation, formatting, accessibility and device matrix remain |
| Dependency locks, migrations and repeatable local scripts | Implemented | `scripts/setup`, `scripts/start`, `scripts/csms-*` | Fresh-task snapshot restoration and production rollout remain unverified |
| Git branch, commits and draft PR | Implemented | `e7e4833` checkpoint; PR #1 | Keep source/evidence current; PR is not acceptance or production deployment |
| Hosted integration CI workflow | Implemented | `.github/workflows/ci.yml` | A completed hosted outcome must be recorded before claiming it passed |
| OpenAPI specification and generated client contract | Not started | Current human-readable `API.md` only | Complete machine-readable endpoint/error/auth schemas and drift checks |
| Security headers, scoped limits and dependency scans | Implemented | `AccountTest`, `SupportTest`; advisory evidence | Full threat model, penetration/load tests, image SBOM and remediation remain |
| Immutable audit trail, monitoring/alerting and operational reports | Not started | Basic append-style audit records exist | Database enforcement, full event coverage, telemetry and report validation remain |
| Backup/restore, staging deployment/TLS and rollback drill | Not started | Development operations notes only | Implement and test repeatable infrastructure and recovery procedures |
| Production deployment and regulatory approval | Blocked external dependency | No production deployed or certified | Explicit infrastructure authorization, operational/legal review and external gates |
| Final release/archive and owner acceptance | Not started | Development checkpoints only | All independent implementation/QA complete; exact remaining external gates documented |

## Product acceptance gates

No gate is marked wholly passed by a foundation/subset result.

| Gate from ACCEPTANCE_TESTS.md | Current status | Completion evidence still required |
|---|---|---|
| A — Engineering foundation | Implemented, partial gate | Fresh-checkout/new-task reproducibility, complete version/license/security review, staging and tested rollback |
| B — Controlled charging end to end | Simulator tested, partial gate | One correlated OCPP transaction/meter/customer-vendor-admin/invoice workflow, failure/retry/reconnect coverage and complete connector hierarchy |
| C — All user surfaces | Not started as a complete gate | Every required feature across customer/vendor web/Android/iOS and admin web, complete accessibility/Arabic-English/device checks |
| D — Payments, enterprise and engagement | Not started as a complete gate | Approved sandbox finance/reconciliation/refund/dispute flow, fleet consolidated billing, loyalty/subscriptions/marketing and commercial administration |
| E — Actual charger hardware | Blocked external dependency | Authorized physical device/site/firmware, safety procedure and recorded hardware cases |
| F — Actual aggregator partnership | Blocked external dependency | Authorized network access/agreement and passed actual agreed OCPI/operator reconciliation/command cases |
| G — Release readiness | Not started as a complete gate | Full software QA, security/load/recovery/staging checks, genuine build/signing/store artifacts and required operational/legal approvals |
