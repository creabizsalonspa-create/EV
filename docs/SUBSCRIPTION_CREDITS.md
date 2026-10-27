# Subscription credit notes and provider refunds

Status: non-production mock and Stripe **HTTP contract** workflow. No real money is enabled and no external Stripe merchant sandbox has been verified. This module adds immutable credit notes against the existing immutable subscription invoices and receipts; it never edits or deletes original billing documents.

## Client API

Mount `routes/subscription_credits.php` inside authenticated cookie `/api` and bearer `/api/v1` groups. Reuse existing financial throttles and session/CSRF policy. Mount `routes/subscription_credit_webhooks.php` stateless in `routes/api.php`, outside user authentication; its raw-body provider signature is mandatory.

- `GET commercial/subscriptions/{subscription}/credits`: paginated credit notes. Owners see their own customer/vendor subscription; administrators may inspect all. Other tenants receive 404.
- `POST commercial/subscriptions/{subscription}/credits`: administrator only. Body `{invoice_id, amount_minor, reason, idempotency_key}`. Amount is an exact positive integer in the invoice currency's minor units (maximum 2,000,000,000 per operation); there is no client-supplied currency or provider reference. Success 201 returns `{credit, entries, subscription, real_payment_processed:false}`. Exact request-key retries return the same credit. Reusing a key with another invoice, amount or reason returns 409. An unpaid invoice or cumulative refund exceeding captured funds returns 409.
- `GET commercial/subscription-credits/{credit}`: the same detail shape, owner/admin scoped. `credit` includes `id`, `number`, `request_id`, `subscription_id`, `invoice_id`, `receipt_id`, `actor_id`, `amount_minor`, `subtotal_minor`, `tax_minor`, `currency`, `minor_units`, `provider_reference`, `evidence`, `reason`, `created_at`. `entries` are its immutable journal rows; `subscription` is the current full subscription summary.
- `POST /api/subscription-credit-payments/stripe-test/webhook`: signed raw Stripe test `refund.updated` events. Only final successful refunds correlated to a previously authorized immutable refund request may post credits. Unsupported event types acknowledge as ignored. An unknown/unrequested external refund returns a financial-review conflict; it cannot invent an administrator-approved reason or bypass the captured-funds bound.

Subscription summaries add `credits`, aggregate `refunded_minor` and `net_collected_minor`. Each original invoice retains its immutable amount and adds derived `refunded_minor`, `net_collected_minor` and `credit_state` (`none`, `partially_refunded`, `fully_refunded`). `paid` still means a receipt exists; it does not conceal a later refund. The existing `ledger_balanced` includes the newly appended refund journals.

## Accounting and entitlements

A refund reverses the remaining invoice revenue/tax components using the shared integer largest-remainder allocator. Partial refunds cannot overdraw either component; refunding the full receipt across any number of partial requests restores every original minor unit exactly. Credits debit subscription revenue and tax payable and credit the **same explicitly labeled mock/Stripe-test cash account** used by the receipt. Journal kind `c{credit_id}` ties each append-only entry group to its credit note. No wallet or stored value is created.

A partial refund retains the original paid entitlement period and does not silently change plan terms. A full refund of the **current paid cycle** cancels the subscription, clears scheduled plan changes/cancellation flags, and immediately revokes entitlements. Fully refunding an older cycle does not revoke a subsequently paid current cycle. A pending next-cycle gateway authorization must be cancelled/reconciled before initiating a full refund of the current entitlement, avoiding a new uncertain capture during revocation. Existing zero-price entitlements remain valid; zero-price invoices have no captured funds to refund.

Cancellation does not erase or automatically void unrelated unpaid cycle invoices, and a refund does not retroactively prorate a plan. Unpaid-invoice waiver/write-off policy, commercial approval thresholds and automatic proration remain separate requirements. Credit numbers use `TEST-SUB-CN-*` and are not legally approved tax documents; regional tax registration, credit-note wording and accounting policy require final review.

## Provider integrity and crash recovery

- A manual/mock receipt is refunded only through the shared deterministic `MockPaymentProvider`; no network request occurs.
- A `STRIPE_TEST_CONTRACT` receipt requires its matching captured gateway operation, exact amount/currency and Stripe PaymentIntent. Changing the default provider to mock cannot convert an external receipt into a local-only credit. The shared `StripeSandboxProvider` must confirm the exact successful refund before posting.
- The immutable `subscription_credit_requests` record is committed before the provider mutation and snapshots administrator, invoice, receipt, amount, reason, request fingerprint and shared recovery identity. It survives an uncertain response independently of the final credit note.
- Stripe refund mutations run through the shared `PaymentRecovery` durable intent and stable provider idempotency key. Unknown outcomes never produce a local success, and another operation cannot bypass unresolved intent checks by changing its client key. Retry only the same operation within the permitted provider window.
- Signed final refund events validate test mode, capture identity, amount, currency, raw-body signature freshness, event identity and correlation through `metadata.creabiz_recovery` or sanitized provider observations. They post the same bounded credit/journal, then resolve the matching durable recovery intent inside the same transaction. Exact event duplicates acknowledge; changed event bodies conflict. A credit's original request identity prevents a replay from resolving another refund intent.
- `SubscriptionCreditService::recoverFromProvider(intent)` performs **read-only** Stripe test evidence lookup using the shared adapter and invokes that same verified posting path. It never fabricates signatures or sends another refund. It can reconcile after the provider retry window has expired when exact final evidence is available. Root recovery worker/controller dispatch is responsible for invoking it under administrator authorization.
- SQLite and PostgreSQL database triggers reject mutation/deletion of requests, credit notes and webhook evidence. Existing invoice/receipt/journal immutability remains intact. Subscription row locks and unique request/provider identities serialize cumulative bounds and prevent duplicate credits.

## Verification

`./scripts/php artisan test --filter=SubscriptionCreditTest` covers exact partial/full tax allocation, replay/conflicts, overrefund/unpaid rejection, owner/admin isolation, historical/current entitlements, actual adapter HTTP-faked refund contracts, unlinked external receipts, mismatched provider outcomes, uncertain retries, signed recovery, wrong signatures/live events/uncorrelated refunds, SQL immutability, production rejection and read-only expired-window reconciliation. These are **mock/contract tests**, not actual external PSP acceptance.

`tests/Postgres/SubscriptionConcurrencyTest.php` additionally races same-key refund retries and distinct refund keys against one capture. The shared PostgreSQL runner must execute and persist those results before they are reported as passing. Root project QA/progress documents are authoritative for the latest executed totals.

Remaining external gates: authorized test merchant account, test PaymentMethods, secure test credentials/signing secret, actual callback delivery, legal tax-note approval and merchant/security acceptance. Production refund endpoints remain disabled; no live key or live money is activated.
