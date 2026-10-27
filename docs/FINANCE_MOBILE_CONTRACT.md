# Financial integration contract for Agent 2

Agent 1 owns finance business rules; Agent 2 consumes these APIs in the canonical Flutter app. Do not reproduce money math, subscription eligibility or settlement calculations in Dart. Both cookie/CSRF `/api` and additive Sanctum `/api/v1` routes use the same database. Existing native cookie client remains supported. Web admin is the administrative surface; no admin mobile login is added.

All amounts are integer minor units with authoritative `currency` and `minor_units`. Use decimal-string parsing for user input and display precision from the response, never a universal two-decimal assumption. Do not sum different currencies. Dates are ISO/UTC or database UTC timestamps; format in user locale. List responses are Laravel pagination (`data,current_page,last_page,total`); follow pages without constructing external URLs. Error statuses: 401 session, 403 role, 404 inaccessible tenant record, 409 conflict/state/idempotency/precision, 422 invalid payload, 429 throttle, 503 provider unavailable/not authorized. Preserve request keys and payload across uncertain retries. Changing payload requires a new key; refresh authoritative state before creating replacement charges.

## Shared records

- `GET finance/invoices` and `GET finance/invoices/{id}`: immutable invoices, paid/refunded/due amounts, payments and credit notes. Existing invoice-history integration is retained.
- `GET finance/report?currency=AED&from=2026-01-01&to=2026-12-31`: own customer, owning vendor or global admin totals grouped by currency/precision. Invoice allocations are not vendor payout eligibility.
- `POST finance/invoices/{id}/refund-requests`: customer `{amount_minor,reason,idempotency_key}`. `GET finance/refund-requests`: accessible requests. Vendor/admin `POST finance/refund-requests/{id}/review {decision:approve|reject,note}`. Approval currently executes mock funds only; provider-backed invoices return503 rather than forge a local-only refund.

## Payment checkout

See [PAYMENT_GATEWAYS.md](PAYMENT_GATEWAYS.md) for exact provider/webhook contracts. `GET/POST commercial/checkouts`, POST with `{invoice_id,amount_minor,idempotency_key}`. Per-checkout POST `authorize`, `capture`, `cancel`, `refund` with request key. Authorization accepts only opaque provider references: `mock_success|mock_decline` for mock; Stripe `pm_*` for test mode. Never send/store PAN/CVV. Status is `pending|authorized|action_required|failed|captured|cancelled`. Only server-confirmed captured receipts count as paid. `action_required` does not mean success and still requires a hosted SDK/SCA integration; the current test-mode adapter never invents it. Hosted tokenization/SCA/mobile SDK screens remain open work.

`GET/POST commercial/cases` supports internal disputes with `{invoice_id,kind:dispute,amount_minor,reason,idempotency_key}`. Admin resolution is a separately authorized operation. Internal disputes are not represented as having been submitted to an external card network. Vendor/admin `GET commercial/reconciliation/{invoice}` compares posted operations/ledger and, for configured Stripe test mode, contract-defined provider evidence.

## Subscriptions and vendor settlements

See [SUBSCRIPTIONS.md](SUBSCRIPTIONS.md) and [SETTLEMENTS.md](SETTLEMENTS.md) for complete endpoint/payload definitions, lifecycle semantics and limitations. `commercial/plans` and `commercial/subscriptions` supply authoritative plan versions, invoices, paid entitlements, scheduled next plan and cancellation state. Mock payment must be labeled explicitly; do not treat a client result as PSP verification. Provider-backed subscription checkout now uses the shared adapters and immutable receipt/entitlement boundary. See the Subscription gateway API section in SUBSCRIPTIONS.md for checkout/capture/reconciliation and signed callback contracts. Hosted SCA, off-session mandates/dunning and subscription refunds remain unfinished.

Vendor statements: `GET commercial/vendors/{id}/statement?currency=AED`, including `minor_units`, available balance and due/hold/minimum schedule eligibility. Positive availability is not a bank balance or stored-value wallet. Settlement requests and approval/simulated payout are explicit lifecycle records with audit events and no real transfer. Admin signed adjustments require approval by a different administrator. Negative refund debt carries forward.

## Evidence and gates

Connected web workflow, Laravel API tests and actual PostgreSQL concurrent-process tests are recorded in `docs/evidence/finance-commercial`. No external merchant sandbox, real payment, bank payout, mobile consuming-screen acceptance, tax/legal approval or final commercial completion is implied. Agent 2 should add native tests against these same source records when integrating screens. Keep mock, test-contract and externally verified states visually distinct.


## Finance v2 additive contracts

Latest ownership is Agent1 finance / Agent2 EV and native. `PAYMENT_RECOVERY.md` documents private recovery list/history and read-only provider reconciliation; clients must retain uncertain states and never open replacement charging checkouts around an unresolved invoice hold. `SUBSCRIPTION_CREDITS.md` documents Admin-only subscription refunds and owner-visible immutable credit notes. Subscription invoice/detail responses add refunded/net/credit-state fields; a fully refunded current paid cycle revokes paid entitlements server-side. Refresh shared subscription state after a credit; do not cache a permanent paid flag. Currency precision remains immutable-source data. No native financial logic should be recreated.
