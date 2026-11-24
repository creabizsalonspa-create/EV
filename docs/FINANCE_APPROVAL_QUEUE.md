# Finance Approval Queue

GET /finance/approval-queue is an authenticated Admin-only read-only aggregation of three canonical financial review sources: pending customer refund requests joined to their invoice, pending vendor settlement requests, and vendor adjustments without recorded approval. Records carry kind, source ID, vendor, invoice ID where applicable, currency, minor precision captured from each immutable invoice, settlement or adjustment, signed minor amount, status, creation time. No customer identity, bank/provider references, private evidence, reason, or request keys are exposed.

Query filters: kind=all|refund|settlement|adjustment; strict uppercase three-letter currency; positive vendor_id of 1-18 digits; page 1-10000; per_page 1-25. SQL UNION ALL paginated by date, ID and kind; no entire-history PHP materialization. Inherits both cookie and native authorization boundaries.

No money is moved or posted. No approval, refund, dispute resolution, hold release or payout operation is performed. Does not grant authority to approve. This is a simulated-only review aid, partial toward requirement 4.3.9; production and full mobile/Arabic supervisor experiences remain outstanding.

## English/Arabic web review and tests

The authenticated commercial-finance workspace adds an Admin-only, lazy "Load pending finance reviews" control. It renders separate refund, settlement and adjustment entries with minor-unit amounts and explicit simulator-only/never-payout labels. It offers no approval/refund/payout action. A kind selector and bounded previous/next pagination never mutate the original workflow. The UI clears private rows on filter changes, tab hiding, account changes, and discards stale in-flight responses.

FinanceApprovalQueueTest exercises true canonical source rows, negative pending adjustments, approved adjustments excluded, status-specific kinds, currency/vendor/pagination bounds, customer/vendor rejection, disallowed Admin personal access tokens and no new journal or settlement events. The real-Laravel financial analytics browser test confirms that a customer has no queue button and Admin can load a read-only bounded queue without mutation controls.

## CSV export

Admin-only `/finance/approval-queue/export` reuses the exact JSON permission, kind/currency/vendor validation and bounded **current page only**; it never broadens to all pending history. UTF-8 numeric IDs, ISO creation timestamps, exact signed minor units and canonical source status are exported as RFC CSV with backslash escaping disabled. Customer names, private reasons, payment secrets and provider identities remain excluded. Header `X-Content-Type-Options: nosniff` and private/no-store cache controls. No account balance or workflow write occurs.

After Admin-only source validation, the bilingual web view provides a same-origin CSV download link for the **currently visible filter and page**, rather than all outstanding financial reviews. Logout/visibility/filter changes remove the link alongside its private rows. The download has its own identical server-side authorization; the UI link alone is not an access grant.

## Revoked reviewer protection (Agent 3 continuation)

The read-only Admin pending-finance review and its one-page CSV now share the same transaction-scoped `CurrentFinanceReader` guard used by the reconciliation audit. Historical role snapshots are never enough: current persisted active Admin role, unchanged password and epoch must be confirmed before private pending refund, settlement or adjustment rows are returned. Four revoked-session scenarios and unchanged canonical approval/ledger state are covered by added Laravel tests. No adjustment approval or real transfer was enabled. Exact-head validation and Agent 5 integration remain open.


## Source-exact pending amount review (Agent 3 continuation)

The administrative queue and its all-filtered grouped summary now share an
explicit signed minor-unit source validator. It rejects PHP floats/booleans,
fractional/exponent strings and values outside ±(2^53−1) **before**
converting money or request counts to browser/native JSON. Group counts must
also remain nonnegative. Previously, whole-valued PHP floats and `true`
could be interpreted as integer amounts by `FILTER_VALIDATE_INT`,
making an inexact source appear legitimate. All existing kind/currency/
precision-separated full filtered summaries, private page export and
source-only status flags remain unchanged.

`FinanceApprovalQueueTest` adds a direct exact-source regression plus
a genuine three-category Admin request with the full filter summary on
page 2; no refund, approval, settlement, external PSP call or canonical
financial journal is created by read. This remains read-only simulated
triage, not banking reconciliation or final finance approval governance.
Exact-head CI and Agent 5 integration remain pending.

## Web review filter and atomic private-page acceptance

The Admin browser now supports optional currency and vendor filters alongside kind. The exact applied query is used for list, pagination and one-page CSV. Editing filters clears previous private records and links; hiding the page also clears filter values. The client validates returned pagination, unique typed row identities, filtered vendor/currency/kind, immutable invoice linkage and source-group counts before committing any new private DOM. No approval or payment action is added. The real browser acceptance test exercises scoped list/CSV and a forged page response; existing Laravel access checks remain authoritative.

## Whole-filter source bounds

Before returning a grouped review or its single-page CSV, the controller checks all filtered source rows for unsafe signed amounts, invalid source identities and missing or out-of-range precision. Opposite invalid signed values cannot cancel into an apparently exact aggregate even when both are outside the visible page. SQLite REAL-affinity source amounts/precision are rejected, and group counts must reconcile with the requested pagination count. Unknown precision is rejected instead of silently becoming zero decimal places. This is read-only source validation; no historical source or journal is modified.
