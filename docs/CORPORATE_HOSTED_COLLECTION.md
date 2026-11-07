# Corporate statement hosted collection

Development continuation after verified PR28 (`f2fe9d8`). This statement-root TEST collection slice preserves original driver-owned invoices and their immutable journals. It does not create driver gateway checkouts for a shared provider intent, issue credit, or introduce another financial ledger. It is not full corporate payment completion: statement-level provider refund/dispute resolution remains unfinished software, and external corporate funds therefore stay excluded from settlements/payouts.

## Contract

Cookie `/api` and native `/api/v1` use the same authenticated family:

- `GET commercial/corporate/hosted-checkouts?statement_id=…` — scoped paginated history.
- `POST commercial/corporate/hosted-checkouts` — `{statement_id, consent:true, idempotency_key}` only. Client totals, currency, provider, return destinations and card data are prohibited.
- `GET …/by-reference/{uuid}`, `GET …/{id}`, `GET …/{id}/events` — private source and event history.
- `POST …/{id}/inspect` — authoritative provider lookup, never a new collection.
- `POST …/{id}/retry-preparation` — original stored creation body and key only.
- `POST …/{id}/capture` / `cancel` — explicit original attempt operations.
- `POST …/{id}/simulate` — local/testing mock only, `{consent:true,outcome:authorized|declined|abandoned}`.

Results use `{checkout: DTO}` and flat paginated items. The DTO follows existing hosted action flags, UTC timestamps, exact minor-unit money, recovery state and evidence labels, with `scope:corporate`, `statement_id`, immutable `payer_id`/`user_id` and `line_fingerprint`. `invoice_id` and `subscription_id` are null: a statement is not a single charging invoice. Detail statement response adds `can_collect_hosted` and `line_fingerprint`. Clients must compare the source statement ID, server total/currency/precision and fingerprint, then follow backend flags. A missing authority flag fails closed. Admin may inspect, but cannot impersonate the payer to authorize/capture.

## Integrity

The immutable statement payer must also be the current fleet owner for a new owner operation. Fleet/statement and all invoice roots are locked in a consistent order. Every included invoice has a durable hold before external HTTP; direct driver payment, another gateway attempt and legacy corporate simulation cannot compete. Atomic authoritative capture creates the existing corporate receipt and one canonical payment for each original invoice. No shared PaymentIntent is copied into driver gateway records.

Merchant configuration, original request/return terms, amount/precision, statement line fingerprint and provider session/intent bindings are fixed. Unknown operations retain their original identity and leases; a missing lookup match does not authorize a replacement. Late and duplicate signed events cannot rewind captured accounting. Local refunds or simulated credit cannot pretend to return external provider funds. Settlement and payout guards remain until actual statement-level refund/dispute allocation software is implemented and tested.

## Configuration and return

For Stripe TEST, configure `PAYMENT_PROVIDER=stripe_test`, approved `STRIPE_TEST_SECRET` and `STRIPE_TEST_WEBHOOK_SECRET`, plus `CORPORATE_PAYMENT_HOSTED_RETURN_URL=https://your-approved-test-host/payments/corporate-hosted-return`. The fixed corporate return path must use HTTPS, with no query, user information or fragment. Its original opaque reference is appended by the server. Production collection is disabled. Local mock uses the same fixed path on the application origin.

`/payments/corporate-hosted-return` is public and inert. The server selects the corporate API family in the rendered template; query parameters cannot switch it. Owner authentication and provider/backend evidence are still required. The constant `creabizev://payments/hosted-return` signal carries no transaction status/ID and triggers GET recovery only. `/payments/corporate-hosted-simulation/{id}` requires authentication and local/testing mock configuration. Private pages/API responses use no-store and no-referrer.

The bounded recovery command is `php artisan commercial:reconcile-corporate-hosted --provider --limit=25`. It performs original provider reads only, posts already-authoritative source observations where appropriate, and returns failure while unresolved outcomes remain held. It never creates/captures/cancels provider money movement.

## Evidence and limitations

Record final exact-source tests in PROGRESS/QA and the evidence checkpoint before integration. Current focused/browser evidence is developmental until full regression, process races and hosted Android/iOS/platform checks pass. Local mock and provider HTTP-contract fakes are not real PSP, SCA/3DS, bank or production acceptance. Signed device releases, live merchant activation, operator/hardware/partner and legal/store acceptance remain external. Keep the fixed42-category/312-subfeature denominator and award no partial broad-atom completion.
