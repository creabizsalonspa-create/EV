# Native loyalty read-only continuation — 28 October 2026

Canonical Flutter consumes the PR18 loyalty account/ledger/reward/redemption APIs; see [NATIVE_LOYALTY.md](NATIVE_LOYALTY.md). This does not duplicate or change economics. Native mutations and other full financial parity remain incomplete. Current temporary full Agent2 ownership supersedes historical split statements below while Agent1 is unavailable.

# Financial integration contract for Agent 2

Latest native QA adds exact server-precision amount formatting (no floating-point/accounting duplication), correct EN/AR empty subscription-notice labels, fail-closed unsupported precision and clearing of transport-uncertainty messaging only after successful authoritative GET. Full Flutter80 passed/seven explicit live-only skips; all nine recovery-specific tests and analysis passed after the refresh correction. Composer strict validation and the preserved finance/lifecycle scheduler plus new hourly dunning registration are verified. Hosted validation is pending for this final native correction.


## Native payment recovery — verified implementation

Canonical Flutter customer/vendor account menu now opens the tenant-scoped payment recovery queue and paginated audit history. Provider inspection requires explicit confirmation and calls the existing read-only provider-reconciliation API; it never creates a charge/refund, accepts card credentials or infers invoice payment from operation completion. Errors clear private rows, 401/419 invalidate the session, disposal fences late responses, lost responses never replay POSTs, and both English/Arabic RTL are supported. The UI renders an allowlist, not arbitrary provider payloads.

Validation: Flutter analysis clean; 77 unit/widget tests passed with six explicit live-fixture skips; six new recovery tests cover pagination/history, consent, lost-response non-replay, expiry, 390px RTL and disposal. Separate native real-Laravel integration passed customer/vendor scope, cross-tenant 404s, private-field redaction and provider-mismatch 409 without initiating payments. Linux native CI now runs that opt-in connected test. Android/iOS hosted builds for this source remain pending. Saved mandates, off-session collection and dunning remain the immediate financial implementation priority; this UI does not implement them or claim merchant acceptance.


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


## Finance v3 candidate — Agent 2 integration handoff

New additive contracts are mounted under both cookie/CSRF `/api` and authenticated Sanctum `/api/v1`. Existing charging, fleet-operation, subscription and native code remains canonical. No Dart financial implementation is added.

| Area | Contract | Native guidance |
| --- | --- | --- |
| Vendor payouts | [VENDOR_PAYOUTS.md](VENDOR_PAYOUTS.md) | Scoped recipient/history views; vendor consent/revoke/hosted onboarding; Admin approvals remain web-only. Preserve unknown outcome and exact currency precision. MOCK/STRIPE TEST outcomes never prove real bank payment. |
| External disputes | [EXTERNAL_DISPUTES.md](EXTERNAL_DISPUTES.md) | Customer/vendor sanitized history/deadlines; encrypted evidence and provider retries are financial Admin operations. Open/lost provider cases hold settlement and refunds. No fabricated chargeback journal. |
| Corporate billing | [CORPORATE_BILLING.md](CORPORATE_BILLING.md) | Accepted agreements, explicit driver's own-invoice/cost-center claims, authorized manager statements/exports. Current fleet owner is checked server-side. Mock corporate receipts are not PSP collection. |
| Documents | [FINANCIAL_DOCUMENTS.md](FINANCIAL_DOCUMENTS.md) | Authenticated private binary PDF/CSV downloads; immutable generation-time state differs from current source state. Corporate documents recheck current owner; never persist provider/banking references. |
| Loyalty | [LOYALTY_ECONOMICS.md](LOYALTY_ECONOMICS.md) | Account/ledger, integer promotional units, qualified invoice earning, rewards/redemption/history. Points have no cash value; pending redemption is not delivery. Reconciliation/holds/debt/expiry remain server-authoritative. |

The current source passes local combined Laravel/PostgreSQL/process/browser checks; hosted PR checks and safe integration remain required. Production activation, external providers, legal rules and partner fulfillment remain separate acceptance gates. Historical unfinished-implementation notes above refer to their earlier checkpoints; hosted mandates/recurring/dunning are already integrated and must not be rebuilt.
