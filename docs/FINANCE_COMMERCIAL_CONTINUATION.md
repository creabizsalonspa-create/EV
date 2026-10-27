# Financial and commercial development

2026-10-27. Dedicated branch `development/finance-commercial` starts from `development/fullstack-continuation` at `940ccbf`. Latest owner finance assignment supersedes older single-agent/account assignments in historical continuation files. Agent 2's branch is not modified.

Current verified milestone: customer refund requests reserve captured funds, replay safely, and permit owning vendor/admin approval or rejection. Approval posts the existing immutable refund/credit note exactly once. Tenant-filtered financial reporting groups currencies and precision separately and distinguishes invoice allocations from settlement eligibility. Two API tests passed (21 assertions).

Existing finance foundation now verified on an actual disposable PostgreSQL database: 43 tests / 5,292 assertions, plus three multiprocess PostgreSQL concurrency tests / 39 assertions. Evidence in `docs/evidence/finance-commercial/baseline-*`. This is mock/simulator accounting, not a live PSP or payout.

In development: provider architecture and signed webhooks; subscription cycles/entitlements; settlement reconciliation; connected customer/vendor/admin web workspace and mobile API contract. No final-completion claim. Next: integrate and test those workflows, extend PostgreSQL coverage, run browser tests and open PR targeting `development/fullstack-continuation`.
