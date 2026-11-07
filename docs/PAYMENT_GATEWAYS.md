# Payment gateway workflows and mobile contract

This source checkpoint implements a complete **mock checkout lifecycle** and an actual **Stripe test-mode HTTP adapter with contract tests**. It does not activate production money, offer stored value, certify accounting/tax compliance, or claim that Stripe merchant credentials have been exercised. No live PSP credentials or real transactions were used. Subscription PSP allocation and hosted SDK/SCA completion are separate open integration work.

## Shared authenticated API

Routes below are mounted by the integrated application in the existing cookie/CSRF `/api` group and bearer `/api/v1` group. Amounts are exact integer currency minor units, bounded to 2,000,000,000. Checkout precision/currency is inherited from the immutable invoice; callers never select a different currency. Never submit PAN, CVV or raw card details.

| Method and path | Payload / behavior |
| --- | --- |
| `GET /commercial/checkouts` | Paginated customer-owned, vendor invoice-owned, or admin view. |
| `POST /commercial/checkouts` | `invoice_id, amount_minor, idempotency_key`; customer owns invoice, balance checked. |
| `POST /commercial/checkouts/{id}/authorize` | `token, idempotency_key`; mock token `mock_success` or `mock_decline`; Stripe opaque PaymentMethod `pm_*`. |
| `POST /commercial/checkouts/{id}/capture` | `idempotency_key`; full unexpired authorization only, invoice balance serialized. |
| `POST /commercial/checkouts/{id}/cancel` | `idempotency_key`; uncaptured checkout only; actual test provider cancellation required for externally authorized intents. |
| `POST /commercial/checkouts/{id}/refund` | Owning vendor/admin: `amount_minor, reason, idempotency_key`; bound to capture and remaining invoice funds. |
| `GET /commercial/cases` | Tenant-scoped dispute records. |
| `POST /commercial/cases` | Customer: `invoice_id, kind: dispute, amount_minor, reason, idempotency_key`; against net captured funds. Refund requests use the existing `/finance/invoices/{invoice}/refund-requests` workflow. |
| `POST /commercial/cases/{id}/resolve` | Admin: `outcome: approved|rejected, resolution`. Mock approved disputes issue exactly one immutable credit note/refund; conflicting resolutions reject. Stripe approval is blocked until provider-specific dispute submission/decision integration exists. |
| `GET /commercial/reconciliation/{invoice}` | Owning vendor/admin: immutable operation→ledger comparisons, balance summary, and Stripe test API inspection where an external test intent exists; mismatches are explicit. No silent ledger repair. |

Responses expose `provider`, state, reference and explicit evidence, omit idempotency hashes, and set `real_money_moved: false`. States: `pending`, `authorized`, `action_required` (Stripe authentication pending), `failed`, `captured`, `cancelled`. Authorization expires after 30 minutes locally; expired authorization cannot capture. Refund totals come from immutable operations/credit notes. A new checkout is required after a decline. Duplicate requests resolve their prior state; changed payload for the same scoped key returns409. Different authorized checkouts cannot overpay the same invoice. Card-field submissions are prohibited by authorization validation.

## Provider selection and secrets

Default `PAYMENT_PROVIDER=mock`. Mock operations never leave the process. `PAYMENT_MOCK_WEBHOOK_SECRET` must be a securely configured random key of at least32 characters to receive mock events; no default secret is supplied.

`PAYMENT_PROVIDER=stripe_test` selects `StripeSandboxProvider`. Configure `STRIPE_TEST_SECRET=sk_test_...` and `STRIPE_TEST_WEBHOOK_SECRET=whsec_...` securely in a non-production environment. A live `sk_live_` key, live-mode response/event and all production-environment adapter operations are rejected. The Stripe API version is pinned with `Stripe-Version: 2024-06-20`. URLs are fixed to `https://api.stripe.com/v1`; redirects are prohibited, TLS remains verified, connect/read timeouts are bounded. Credentials and provider error bodies are never copied into persisted operation records or user-visible exceptions.

Stripe adapter uses manual-capture PaymentIntents, provider idempotency keys, opaque PaymentMethods, explicit cancellation, refunds, and GET inspection with expanded charge refund totals. Ledger receipts are issued only for verified final capture/refund responses or authenticated final events. Requires-action results remain pending with no receipt; secrets are not returned or persisted. Completing 3DS/SCA through Stripe SDK/hosted checkout is **not implemented** and must not be presented as working card checkout. Network uncertainty returns503 and instructs reuse of the same key; signed final events recover lost responses. Pending refunds do not post credit notes until a signed succeeded event or confirmed response arrives.

## Webhooks

- Mock stateless `POST /api/payments/mock/webhook`: headers `X-Payment-Timestamp` (Unixseconds), `X-Payment-Signature` (hexHMAC-SHA256 over `timestamp + '.' + exact_raw_body`). Freshness300seconds; body limit16KiB. JSON: `event_id, checkout_id, type: captured|refunded, amount_minor, currency`. Optional `provider_operation_reference` correlates a known direct refund/capture without posting again. Capture callbacks reconcile an already committed capture automatically. A new refund event without operationreference represents a new distinct provider refund and is bounded by remaining funds.
- Stripe stateless `POST /api/payments/stripe-test/webhook`: exact raw-body `Stripe-Signature` timestamp/v1 verification and300second freshness,64KiB limit, strict test-mode event/object checks. Supported final/recovery events: `payment_intent.amount_capturable_updated`, `payment_intent.payment_failed`, `payment_intent.succeeded`, `refund.updated`. Unsupported events are acknowledged as ignored. Amount, currency, checkout identity and operation reference must reconcile. Provider event identifiers are unique globally per provider; exact replay is accepted, changed replay409. Unknown checkout404 can be retried after the local checkout is committed. The endpoint must not use session/CSRF middleware.

The invoice row is the shared PostgreSQL serialization root for local payment, provider capture/refund, webhook and dispute workflows. Immutable operations and webhook receipts use SQL mutation guards; finance invoices/journals remain unchanged. Provider API calls use stable outbound keys to survive retries, while signed callbacks reconcile uncertain final outcomes. External guarantees beyond the tested provider contract still require actual authorized test-account verification. No code here grants a wallet or pays vendors.

## Verification and remaining work

Executed focused SQLite suite: `./scripts/php artisan test --filter='PaymentGatewayTest|StripeSandboxTest'`. Tests cover mock authorization/decline/expiry/cancellation, exact replay, competing authorizations, refunds and balanced journals, customer disputes/admin approval/tenant isolation, forged/stale/conflicting webhooks, Stripe request/response contracts, live-key/live-response rejection, SCA pending behavior, pending refunds, transport uncertainty, and signed response-loss recovery. Stripe HTTP calls are faked with stray network requests prohibited; these are **contract tests**, not an external Stripe sandbox run.

`tests/Postgres/GatewayConcurrencyTest.php` supplies real process-barrier races for same capture retries, competing checkout captures, and simultaneous signed event retries. Its executed result must be taken from the PostgreSQL runner evidence; supplying the tests alone is not verification. Root runner should include `PaymentGateway|StripeSandbox` feature filters and all `tests/Postgres` classes.

Still open: authorized external PSP sandbox credentials/run, complete hosted SDK/SCA/card tokenization UI and native SDK integration, provider dispute evidence submission and chargeback liability accounting, broader bank/acquirer settlement import, legal tax/payment approval, production activation and operational/security acceptance. The mock UI must stay clearly labeled; do not claim full payment completion from these tests.
