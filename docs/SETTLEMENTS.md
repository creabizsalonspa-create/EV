# Vendor commissions, payables and settlements

Implemented source: `SettlementService`, `SettlementController`, migrations `2026_10_28_120001` and additive `120002`, and `routes/settlements.php`. This is a shared backend contract for the responsive web workspace and customer/vendor native clients. No bank account, wallet, transfer, payout API or real money movement is enabled. Settlement execution is explicitly `SIMULATED`, and disabled in production.

## Accounting and eligibility

The immutable invoice's commission and vendor portions remain authoritative. Eligibility requires **full payment capture**, a configured/enabled administrator schedule, the holding period, a due date, and a minimum payable. Partial captures cannot be settled. Currencies never net against one another. Statements return authoritative `minor_units`; historical invoice, adjustment and settlement precision must agree with configured regional precision. Missing or mixed precision is rejected before money is formatted or reserved. Adjustments snapshot their precision. Migration 120002 validates legacy metadata before DDL and backfills new metadata without changing economic values.

Creating a pending settlement reserves the available vendor amount across immutable invoice/adjustment lines. Pending, approved and simulated-paid records all count against availability. Rejected records release their reservations. Vendor/user row and source invoice locks serialize drafts and payment/refund writes. Administrator approval and simulated execution recheck current balances while locking source invoices, so a refund between draft and approval can block execution until the obsolete request is rejected/rebuilt.

Customer refunds are never blocked simply because a vendor has already settled. A later refund creates a **negative payable balance**, which offsets subsequent earnings in the same currency. A subsequent settlement includes signed negative debt lines along with positive earnings. This is a payable/recovery record, not a customer wallet or an automatic debit to a bank account. Commercial collection of unrecovered vendor debt remains external.

Administrator adjustments require a reason and a different administrator's approval. Approved adjustments have immutable, balanced vendor-payable/platform-adjustment debit/credit entries. Neither adjustments nor settlements rewrite invoice, payment, credit-note or original charging journals. Settlements preserve immutable economic terms, lines and audit events; state transitions are controlled and idempotent.

## Authenticated API contract

Mount `routes/settlements.php` inside the existing authenticated `/api` and optional `/api/v1` groups. All integer amounts are in the named currency's minor units. These APIs expose no banking credentials.

| Endpoint | Role / payload |
| --- | --- |
| `GET /commercial/settlements` | Vendor sees own records; admin sees all. Optional currency, state, UTC creation-date range and bounded page filters |
| `GET /commercial/settlements/export` | Private UTF-8 CSV for the same filtered page. Amount and precision fields use exact minor units |
| `GET /commercial/vendors/{vendor}/statement?currency=AED` | Owning vendor/admin; returns invoiced totals, available payable, signed source lines, schedule, adjustments, eligibility and explicit simulation status |
| `PUT /commercial/vendors/{vendor}/schedule` | Admin: `currency`, `hold_days` 0–365, `interval_days` 1–365, `minimum_minor` >0, `next_due_at` ISO date, `enabled` boolean |
| `POST /commercial/vendors/{vendor}/settlements` | Owning vendor/admin: `currency`, `idempotency_key`; creates/replays pending settlement |
| `GET /commercial/settlements/{id}` | Owning vendor/admin: economic record, immutable lines/events, reconciliation boolean |
| `POST /commercial/settlements/{id}/approve` | Admin; pending → approved |
| `POST /commercial/settlements/{id}/reject` | Admin; pending → rejected |
| `POST /commercial/settlements/{id}/simulate-payout` | Admin; approved → simulated_paid; advances schedule; no external transfer |
| `POST /commercial/vendors/{vendor}/adjustments` | Admin: `currency`, signed nonzero `amount_minor`, `reason`, `idempotency_key` |
| `POST /commercial/adjustments/{id}/approve` | A different admin approves once |

Request keys are actor-scoped and bound to vendor, currency and economic payload; changed-payload reuse returns 409. Cross-tenant resource access returns 404. Invalid lifecycle, unavailable payable, disabled/not-due schedule, unresolved precision or changed balance returns 409. Simulation operations return 503 in production.

## Validation and remaining gates

`SettlementTest` covers full capture, commission, reservation/idempotency, admin approval, simulated execution, rejection/rebuild after refund, late-refund debt offsets, partial/held funds, tenant isolation, currency separation, dual-control adjustments, balanced immutable adjustment entries and production simulation denial.

`tests/Postgres/SettlementConcurrencyTest.php` runs only against a guarded disposable PostgreSQL database and starts two actual PHP worker processes behind a barrier. It tests simultaneous different-key drafts (one reserve, one conflict) and identical-key retries (one persisted settlement, both resolve). Execute via the PostgreSQL finance runner; passing evidence must be recorded by the task that actually executes it. SQLite feature results alone do not prove PostgreSQL concurrency.

No PSP or bank settlement provider is connected by this module. Actual payout-provider integration, merchant onboarding, bank beneficiary validation, jurisdiction-specific commission/tax/legal review and external bank statement reconciliation remain distinct gates. This module does not claim those gates complete.


## Earnings CSV source and Unicode formula safeguards (Agent 3 follow-up)

The existing read-only Vendor/Admin earnings JSON and page-bounded CSV use
`VendorEarningsController::minor()` for exact source amounts. The primitive
now explicitly refuses PHP floats/booleans **before** integer conversion:
`100.0` and `true` used to pass integer validation although the original
source may already have been rounded or coerced. Integer and canonical
decimal-string minor units are supported; sources outside the signed/unsigned
2^53−1 browser-safe bound remain rejected with 409.

Invoice numbers **and source evidence labels** in the **CSV only**, never
in the immutable database or JSON, are prefixed as spreadsheet literals
when a formula introducer
(`=`, `+`, `-`, `@`) is preceded by Unicode whitespace, controls,
format or bidirectional markers. This includes invisible zero-width,
BOM, RTL override and nonbreaking-space prefixes, in addition to
ASCII controls. The page exporter remains private, no-store, nosniff,
UTF-8 and no custom backslash CSV escaping.

Added `SettlementTest` source-primitive regressions for whole-valued
floats/booleans and invisible Unicode-formula prefixes, while preserving
auth and genuine immutable issued-invoice access tests. The changes do
not create payment/refund/settlement transactions, edit canonical journals
or enable real payouts. Exact-head Finance/Platform CI and Agent 5 merge
are still required; original 3.5.9 remains partial until all required
surfaces and integrated verification are complete.


## Reconciliation audit exact-source safety (Agent 3 continuation)

`InvoiceReconciliationAudit::inspect()` now verifies exact signed
minor-unit source values **before** its legacy integer casts, for the
immutable invoice components, source journals, posted debit/credit entries,
payments, refunds and unpaid credits. It rejects PHP float/boolean,
non-integral decimal strings and source totals outside the ±(2^53−1)
browser-safe bound with HTTP 409 rather than misreporting a rounded damaged
source as `reconciled`. Signed negative accounting anomalies inside the
exact range remain available for the original mismatch classifiers; no
journal is silently corrected, recalculated or written. Per-invoice
refunded component, journal-entry and collection aggregate sums receive
the same exact-bound checks before serialization.

The finance reconciliation CSV formula-literal guard now also recognizes
Unicode separation/format/control characters such as nonbreaking space,
zero-width space, bidi controls and BOM preceding an initial formula
introducer. Added private audit feature regressions for exact numeric
limits, rejected float/boolean/exponent sources and Unicode-hidden
formula prefixes; existing legitimate API and immutable journal tests
remain the required regression gates. This remains an internal
simulation-backed audit, **not** automatic live PSP/bank reconciliation.
All changes require exact-head Finance/Platform CI and Agent 5 integration.


## Canonical settlement balance checked arithmetic (Agent 3 continuation)

The **underlying** `SettlementService::balances()` now validates every
authoritative payment, refund, reserved settlement line, invoice
component, approved adjustment and completed settlement aggregate as an
exact integer *before* applying arithmetic or serializing a private
statement. It rejects PHP float/boolean, malformed or browser-inexact
money, and verifies each running gross/commission/tax/refund/available
sum against ±(2^53−1). The former `array_sum` of signed payable lines
could turn a large otherwise valid collection of line items into an
inexact floating-point total; the new checked accumulation fails closed
with HTTP 409. All historical signed negative refund debts and payable
adjustments within range remain supported.

A real authenticated feature regression creates two individually
representable, synthetic test-only signed approved adjustment records
whose total is exactly 2^53. It requires 409 from the Vendor statement,
Vendor earnings report and settlement draft, **before** any payable
reservation is created. A second regression covers exact positive and
negative range bounds, float/boolean source rejection and grouped
addition. No financial journal, payable settlement or actual payout is
created. This is defensive software verification; source approval
authenticity, merchant acceptance, exact-head CI and Agent 5 merge
remain independent requirements.


## Private settlement and adjustment receipts (Agent 3 continuation)

The Vendor/Admin settlements list, direct settlement detail, newly drafted
settlement, exact idempotent draft replay, adjustment creation and adjustment
replay now **redact the server-owned `request_key` and `fingerprint`**
from every public response. Persisted immutable hashes remain in their
original database records and continue to enforce same-key idempotency.
No change to who can read, draft, reject, approve or simulate a payout;
this is a privacy-only DTO boundary.

A settlement detail's `reconciled` boolean now calls the canonical
`SettlementService::reconciles` exact, signed accumulator instead of
casting the immutable source and an unchecked collection sum to ints.
Refund-debt lines remain legal and are checked against the payable
amount. Any oversized or inexact source fails 409 instead of returning
an incorrect green reconciliation. End-to-end tests exercise owner
list/detail/draft retries and Admin adjustment replay, verify request
keys remain server-owned, and test signed-detail arithmetic and overflow
without modifying the canonical cash ledger.

These are partial backend authority and integrity improvements, not
external payout confirmation or production acceptance. Tests require
exact-source CI, Agent 5 merge and integrated checks.


## Live vendor-account payout authority (Agent 3 continuation)

The settlement writer now checks the persisted owning vendor's
`account_status=active` under the same vendor row lock when drafting a
new payable, rather than merely checking the `vendor` role. An
administrator cannot reserve new funds for a suspended merchant.
Approval and simulated execution also recheck that the payee remains
an active vendor under lock; a demoted or suspended merchant cannot
receive a subsequent state advancement.

**Rejection remains available** to a current administrator even while
the vendor is suspended/demoted, so an existing pending or approved
claim can be cancelled safely and reserved funds released for supervised
review. Historical settlement, invoice and audit records are preserved;
no automatic real payout is enabled.

`SettlementTest` exercises suspension before approval and drafting,
then reinstatement, approval, post-approval role demotion, blocked
execution and successful Admin rejection with the original event trail.
It verifies no new payment, bank claim, refund or finance journal
mutation. Agent 5 should reconcile this gate with the independent
TEST-only payout workflow and validate exact-head CI before integration.
