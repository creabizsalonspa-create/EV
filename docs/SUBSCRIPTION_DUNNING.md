# Subscription dunning — in-app reminder slice

Status: INTEGRATED_TESTED locally; hosted checks for this slice must pass before merge. This is not saved-mandate enrollment, recurring collection, email/SMS/push delivery or live PSP acceptance.

`commercial:subscription-dunning --limit=100` scans a bounded set of due positive next-cycle subscription invoices without receipts. FinanceScheduleProvider runs it hourly without overlap. It records immutable in-app reminders at day 1, 3 and 7 from the invoice period start. The invoice/stage key is unique; subscription-row locking serializes concurrent workers with the canonical receipt/renewal boundary. A delayed run records only the latest due stage. The selection excludes invoices waiting for their next stage so they cannot starve other due work.

Cancelled, zero-price, paid and unresolved-provider invoices are suppressed. An uncertain payment is handled by the existing recovery workflow, never a new payment attempt. Notices do not modify invoices, ledger entries, subscriptions, entitlements, payment methods or provider operations. No automatic cancellation or grace entitlement is invented. Subsequent payment/cancellation/recovery state is read afresh when notices are displayed; historical reminders remain immutable.

GET `/api/commercial/subscription-notices` and `/api/v1/commercial/subscription-notices` paginate 25 notices. Customer/vendor scope is the subscription owner; Admin can see all. DTOs contain invoice number, subscription/invoice IDs, stage/due/created times, currency/precision/total, current subscription status and `attention` (`payment_due`, `recovery_required`, `settled`, `cancelled`). Responses are no-store. `collection_attempted:false` describes this reminder action; `delivery:IN_APP_ONLY` never claims external delivery. No provider credentials or card data are included.

Web financial workspaces show scoped reminders, current attention, precise server amounts, refresh/pagination, English/Arabic and logout isolation. The canonical Flutter account menu opens subscription notices with RTL, pagination, localized empty states, errors and session expiry using the same APIs. Native amount presentation uses the existing exact-decimal formatter with validated server currency precision, never floating point or client accounting rules. The recovery screen remains separate and requires explicit confirmation for provider-status inspection.

Migration: `2026_10_30_100001_create_subscription_dunning_notices`. Update/delete triggers protect notice history; non-testing downgrade refuses populated history before DDL.

Verification commands:

- `./scripts/php artisan test`
- `services/business-api/tests/Postgres/run-finance`
- `node tests/subscription-dunning.cjs`
- `npm run test:commercial`
- `node tests/native-connected.cjs`
- pinned Flutter `flutter analyze` / `flutter test`

The connected native runner batches nine and five synthetic logins with a full authentication-window boundary after the previous batch completes, preserving production's ten/IP/minute limit. It never clears limiter storage or retries login/payment mutations. The commercial browser suite similarly paces a later bulk read refresh under the unchanged 60/user/minute limit.

Immediate remaining finance task: provider-owned hosted mandate enrollment with consent/revocation, authenticated customer/SetupIntent identity binding, off-session collection through the existing durable recovery/receipt architecture, decline/backoff and SCA handling. Never introduce a second payment state machine or infer a mandate from a client-supplied PaymentMethod. Email/push reminders, preferences and delivery-provider acceptance also remain unfinished. Existing renewal invoicing is explicit; this worker does not schedule or charge renewals.
