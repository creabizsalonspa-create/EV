# Partner CDR finance reconciliation — current authority boundary

**Agent 3 / original requirement 7.3.7, partial software candidate.**

The existing authenticated OCPI provider billing reconciliation JSON
(`GET /api/ocpi/providers/{provider}/billing-reconciliation`) and both
`/csv` and `/pdf` exports contain canonical private invoice number,
invoice amount, recorded payment/refund/noncash credits, review status and
sensitive source lineage. A provider record being visible alone never
confers cross-vendor invoice access.

All three read paths now:

- Require the request's original signed-in Vendor or Admin snapshot to
  match the **persisted active account**: current role, auth epoch, and
  password hash, under a transaction-held account lock.
- Reload the **current provider ownership** under a subsequent provider
  row lock before reading any CDR or invoice data. A former provider owner
  receives HTTP 404 even if the route-bound model still contains their ID.
- Hold those locks through materialization of the private JSON/CSV/PDF
  source report. Report rendering occurs after the database transaction
  using only the approved bounded materialized rows (250 maximum for
  export), with no new external payment or provider requests.
- Maintain the existing 25-row page, original source evidence semantics,
  numeric minor-unit precision, safe spreadsheet CSV and bilingual PDF,
  private/no-store cache policy and original authorization/review roles.

Regressions simulate a stale in-memory Laravel session after Vendor or
Admin role revocation, suspension, password change and authentication epoch
rotation. All three financial reads return 409. A current user who loses
provider ownership receives 404 for all three, while the new owner may
only see `scope_requires_review` for a prior vendor's invoices rather
than inheriting their financial material. The original immutable finance
journals and OCPI source receipts remain untouched.

This does **not** prove independent partner billing, captured PSP/bank
funds, legally enforceable CDR settlement, or production acceptance.
Agent 2 should review the shared OCPI controller; Agent 5 alone integrates
after the exact-source backend, PostgreSQL, security, and combined gates.
No new completion credit should be assigned before validation.
