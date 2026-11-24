# Native Admin finance approval review

## Scope

This adds a read-only English/Arabic Flutter workspace for the existing
authenticated `GET /finance/approval-queue` endpoint, using its canonical
pending refund, settlement and adjustment sources. The account menu displays
the workspace only for an administrator. Before every page request, the client
rechecks the current authenticated `me` role and positive exact account ID;
the server remains the authoritative account/role and tenant boundary.

The client accepts only the explicit TEST read-only/no-money/no-authority
response contract. It verifies the requested page, per-page size, total count,
row count, positive source identities, source-specific statuses, canonical
currency, precision and signed adjustment amounts within 2^53−1. Each row
contains only known public-within-Admin review fields. Duplicate source IDs
within the same kind, private extra fields, unexpected currency/vendor/kind,
and incoherent pagination fail closed. Money is displayed exactly in the
source currency with its recorded precision. Missing precision is clearly
marked as raw minor units, not guessed.

The immutable accepted row objects cannot be rewritten by transport maps.
The view applies bounded kind, optional currency and vendor ID filters.
Previous/next reads use the last applied query; edits discard old private
rows. This is a list of pending records, not a financial balance, and no
cross-currency sum is shown. The native workspace works with both the
canonical queue contract and the additive summary response from pending
PR #248; its summary is not interpreted or displayed.

## Private lifecycle

A synchronous authenticated session-generation event invalidates the open
screen, drops private records and filter inputs, and suppresses every late
read. App backgrounding also clears rows and private filters; returning
to the foreground requires explicit refresh, without automatic requests.
The screen retains the opening API/session identity rather than transferring
authority to a newly authenticated user. Any error is a generic localized
message, never the provider/private diagnostic payload.

Only GET requests are dispatched. There is no approve/reject, payout,
bank, refund, settlement transition or provider mutation action.
No production financial or supervision acceptance is claimed.

## Verification

`apps/mobile/test/finance_approval_queue_test.dart` covers exact negative
three-decimal adjustment display, immutable rows, strict source and page
rejection, Admin-only read dispatch, Arabic dark/RTL presentation,
applied-filter paging, synchronous session invalidation, late response
suppression and foreground refresh behavior. Tests are supplied, not
represented as executed until exact-head Native and Platform hosted
workflows pass.

Partial software toward original requirement 4.3.9 (finance approval
governance); no complete atom or real financial acceptance is claimed.
Agent 5 alone integrates after exact-source and post-merge checks.
Preserve the independent menu additions of other native feature branches.
