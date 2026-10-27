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
| `GET /commercial/settlements` | Vendor sees only own records; admin sees all; paginated |
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
