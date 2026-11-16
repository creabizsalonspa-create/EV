# Canonical invoice reconciliation audit

Two authenticated read-only routes add source-integrity reporting without introducing another financial ledger:

- GET /finance/reconciliation-audit
- GET /finance/reconciliation-audit/export

Access is administrator (all) or vendor (own invoices); customers are forbidden. Filters: currency, from, to, per_page (1–25), page (1–10,000). Invalid pages return 422 for both JSON and CSV; the export reuses the exact filtered current page, including empty pages. Exports contain exactly the selected bounded page, not unbounded private data. Existing authenticated API parents, request throttles, and web CSRF protections remain in effect. Responses use private/no-store.

The audit checks invoice component arithmetic, issuance journal, payment/refund/noncash-credit journal linkage, refund allocation, journal currency, per-journal debit/credit equality and exact canonical account allocations, orphan records and collection limits. Anomaly codes are read-only and never repair or modify immutable accounting. Re-run after concurrent operations settle: this is a diagnostic, not a formally frozen accounting close.

Source evidence is SIMULATED_CANONICAL_LEDGER and SIMULATED_INTERNAL_RECONCILIATION. The explicit external_psp_or_bank_verified=false field must be preserved. No live payment processing, bank settlement verification, external PSP reconciliation, legal tax invoices, or wallet authority is inferred.

Feature tests: FinanceReconciliationAuditTest. Run the full financial Laravel/PostgreSQL/concurrency suites and exact-commit hosted CI before merging. This contributes only a partial slice toward 3.5.9 and 7.3.8; neither original requirement is fully delivered by an internal source-integrity audit.

## Vendor earnings and source-backed settlement statements

Authenticated `GET /commercial/vendors/{vendor}/earnings?currency=AED` and `GET /commercial/vendors/{vendor}/earnings/export?currency=AED` expose own-vendor/Admin-only paginated per-invoice earnings source records (1–25 items, optional date filters). Each row identifies original invoice allocation, captured/refunded and noncash credits, pending versus completed settlement reservations, and invoice availability taken **only** from `SettlementService::balances`, including held amounts and carried-forward post-payout refund debt. The separate `overall_available_minor` deliberately covers all invoices and approved adjustments, not the filtered page. CSV exports exactly the requested scoped page. All results are no-store, integer minor-unit, simulated evidence; never bank receipts, payout instructions, or legal invoices. No financial or vendor-settlement records are mutated. Web/native consumers must display the simulation and precision flags. This is a meaningful reporting backend slice of 3.5.9; complete financial UX/reporting remains uncredited until cross-platform acceptance.

## Web integration and end-to-end access

The existing authenticated, bilingual finance workspace now offers read-only itemized vendor earnings and canonical ledger integrity views, with bounded pagination, a clear simulated-only disclaimer, private server-generated CSV download links, literal text node rendering and lifecycle-aware request cancellation. Administrators are explicitly told their integrity query covers all invoices they may access in the selected currency, while vendor views remain tenant-scoped. The real Playwright financial-analytics browser flow checks authenticated report rows, CSV export, internal audit, and forbidden foreign-vendor access. This does not enable payment or constitute external bank/PSP verification.

## Current-account protection (Agent 3 continuation)

The private reconciliation JSON and bounded CSV now use a shared `CurrentFinanceReader` guard, which rechecks the persisted active role, password hash and authentication epoch against the request snapshot under a transaction-scoped `users` row lock. Vendor and Admin finance reads are fully materialized while the lock is held. Stale cached requests after role changes, suspension, password rotation or epoch revocation fail HTTP 409 before returning cross-tenant invoices or CSV. A dedicated regression covers all four revocation states for both owner and Admin, with no finance journal mutation. The helper is a **read guard only** and cannot trigger refund, payout, capture or banking actions. Exact-head platform/PostgreSQL/browser validation and Agent 5 merge approval remain required.

## Broader canonical finance account guards (Agent 3 second batch)

The shared current-account guard also protects existing grouped `/finance/report` and private refund-request listing reads, and revalidates the persisted account **inside** the refund submission and vendor/Admin refund-review transactions. Requests can no longer rely only on a route-bound invoice or cached user role after suspension, password change or authentication-epoch rotation; the current authenticated user row is locked first, then the canonical invoice and workflow rows. The invoice's current customer owner is rechecked before reservation. Existing idempotency keys, OCPI credit source requirements, mock-only refund execution and journal entries are unchanged. Grouped finance JSON now has a private no-store cache policy.

Added regressions hold a stale Admin snapshot through role/suspension/credential changes, exercise private finance report, private request history and attempted refund approval, and ensure no ledger/audit/payment mutation. Another test verifies a suspended Customer cannot open a second refund request. Exact-head Laravel/PostgreSQL/browser tests and integration approval remain pending; these source changes are not production PSP refund evidence.

### Full financial analytics export and PDF authority

The existing authenticated `/api/financial/analytics` JSON/CSV/Arabic-PDF controller now constructs each complete private report inside the same live-account row-locked transaction, using the shared current Vendor/Customer/Admin role, account status, credential hash and auth epoch guard. PDF rendering takes place from a fully authorized in-memory report after the transaction; it does not execute a new cross-tenant database query. Regression cases revoke all three actor roles while cached, exercise all three output surfaces and check that no financial journals change. This is privacy/authorization hardening; it does not change charge amounts, create legal tax invoices or enable external payments. Re-run the full financial analytics and PDF suite on the combined exact head.

### Vendor operational-finance and export privacy

Existing Vendor operational analytics (mapped EVSE utilization, source-reported energy/demand, separately scoped simulated invoice facts and unknown-status intervals) now pins both the current account and current company rows during the complete report. Scoped staff membership is revalidated and can no longer be bypassed by stale Vendor/Admin session role/status/password/epoch or a stale route-bound company model. The same guard applies to JSON, CSV and PDF; PDF rendering operates on the authorized report after the transaction. Regression coverage checks revoked sessions, all three output formats, the explicit not-physical-uptime flag and unchanged finance journals. This does not claim actual charger uptime, external network health or commercial payout eligibility.
