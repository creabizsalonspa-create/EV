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

## Vendor Flutter private-read parity

`PartnerBillingPage` uses the existing authenticated API and bilingual
English/Arabic invoice/payout explanation. It now clears the previous
private provider invoice and removes its PDF action as soon as a currency,
country, party or CDR kind filter is edited. It uses the new applied
filters only after an explicit refresh. A response must carry the exact
provider and requested page identity, a bounded page with no more than
25 rows, the existing read-only/no-money source flags, valid CDR identity
fields and exact JavaScript-safe integer minor-unit fields for any
linked canonical invoice. A review-only CDR cannot attach a made-up
invoice to a `not_invoiced` or `source_requires_review` status.

The native view also retains its current account-generation fence and
now refuses to initiate a new read while the app is inactive. It clears
existing source data on backgrounding or API/provider replacement and
drops any late JSON/PDF result from an old generation. Widget tests cover
draft filter invalidation, applied PDF filters, impossible 2^53 financial
amounts, forged pagination, lifecycle cancellation, cross-provider
mismatch and logout. This is no claim of verified CDR cash collection,
live PSP or partner settlement.

The native response contract additionally permits only the known bounded
source-card fields. A peer's raw OCPI CDR `payload`, card/token IDs,
private source credential fields, or unexpected top-level fields cause the
entire report and export affordance to fail closed. These fields are not
stored in the displayed report. A synthetic widget regression injects a
`payload.token` and confirms no invoice card, PDF action or token text
can appear. Backend JSON continues to project only sanitized references.


The native parser also validates the response's `total`, `per_page`,
`last_page` and exact selected-page row count against the canonical
paginator, including empty filter results. It will not display a report
with impossible row totals, an out-of-range page or a forged partial
page that changes which invoices appear in the current financial card.
A widget regression tampers with the reported total and confirms all
private invoice and PDF surfaces fail closed.
