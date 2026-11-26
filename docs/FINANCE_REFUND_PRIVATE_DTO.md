# Private refund request DTOs and live authorization order

**Agent 3 — partial commercial finance/privacy scope 2.8.8, 3.5.6, 4.3.9.**

The existing `finance_refund_requests.request_key` holds a
customer-provided idempotency/replay nonce in the canonical database.
For retry safety it must be checked against the original request even
after a mock provider refund or rejection. It is **not** a financial
field customers, vendors or Admin reviewers need in an API response.

Previously the refund-request creation, idempotent replay, pagination
and vendor/Admin approve/reject endpoints returned full raw table
records, including the nonce. An acquired financial export or client
log could therefore reveal customer-supplied private replay keys.

The `CommercialFinanceController` now projects a common privacy-safe
refund record from **every** response path, omitting `request_key`
and any future `fingerprint` while retaining the immutable invoice
identity, customer, amount, reason, source-credit lineage, review
status, note, actor, timestamps and financial refund identifier.
Sensitive successful POST DTOs and replays explicitly use
`Cache-Control: private, no-store`.

The original replay key is still persisted and compared in the
database. A repeat identical request returns the same ID without an
extra refund; a changed amount/reason remains 409. No finance
invoice/payment/refund journal is modified by the DTO projection.

Refund creation now checks persisted current account identity and
locks/rechecks the original invoice owner **before** parsing the
private amount and credit/source filters, so a rotated/suspended
customer cannot use an invalid refund form to bypass live authority.
Refund review likewise checks current Admin/vendor account, locks
the source invoice and enforces original reviewer scope **before**
parsing the requested decision or note. An unrelated reviewer
receives non-enumerating 404 even for invalid form contents; stale
reviewer credentials receive 409. Existing provider-guarded
simulation remains unchanged: a non-mock PSP invoice cannot receive
ledger-only simulated refund approval.

Real Laravel feature regressions cover customer creation and replay,
vendor and Admin paginated lists, initial/repeat approval, identical
canonical stored replay nonce, exactly one simulated provider refund,
private/no-store responses and authorization precedence for invalid
forms. No real bank/PSP refund/settlement, regulated wallet, or live
production acceptance is implied.

This work is bundled in Agent 3's existing **PR #248 private finance DTO**
rather than creating a separate small PR. Agent 5 alone integrates
after exact-head Laravel/PostgreSQL/finance/browser/native gates;
do not credit an original requirement without full evidence.


## Finance report numeric source integrity

The existing multi-currency `GET /api/finance/report` and
`GET /api/v1/finance/report` share
`CommercialFinanceController::currentReport`. PostgreSQL can return
aggregate `SUM()` as numeric strings, and PHP 64-bit integers can
hold values above the exact 2^53−1 limit of clients using JavaScript
number semantics. Previously the report accepted these values, so
customer, vendor or Admin reports could display **rounded financial
amounts despite an intact database**.

Every server-returned nonnegative invoice count/amount aggregate is
now parsed and capped at `9,007,199,254,740,991` before JSON
serialization. Negative values, fractional/invalid numeric strings
and over-range totals produce HTTP 409 with a request to narrow the
period; signed net values are differences of exact bounded operands.
The existing currency + precision group isolation, canonical invoice
and payment/refund allocations and customer-vendor-Admin field
permissions remain unchanged. A dedicated pure regression tests zero,
the exact upper bound, the first overflowing value, fractions,
negative numbers and large out-of-range numeric strings. This is a
source-accuracy safeguard, **not** independent provider settlement
or confirmation of real collected funds.


## Subscription credit Admin refund authority

`SubscriptionCreditController::refund` used to validate private
invoice, amount, reason and idempotency form fields **before** checking
whether a persisted Admin account was still active and genuinely allowed
to inspect the target subscription. Revoked Admin credentials could
therefore receive 422 for an invalid private refund form instead of
being denied, disclosing policy shape and potentially the distinction
between a real and unknown subscription.

The endpoint now checks the *current locked account* and Admin role
and verifies the target subscription exists before validation, under a
short database transaction; it then exits the transaction **before**
calling the durable `SubscriptionCreditService::refund`. That service
already performs independent account validation and provider-safe
transaction boundaries, which are unchanged. Successful sandbox DTOs
return private/no-store. The new feature regression tests valid Admin
invalid-form 422, nonexistent subscription 404, revoked role/status/
auth epoch/password 409 (even for malformed forms), unchanged
subscription ledger and credit/request counts, and zero unexpected
external HTTP. This is an **authorization and DTO** improvement, not
real provider refund certification.


## Admin pending finance reviews — grouped exact source across all pages

The existing read-only private Admin `GET /finance/approval-queue`
now includes a source-generated `summary` in addition to its
unchanged 1–25 item details page. The original queue's three
independent sources remain the only authorities: pending customer
refund requests, pending vendor settlement batches, and unapproved
adjustments. Each bucket is separated by **kind, currency and minor
units** and has a full-filter `review_count` plus a signed integer
`signed_amount_minor`. It is never called a balance, bank deposit,
payout obligation, or approval authorization; no currencies or
precision groups are summed together, and a negative adjustment can
never be silently netted against a pending customer refund.

Summary scope is `ALL_CURRENT_FILTERED_PENDING_RECORDS_NOT_PAGE`
and explicitly marked `not_a_payment_or_payout_authority=true`.
All counts and amounts must fit exact 2^53−1 bounds for browser
serialization. More than 200 distinct filtered review scopes fails
with a request to narrow filters rather than silently truncating
amounts. Admin's current persisted role/status/auth-epoch/password
lock remains held throughout the read. The one-page CSV remains
exactly one detail page; it does not invent a payout file.

The web Admin `commercial.js` financial review section now shows
an English/Arabic read-only grouped source card before individual
items. It verifies full source schema, full filtered count against
the sum of distinct review groups, numeric exactness, precision,
kind and currency and rejects duplicate group identities. A
customer note, replay secret, PSP token or signed provider
credential is never included in either API or displayed text.
The app computes no settlement eligibility and does not add a
mutation button.

`FinanceApprovalQueueTest` checks the live synthetic pending
refund 25 AED minor, vendor settlement 60 AED minor, adjustment
−10 AED minor as three separate exact groups even on page 2
with one row, kind/currency/vendor filtering, unchanged immutable
finance journals and zero approval/refund/payout writes.
`tests/commercial-workspace.cjs` now performs a **real Chromium
browser + Laravel** Admin review after a customer submits a
synthetic pending refund, confirms the displayed category/currency
totals are exactly the server-source groups, and confirms the
private customer reason is not displayed. No real bank or PSP
reconciliation is implied.
