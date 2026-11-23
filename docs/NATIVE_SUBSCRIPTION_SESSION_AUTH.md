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
