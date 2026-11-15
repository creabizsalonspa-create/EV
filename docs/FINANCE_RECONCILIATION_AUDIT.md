# Canonical invoice reconciliation audit

Two authenticated read-only routes add source-integrity reporting without introducing another financial ledger:

- GET /finance/reconciliation-audit
- GET /finance/reconciliation-audit/export

Access is administrator (all) or vendor (own invoices); customers are forbidden. Filters: currency, from, to, per_page (1–25). Exports contain exactly the selected bounded page, not unbounded private data. Existing authenticated API parents, request throttles, and web CSRF protections remain in effect. Responses use private/no-store.

The audit checks invoice component arithmetic, issuance journal, payment/refund/noncash-credit journal linkage, refund allocation, journal currency, per-journal debit/credit equality and exact canonical account allocations, orphan records and collection limits. Anomaly codes are read-only and never repair or modify immutable accounting. Re-run after concurrent operations settle: this is a diagnostic, not a formally frozen accounting close.

Source evidence is SIMULATED_CANONICAL_LEDGER and SIMULATED_INTERNAL_RECONCILIATION. The explicit external_psp_or_bank_verified=false field must be preserved. No live payment processing, bank settlement verification, external PSP reconciliation, legal tax invoices, or wallet authority is inferred.

Feature tests: FinanceReconciliationAuditTest. Run the full financial Laravel/PostgreSQL/concurrency suites and exact-commit hosted CI before merging. This contributes only a partial slice toward 3.5.9 and 7.3.8; neither original requirement is fully delivered by an internal source-integrity audit.

## Vendor earnings and source-backed settlement statements

Authenticated `GET /commercial/vendors/{vendor}/earnings?currency=AED` and `GET /commercial/vendors/{vendor}/earnings/export?currency=AED` expose own-vendor/Admin-only paginated per-invoice earnings source records (1–25 items, optional date filters). Each row identifies original invoice allocation, captured/refunded and noncash credits, pending versus completed settlement reservations, and invoice availability taken **only** from `SettlementService::balances`, including held amounts and carried-forward post-payout refund debt. The separate `overall_available_minor` deliberately covers all invoices and approved adjustments, not the filtered page. CSV exports exactly the requested scoped page. All results are no-store, integer minor-unit, simulated evidence; never bank receipts, payout instructions, or legal invoices. No financial or vendor-settlement records are mutated. Web/native consumers must display the simulation and precision flags. This is a meaningful reporting backend slice of 3.5.9; complete financial UX/reporting remains uncredited until cross-platform acceptance.
