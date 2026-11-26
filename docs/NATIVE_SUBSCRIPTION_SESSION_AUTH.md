# Native subscription financial source authorization and replay privacy

**Agent 3 — original 3.7.4, partial native subscription billing acceptance.**

The canonical Laravel API already implements authenticated customer/vendor
subscription enrollment, a published plan catalog, subscription invoice
summary, current entitlements, next-cycle plan changes, explicit cancellation,
payment recovery, immutable event history and non-production TEST billing.
This change deliberately adds **no new PSP authorization, real recurring
capture, regulated stored value, legal invoice or tax-policy claim**.

## Scope implemented

- `SubscriptionsView` (catalog + owned subscriptions) captures the original
  `EvApi.sessionFence()` before requesting current account identity. It
  rechecks the fence **before the next private request**, after every awaited
  response and before rendering owner/plan data. A revoked login cannot cause
  a private plan fetch for another user. All source lists are erased on
  background, API or selected subscription change, including pending requests.
  Returning to foreground requires an explicit refresh; it never silently
  fetches invoices or resets financial state.
- `SubscriptionDetailView` fences the `me` read, owner-bound subscription
  summary and immutable lifecycle history **separately**. It cannot begin a
  private history fetch if the subscription response belongs to an expired
  account. The private invoice amounts, entitlement claims, next-plan state
  and history are cleared on background or source/API/identity replacement.
- `SubscriptionActionView` captures the original login generation through
  account lookup, owner-summary validation, encrypted saved-operation restore,
  durable idempotency write, HTTP POST and receipt acknowledgement. It is
  invalidated by backgrounding or an API/plan/subscription/action swap.
  The durable operation's `isCurrent` closure now also binds original
  **foreground + screen generation**, not just the still-valid login: a
  backgrounded completed HTTP response cannot mark its encrypted intent
  as acknowledged after the view was invalidated. A dedicated paused-POST
  widget test proves no second POST is sent on resume and the original key
  remains persisted.
  An obsolete request cannot confirm a saved plan change or enroll another
  account. A new login must reopen and deliberately reauthorize its original
  action; no automatic retry or alternate idempotency key is issued.
- The UI **never prints saved pending operation body**, which previously
  risked displaying a raw idempotency nonce in the subscription action screen.
  It shows a bilingual neutral 'pending intent' message while preserving the
  unchanged exact durable operation and same saved nonce for explicit retry.
  Private status/expiration errors from old account requests cannot log out
  a newer account.
- Accepted subscription summaries now recursively freeze invoice line
  metadata, owner identity, plan and entitlements. Mutating the original
  JSON transport object cannot rewrite already accepted customer financial
  evidence or plan amount displayed in native UI.
- Shared `FinancialOperation.confirmed()` now checks session identity before
  and after the secure-storage read and before deleting a successful original
  request. This closes a cross-account window where a late old-account
  acknowledgement could erase the durable replay key that should survive
  an uncertain callback. Does not delete or remap a pending receipt on logout.
  The same helper is used in other financial workflows; Agent 4 should
  review the shared-code effect.

## Tests

- `apps/mobile/test/subscriptions_test.dart` now covers logout while
  requesting `me`, rejection of old detail before initiating private
  history, background private catalog/invoice erasure, explicit refresh,
  revocation during an in-flight POST, hidden replay nonce, and deeply
  immutable accepted subscription invoice/plan/owner evidence.
- `apps/mobile/test/native_financial_operations_test.dart` includes a
  controlled secure-storage pause: account rotation while
  `FinancialOperation.confirmed()` is awaiting a read must return 409
  and preserve the exact old durable key. Once its original user
  reauthenticates, it can be acknowledged without generating a second key.

All assertions are **new source-code regression candidates**. Native
Android/iOS Flutter analyzer, widget suite, device builds, genuine Laravel
backend/PostgreSQL and combined end-to-end acceptance **must run successfully
at this branch's exact HEAD before Agent 5 integration**. The original 3.7.4
is not credited yet. User credentials and production payment/provider data
were not accessed or modified.


### Late source completion and spinner disposal

An earlier draft could reject a late response correctly but still leave
`CircularProgressIndicator` mounted because its `finally` handler
required a now-revoked session fence. All three view classes now
distinguish a **current screen generation with revoked credentials**
from a superseded screen generation: the former always schedules a
privacy-safe repaint, disables the stale spinner, and clears sensitive
plan, invoice, event, pending-action and error fields. The latter does
nothing, preventing an old request from erasing a later account's
legitimate source. No old callback invokes `onExpired` unless the
exception specifically carries current-session expiration evidence.


### Immediate erasure of a completed billing screen on account logout

An external logout can invalidate `EvApi.sessionFence()` without
scheduling a widget repaint. That means the prior native subscription
catalog, private invoice history or a consent/submit button that
finished rendering **before** logout could remain onscreen. The
additive native `EvApi.sessionEvents` stream now synchronously
emits a non-secret generation integer when an authenticated
context is cleared or closed. All three subscription classes
subscribe to the currently selected API and immediately dispose
their old plan, invoice, entitlement, billing-operation display,
consent buttons and spinner on invalidation. They detach listeners
on API replacement and view disposal.

New widget tests sign out after a completed plan list, after a
completed invoice history and while a billing consent form is open,
without an unrelated UI rebuild or second HTTP call. They require
the private source and submit controls to disappear immediately.
Original saved financial-operation nonce and provider request remain
in their **original** encrypted store for explicit owner
reconciliation after uncertain outcomes; none is auto-replayed.

The same **byte-identical additive EvApi session event primitive**
appears in PRs #243 and #272 (and will be reconciled once by
Agent 5), while each domain's own listeners remain specific to its
source data and testable action. These are Flutter security
acceptance improvements; no real bank/PSP, hardware or legal
certification is claimed.


## Accepted catalogue source integrity (24 November 2026)

The native subscription catalogue now **deep-freezes each accepted plan or
subscription list row**, including nested entitlements, before the UI retains
the response. A mutable transport/fixture Map cannot alter the trusted plan
price/ID, audience or displayed benefits after validation, even if a later
Flutter rebuild occurs. The authenticated `me` response requires a
browser-safe positive account ID and either customer or vendor role before
fetching the subscription catalogue.

For every explicit paginated read, the UI also verifies the server's
`current_page` equals the request, `last_page` lies within a bounded
page window, `total` is an exact nonnegative 2^53−1-safe integer,
each catalogue row ID is positive and exact, and a zero-total page
cannot fabricate items. Invalid authority metadata or page/source IDs
fail closed without an enrollment POST. Real Flutter widget regressions
exercise mutable accepted plan/nested entitlement values, forged page,
oversized plan IDs and oversized counts. Existing private source wiping,
manual refresh, no auto-replay and no real payment semantics are unchanged.

The earlier Platform retry for exact head `5fbb66eb` passed the five
underlying Platform jobs but failed the **recovery-acceptance provenance
check** when the same workflow was rerun at attempt 2: it correctly
rejected recovery evidence from attempt 1 (`workflow_run_attempt`
mismatch). That old workflow result cannot be promoted to green by
ignoring the provenance assertion. This newly committed genuine source
change triggers a **new source SHA and first-attempt workflow**. CI must
pass on that exact head and Agent 5 must perform the only canonical merge.


## Exact subscription action identities (additional 24 November source)

The same positive 2^53−1 identity limit now also applies to
`subscriptionSummary` subscription and invoice IDs, as well as
`SubscriptionActionView` plan, subscription and authenticated owner
identities **before** it restores the encrypted replay nonce or offers
a mutation. This closes the previously inconsistent condition where
catalogue IDs were bounded but downstream historical invoice IDs and
direct navigation to `cancel_now`/enroll could still accept an
unrepresentable native/JSON integer. A rejected ID never dispatches an
enrollment, cancellation or plan-change POST and does not acknowledge
an unrelated durable operation.

Added independent non-UI source vectors for fractional, boolean and
greater-than-2^53 invoice/subscription IDs, and Flutter widget
regressions proving that oversized plan/cancellation targets disable
submission without invoking the write endpoint. No new payment flow,
provider integration or financial authority is implied. Fresh exact
head Native/Platform validation and Agent 5 source integration required.


## Private subscription event pagination (24 November 2026)

The subscription detail screen now validates and deep-freezes the
**actual authenticated subscription event history** returned by
`GET /commercial/subscriptions/{id}/events`, in addition to the
previously immutable invoice/plan summary. The backend currently
selects only `id`, `kind`, `data`, `created_at` for each
tenant-filtered event; the client does **not** assume or fabricate a
per-event `subscription_id` that is not part of that response.

The requested `page` must match `current_page`, `last_page` must
be within the bounded client window, `total` an exact nonnegative
JSON-safe count, and no response may contain more than the server's
25-item page cap. Every displayed event must have a positive
browser-safe immutable source ID, a bounded nonempty kind string
and a string timestamp. Invalid event data, pagination or account
metadata clears the whole private summary/history display instead
of mixing plausible invoices with an untrusted events page. The
incoming event's nested `data` is deep-frozen before the state retains
it; it cannot be rewritten by mutable transport references.

Flutter widget regressions exercise a legitimate event with mutable
nested evidence and reject a forged current page, over-range event ID
and over-range count. They assert no subscription/payment mutation
is sent. This is a **read-only session isolation boundary**, not
legal financial, billing collection or historical provider acceptance.
Exact-source Native and Platform validation plus Agent 5-only
integration are required.


## Laravel/native shared page contract (24 November 2026)

Authenticated Laravel `SubscriptionController::plans`,
`SubscriptionController::index` and `SubscriptionController::history`
now enforce `page` as an integer in the closed range 1–100000,
using the **explicit validated page** with all three 25-record
paginators rather than allowing Laravel's generic fallback to silently
reinterpret invalid values. Source identity is always revalidated
**before** pagination validation: a revoked account or non-owning
vendor cannot probe another subscriber's event history merely by
sending a malformed `page` parameter.

`SubscriptionTest` verifies page 1 and an empty page 10000,
rejects zero, negatives, nonnumeric, exponent and overrange pages
for all three real API endpoints, verifies foreign subscription
history still returns 404 before bad-page validation, and confirms
no receipts or extra subscriptions are created by read operations.
The Flutter catalogue/detail page contract independently verifies
the same bounded source and preserves the original private
session-generation fence. This remains TEST/simulator commercial
software; provider collection and external acceptance remain open.


## Frozen consent plan and authoritative receipt lineage

The enrollment/change-plan action now snapshots the selected plan's
`id`, displayed price, metadata and nested entitlements **when the
screen mounts**, rather than retaining a mutable Map passed from a
caller. Every consent label, subscription preview, restored durable
intent comparison and POST body uses that same immutable source
snapshot. Background/logout/account revocation explicitly clears the
snapshot. A mutable source Map can no longer substitute plan 8 after
the operator saw and consented to plan 7.

The shared `subscriptionSummary` also requires the canonical plan
record's exact positive `plan.id` to equal the persisted
`subscription.plan_id`; a server receipt for a different plan
cannot acknowledge/delete the locally saved operation key. Existing
`next_plan_id` scheduling is not confused with the **current**
source plan; historical invoices retain their own immutable plan
versions and are not repriced. Flutter regression tests mutate
the original plan object while the action remains mounted, verify
the original price/ID are still shown and posted, and prove that
a mismatched server plan receipt leaves the exact durable intent
available for explicit review/retry. No new subscription, refund
or provider payment authority is inferred.


## Exact canonical TEST subscription billing arithmetic (Agent 3 continuation)

The Laravel `SubscriptionService` that feeds the native subscription
and billing pages now rejects source minor-unit values that were
coerced from PHP floats or booleans, decimal/exponent strings, or
values outside the signed/unsigned 2^53−1 precision range. Its
read-only `summary()` verifies exact invoice subtotal + tax = total,
exactly sums individual receipt and credit rows with running overflow
checks, verifies that refunded credit never exceeds collected funds,
and validates immutable per-entry ledger debit/credit sums without
trusting SQL/PHP `SUM` followed by an unsafe `(int)` cast.
Valid cancelled, partially credited and fully refunded historical
billing semantics are retained; the software does not interpret a
balanced synthetic ledger as real PSP payment verification.

The source writer now checks each new invoice total before insertion,
checks *all* proposed debit/credit entries and both running posting
totals **before the first journal write**, and rejects negative,
float-origin or over-precision amounts. The same exact-money boundary
protects mock/Stripe-TEST receipt amount capture and provider receipt
replay checks. A damaged test invoice must not be rounded into a
supposedly reconciled provider capture. `SubscriptionTest` exercises
integer and decimal-string source bounds, synthetic 2^53 aggregate
overflow, invalid journal candidates that must post zero entries,
unchanged valid TEST invoice/read behavior, and legitimate mock
receipt creation with balanced journal entries.

No production payment, real settlement authorization, bank payout or
automatic merchant approval is introduced. This is **partial finance
integrity software only**. Exact-head Laravel Finance/Platform/Native
tests and Agent 5 integration are mandatory; do not promote whole
requirements or production acceptance from these focused checks.


## Immutable operation acknowledgement (Agent 3 continuation)

Native enrollment and lifecycle changes previously accepted a plausible current
subscription summary as proof of a particular saved request; subsequent
changes or an unrelated response could otherwise clear its durable key.
The existing canonical subscribe/change methods now attach a private
`operation_receipt` derived from the original immutable actor-scoped event,
including event, actor, subscription, action, plan and a domain-separated
SHA-256 binding to the original client key. Exact replays return the same
receipt alongside the current summary even if later actions changed the
state. No raw replay keys or fingerprints are added to the response or history.
No new event, database table, financial authority or provider action is added.
Native verifies the receipt before acknowledging encrypted storage. A missing,
wrong or stale receipt retains the original key for explicit same-intent retry.
Backend and Flutter regressions cover the original cancellation followed by
resume, malformed response binding, actor isolation and exact replay. Fresh
hosted source and combined validation required before Agent 5 integration.
