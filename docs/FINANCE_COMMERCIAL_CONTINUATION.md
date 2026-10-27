# Financial and commercial development

2026-10-27. Dedicated branch `development/finance-commercial` starts from `development/fullstack-continuation` at `940ccbf`. Latest owner finance assignment supersedes older single-agent/account assignments in historical continuation files. Agent 2's branch is not modified.

Current verified milestone: customer refund requests reserve captured funds, replay safely, and permit owning vendor/admin approval or rejection. Approval posts the existing immutable refund/credit note exactly once. Tenant-filtered financial reporting groups currencies and precision separately and distinguishes invoice allocations from settlement eligibility. Two API tests passed (21 assertions).

Existing finance foundation now verified on an actual disposable PostgreSQL database: 43 tests / 5,292 assertions, plus three multiprocess PostgreSQL concurrency tests / 39 assertions. Evidence in `docs/evidence/finance-commercial/baseline-*`. This is mock/simulator accounting, not a live PSP or payout.

In development: provider architecture and signed webhooks; subscription cycles/entitlements; settlement reconciliation; connected customer/vendor/admin web workspace and mobile API contract. No final-completion claim. Next: integrate and test those workflows, extend PostgreSQL coverage, run browser tests and open PR targeting `development/fullstack-continuation`.


## Integrated financial milestone

Implemented connected web controls and shared cookie/native-v1 API routes for payments, refunds, subscription plans/cycle management and financial operations. Existing immutable charging invoices/journals remain authoritative; subscriptions have dedicated immutable invoices/receipts/balanced ledger. Simulated settlements reserve earned fully captured funds, account for refunds/negative carry-forward debt, hold periods, schedules and two-administrator adjustments. Currency precision is snapshotted and ambiguous historical precision blocks eligibility.

Executed evidence: complete Laravel 137 tests/6110 assertions; PostgreSQL relevant suite 74 tests/5644 assertions and 13 independent-process races/155 assertions (then targeted source hardening was retested). Browser financial workflow and existing invoice/isolated frontend tests are separately logged. Current persisted evidence supersedes counts when additional checks change them. Stripe HTTP contract tests use Laravel fakes and prevent stray network calls; they are not external PSP sandbox tests.

Outstanding: subscription PSP capture integration, hosted tokenization/SCA, durable outbound uncertainty recovery beyond provider idempotency retention, external provider dispute submission/reconciliation, accounting/tax legal review, advanced reporting/export and commercial package policies, native consuming screens by Agent 2, external merchant sandbox validation and payout-provider approval. Do not call the entire financial workstream complete. No real money or stored-value wallet is enabled.
