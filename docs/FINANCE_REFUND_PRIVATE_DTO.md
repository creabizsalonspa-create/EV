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
