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

### Settlement and subscription-credit authority (Agent 3)

The canonical `SettlementController` now checks the persisted current Vendor/Admin credential epoch, password, role and active status inside transactions for settlement list/statement/detail, drafts, Admin schedules, simulated transitions, adjustment requests and dual-control approvals. Read-only responses use private no-store. Tests revoke an in-memory authenticated Vendor/Admin account and verify previously drafted settlement state, events and financial journals cannot change.

The subscription-credit test-mode refund API now uses the same current-actor guard for private credit lists/detail and before invoking the refund service. Importantly, the refund service independently revalidates and locks the **same typed actor snapshot inside its durable request-creation transaction and again before provider refund I/O**. The controller does not hold one giant transaction around external provider I/O. A revoked Admin cannot create a refund request, replay it as an authorized refund, or read another user's credit data. Tests use a valid mock credit, then suspend/demote/rotate the Admin and assert zero additional credits, durable requests or ledger postings. Real live-mode refunds remain disabled and external receipt/reconciliation is unaccepted.

All additional tests are authored **not yet demonstrated passing** on this exact source. Agent 5 integration must retain signed webhook behavior, refund idempotency, uncertainty recovery and the one canonical ledger, and rerun the original payment/refund/PostgreSQL/browser gates.

### Private payout, prepaid campaigns and corporate billing controls

The existing payout account directory, simulated payout history and single-payout details now preserve Vendor/Admin tenant ownership and disclosure boundaries against suspended, demoted, password-rotated or epoch-revoked cached identities. Payout mutation routes additionally revalidate the live actor before handing off to their original source-bound provider and lease/idempotency services; these short controller checks are **not** proof of immunity to mid-flight revocation during external provider I/O. The existing provider service, maker/checker policy, immutable financial events and real_money_moved=false disclosures remain authoritative. Regression tests cover recipient creation and payout approvals after explicit account revocation, plus private account and payout read isolation.

The already-present prepaid marketing-campaign backend now guards its private list, Vendor station inventory, package entitlement reports and campaign-detail/history inside current-account read transactions. Campaign review actions maintain the existing HTTP 404 non-enumeration convention after reviewer demotion while retaining the service's own locked current-actor and immutable campaign-revision checks. A regression verifies stale Vendor and Admin roles cannot view their former private campaigns, package evidence or change campaign state; public sponsored placements remain source-labelled, and no second advertising ledger or extra cash capture was introduced.

Corporate fleet agreement lists, private claims, consolidated statements, statement CSV and **mock-only** settlement now revalidate and transaction-pin persisted Customer/Fleet Owner or Admin accounts before returning any financial data or invoking the canonical simulated collection. Existing fleet membership, credit-limit, immutable contract, idempotency and exact invoice payment checks remain in CorporateBilling; no live corporate credit agreement or legal tax invoice is activated. Regression verifies revoked owners/Admin cannot read private agreements, claims, statements, CSV or generate new simulated receipts or finance journals.

All above are **committed source and authored regressions**, not proof of passing exact-head CI or of complete original 312-atom acceptance. Finance, Vendor, Admin and Flutter combined-tree review remains Agent 5's merge gate. Production payments, actual bank payout reconciliation, legally enforceable corporate credit and regulatory wallet acceptance are expressly outside simulated readiness.

### Payment uncertainty recovery: private reviewer authority

Existing hosted TEST payment-recovery intent history and event pages now revalidate and lock the current persisted Customer, Vendor or Admin account for every private query and redacted event pagination. A previously privileged role, revoked epoch, rotated password or suspended account cannot continue reading provider uncertainty details from a stale browser session. The explicit read-only reconciliation route checks current tenant ownership in a short transaction *before* contacting the existing provider-bound reconciliation service, without holding a database transaction over provider network I/O. Its original signed evidence, durable lease, no-duplicate-capture and uncertainty semantics remain unchanged. Regression tests create a real durable simulated unknown outcome, revoke each reader without changing the cached session object, and verify list/event/reconcile denial with no fabricated cash posting. A role revocation arriving after the short preflight check but during provider I/O remains a separate concurrency case requiring combined recovery-suite acceptance; no production PSP or bank acceptance is inferred.

### Private dispute evidence and subscription checkout identity guards

Existing hosted Stripe TEST external-dispute list/detail/event and Admin-only evidence/retry/reconciliation routes now check the persisted active Customer/Vendor/Admin identity before disclosure and before provider review. Read-only listing/detail data is fully materialized within an account-row-locked transaction; provider I/O remains outside controller transactions and retains the canonical durable uncertainty/retry service. Added regressions demonstrate that a stale demoted/suspended/epoch-rotated/password-rotated reader cannot see private dispute evidence or submit test-provider actions. Latest integrated main also has the separate Admin dispute-deadline queue from PR #153, absent on this branch's older base; **Agent 5 must preserve that route/controller and adapt its Admin read to the same live-authority guard** when resolving source conflicts.

Encrypted internal payment-case evidence list, upload and direct download now revalidate the current tenant against the canonical invoice and gateway case while the persisted user row is locked. Evidence ciphertext is decrypted and integrity-checked only inside a scoped transaction, returned strictly as a sandboxed attachment; no file/metadata disclosure on stale account authority. Upload uses a short pre-validation scoped check plus its existing durable transaction lock and preserves ciphertext/orphan-retention policy. Regression tests exercise all four revocation conditions for Customer, Vendor and Admin without changing finance journals, document counts or audit events.

The existing subscription TEST gateway checkout listing and reconciliation reads are now current-account scoped; new checkout, authorize/capture/cancel entrypoints revalidate the live owner before any private validation or provider action. Service-side durable checkout, operation and scheduled authenticate transactions independently compare the original credential snapshot against the persisted user under a row lock, preserving the canonical payment-recovery lease and never sending funds from test-only code. Regressions cover a revoked Customer or formerly privileged Admin across checkout history, collection/reconciliation and authorize instructions. These protections are **authored code/tests**, not yet accepted external PSP evidence, and all new exact-head CI and integrated tree security gates remain mandatory.

### Agent 3 continuation: checkout, corporate-hosted, invoice and reminder privacy

Charging **mock / Stripe TEST** checkout history, locally created dispute history and test operation entrypoints now validate current persisted Customer/Vendor/Admin authority in a short transaction before using their existing canonical gateway/recovery service. No controller transaction spans a provider action, no second payment ledger was introduced and gateway webhooks retain existing signature checks. Revoked-user HTTP regressions assert no fresh checkout, provider operation or financial journal.

Hosted corporate statement collection now rechecks the current payer or Admin for private index, single checkout, original payer reference, bounded events and all test-mode operation entrypoints. Private responses are fully materialized with the account row lock, while approved test-provider calls retain their original durable lease/recovery transactions without a wrapper transaction. Added mock-hosted regression protecting future checkouts and collection decisions from stale roles, suspension, credential rotation and auth-epoch revocation.

Subscription dunning notice listings likewise use the persisted actor in a bounded transaction, keeping in-app reminder totals and attention stages private. A revoked Customer or Admin cannot enumerate old billing notices.

Canonical finance invoice history, individual invoice detail, invoice source/credit evidence, read-only journal history, Vendor pricing and station inventory have now been moved to private current-account transactions. Tariff publication additionally rechecks current role inside the serialized station write; mock payment/refund and OCPI test unpaid-credit actions precheck current actor before invoking the original finance service. Regressions assert no unauthorized payment/refund or invoice journal changes.

**CI defect triage** on earlier exact PR #132 source run `43792471636` finance-postgres job `133053821746`: 423 test cases, **3 failed**, 1 skipped. Two CSV export tests received browser 302 instead of expected API 422 for invalid plain GET filters; `FinanceApprovalQueueController::export` and `FinanceReconciliationAuditController::export` now return explicit privacy-safe JSON 422 from the same validated private page. Another payout test expected 403 for current Admin attempting Vendor consent, but newly added persisted Vendor guard returned 409; service now preserves 403 for a non-Vendor while continuing to return 409 for suspended/stale owner. The remaining finance test cases in that historical run passed. These fixes **are not yet independently green on the latest head**; use only fresh CI and Agent 5's combined-tree gates for completion claims.

Historical PR #135 Platform failed on local demo charging-review moderation with `sqlite_locked` after 1313 Laravel tests passed and many browser cases, not on KYC tests. A rerun was requested for its precise failed job. PR #150 Platform Arabic connector-fleet timeout was also rerun; neither is authoritative until the rerun completes. PR #164 already passed all source-branch Platform and Finance CI but has not been integrated by Agent 5.

No production merchant activation, live payout dispatch, production customer wallet, bank/PSP acceptance or live charging operation is asserted by this documentation.


## Durable lease access and native readout — 19 November 2026

Gateway authorization preflight now reads current ownership/status without invoice/account row locks. Its transaction grants no durable authority: the operation still locks invoice then actor and rechecks within its mutation. This allows an overlapping Stripe TEST request to reach the existing execution lease and immediately return409 while the first provider operation is in flight. The original two-process release barrier, crash test, provider idempotency key, single-capture/journal and balance checks are unchanged and pass. No financial arithmetic or provider mode changed.

Flutter's Vendor overview now consumes itemized earnings via the existing server endpoint, with original invoice provenance and no locally computed settlement. Its explicit all-invoice overall-scope label, EN/AR pagination, signed precision and current-account/foreground guards are tested. Full financial mobile scope and external bank/PSP acceptance remain uncredited.


## Agent 3 — exact vendor earnings/CSV hardening (23 November 2026)

The existing own-vendor/Admin-only, ledger-derived earnings JSON and CSV use
the **same explicit page bound (1–10,000) and per-page bound (1–25)**. Invalid
pages return HTTP 422 to normal browser CSV requests as well as JSON callers,
instead of following implicit paginator fallbacks or form redirects.
The export contains only the validated current page, including empty pages.

Amounts on the issued invoice, payment/refund/credit projections, settlement
reservation rows, per-invoice availability and overall availability must fit
exact signed or nonnegative JavaScript-safe integer bounds (2^53−1).
Out-of-range or unrepresentable historical source values fail closed as 409;
the report must never silently cast or round a large PostgreSQL aggregate.
Refund debt remains signed and is not falsely turned into a positive payout.

Provider/issuer invoice numbers are left intact in canonical JSON and database
records but prefixed as literal cells on CSV export if they could be
interpreted as spreadsheet formulas. CSV escapes RFC quotes without treating
backslashes as an escape operator; responses remain private/no-store and
nosniff. Tests cover the original ledger and both invalid-page transports.
The inexact-amount and spreadsheet-injection primitives are exercised
directly using reflection **without updating immutable issued invoices**:
canonical invoice UPDATE/DELETE triggers remain enabled in SQLite and
PostgreSQL. The same tests prove ordinary JSON/CSV work with those immutable
originals and leave journals unchanged. No source or financial ledger
mutation occurs, no bank settlement is verified, and original requirement
`3.5.9` remains uncredited pending Agent 5 integration and full cross-platform
acceptance.

### Immutable-source reconciliation CSV safety

The separate `finance/reconciliation-audit/export` one-page CSV now marks
formula-leading invoice references as literal spreadsheet cells while
retaining their exact immutable original in source JSON/DB. CSV encoding uses
explicit RFC quote and empty escape parameters; `nosniff` and
`private, no-store` remain enforced. This prevents attacker-controlled
imported invoice references from becoming formulas on an auditor's desktop
without rewriting immutable finance tables. The feature regression checks
unsafe leading `=,+,-,@` (including control-prefixes), validates the real
CSV response and unchanged canonical journals. This remains a read-only
diagnostic, not verified PSP/bank reconciliation or an approval authority.

## Native internal reconciliation workspace

Vendor finance now links each currency to a bilingual read-only internal invoice audit, using the existing bounded tenant-scoped API. The native view supports optional ISO date filters, exact paging, immutable money and finding validation, read-only diagnostic labels, and current-account/foreground clearing. It never creates a financial operation. Native CSV parity, physical bank/PSP verification and complete requirement 3.5.9 / 7.3.8 acceptance remain outside this batch. Added parser and widget tests; exact-head hosted CI required.

### Native private reconciliation CSV

The Flutter audit now requests the existing server export with exactly the displayed filter/page. CSV is limited to 64 KiB, parsed with the existing bounded finance CSV parser and verified cell-for-cell against the immutable accepted audit rows, including signed amounts, issues and spreadsheet-literal invoice references. The preview is memory-only, never stored or automatically retried; background, scope/credential changes and mismatched source erase it. External PSP/bank acceptance remains unavailable. Added verifier and widget regressions; fresh exact-head CI required.
