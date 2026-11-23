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


### Exact hosted Flutter failure repair (real macOS/Ubuntu jobs)

The native CI run against the preceding branch head
`79e413f1db62db1bbdb5b0f8a41e668e4e051a33` completed **red**
on both Ubuntu and macOS despite a successful analyzer. The downloaded
raw job logs identified **two** defects:

1. The new forged-pagination widget test deliberately assigned
   `result.total=300`, but its fake `BillingApi.request` incorrectly
   overwrote the injected total with `2` before the app saw it.
   The corrected fixture preserves the source-provided total and last
   page for nonempty reports; production source verifier continues
   rejecting mismatched page geometry. This fixes the test, not the
   security requirement.
2. An old account session could expire while a partner JSON/PDF
   request was pending. Its `finally` used the now-false old auth
   fence to decide whether to stop a spinner; the UI remained in
   infinite loading and `pumpAndSettle` timed out. The corrected
   source state distinguishes the **same current view generation with
   revoked credentials** from a later account/view generation.
   Only the former is repainted with spinner stopped and private
   invoice data cleared; the latter is left untouched. This corrects
   an actual native privacy/lifecycle bug.

No provider payment, settlement, consent, private raw token or document
generation behavior changed. The new branch head requires a completely
new full Native/Platform CI pass; a prior red run is not acceptance.


## Immediate native account logout after a CDR invoice was rendered

An already displayed partner invoice and TEST CDR finance PDF link
could otherwise remain visible after a separate account logout
because Flutter did not necessarily rebuild when the API's old
session fence became false. The shared additive, non-secret
`EvApi.sessionEvents` stream now emits synchronously on every
credential generation change, and the native
`PartnerBillingPage` listens to erase the formerly authorized
provider invoice, export authority, filter drafts, and pending
spinner immediately. An old HTTP or PDF response can no longer
re-populate that generation; no new request is sent.

A new Flutter widget regression lets a partner invoice finish
painting, then calls `forgetSession()` without a second HTTP
response or synthetic widget rebuild, and checks invoice number
and private PDF action disappear immediately. The identical
additive API generation notifier also exists on Agent3 PRs
#236, #243, #265 and #272; integration owner Agent5 should
apply this nonsecret signal **once** and keep the distinct
domain listeners. Exact Native macOS/Ubuntu and Laravel/OCPI
combined CI are still required; this is not a live partner
settlement or a proof of PSP/bank remittance.


### Already-open partner CDR reconciliation private PDF route

An already-pushed private PDF must not continue rendering after logout
when its parent financial report clears. This branch now carries the
**byte-identical optional** `VendorDocumentPreview.revokedSessionEvents`
implementation from Agent 3 PR #272. The native report passes the
current `EvApi.sessionEvents` to its viewer. A token-free account
invalidation immediately hides the PDF/image renderer, evicts any
preview image cache and closes the route on the next frame. The
shared component's already-open-route widget regression lives in
PR #272; Agent 5 should deduplicate this identical implementation
once during integration. Existing private report amount/source
tests and full Native/Platform CI must pass on this branch's exact
HEAD. This does not certify bank, PSP, grid, charger or roaming data.
