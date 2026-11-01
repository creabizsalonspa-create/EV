# Durable hosted checkout — charging and subscriptions

This development slice adds test-mode hosted checkout to the existing charging and subscription accounting systems. It does not introduce a wallet or another financial ledger. Corporate statement PSP collection remains unfinished software. Live payment acceptance, real SCA challenges, app signing and verified HTTPS universal links require separate evidence.

## API contract

Authenticated cookie `/api` and mobile `/api/v1` expose the same contracts under `commercial/hosted-checkouts`:

| Method / suffix | Behavior |
| --- | --- |
| GET collection | Scoped, paginated checkout history; optional `scope` and `invoice_id`. |
| POST collection | `{scope: charging\|subscription, invoice_id, subscription_id?: integer, consent: true, idempotency_key}`. Subscription ID is required for subscription checkout. Amount, currency, taxes, provider and return destination are server-derived. |
| GET `/{id}` | Current source state and allowed actions. |
| GET `/by-reference/{uuid}` | Owner-only lookup for an opaque return hint. |
| GET `/{id}/events` | Scoped operational event history. |
| POST `/{id}/inspect` | Authoritative provider lookup. An already captured provider payment may be posted to canonical accounting; this never initiates collection. |
| POST `/{id}/retry-preparation` | Explicit retry of the **original** stored creation request and PSP key after uncertainty. Never replaces its financial identity. |
| POST `/{id}/capture` | Explicit owner capture of verified authorization using durable canonical recovery. |
| POST `/{id}/cancel` | Explicit cancellation with provider confirmation or canonical cancellation. Captured funds require the existing refund workflow. |
| POST `/{id}/simulate` | Local/testing mock only: `{consent:true,outcome:authorized\|declined\|abandoned}`. |

Single results wrap the flat DTO in `checkout`; collection items are flat. DTO includes source invoice/subscription/canonical IDs, UUID reference, minor-unit amount/currency/precision, UTC timestamps, hosted and effective recovery status, evidence labels and `allowed_actions`. Clients must respect action flags, not infer authorization from status text. Private hosted URLs are returned only to the owner for an open session. Admin/vendor scoped inspection does not grant owner mutation rights.

## Integrity and recovery

Creation terms, original merchant fingerprint, canonical references and PSP request identity are immutable. A durable lease commits before external HTTP. Unknown creation or capture outcomes hold the original invoice; negative lookup alone never proves no payment. Explicit preparation retry uses the exact original body/key while its original window remains valid. A new attempt is permitted only after authoritative terminal cancellation/expiry and server `can_retry` confirmation. Replaying an old key always returns its original row.

Stripe TEST contracts use Checkout Session manual capture. Session/PaymentIntent metadata, currency, exact amount, test-mode flag, manual capture and unique binding are validated. Hosted destination must be the exact verified `checkout.stripe.com/c/pay/cs_test_…` session; opaque fragments are preserved, arbitrary query destinations rejected. Signed duplicate and out-of-order provider events preserve canonical receipt/journal invariants. Existing direct capture/refund/recovery and signed-refund ingestion also enforce the original merchant configuration.

`/payments/hosted-return` is an inert public landing page so an external browser can return without app cookies. A query is only a hint; owner authentication and backend source lookup remain mandatory. `creabizev://payments/hosted-return` is a fixed unauthenticated foreground signal, with no financial identifiers or success fields. It triggers source GET only. It is not a verified universal link or proof of payment.

Web and native clients persist the original request identity before POST, retain it after a lost response, clear private state on session/background changes, and require explicit capture/retry/cancel. Customer/vendor subscriptions use their existing invoices, receipts and entitlement enforcement. EN/AR RTL and Light/Dark/System use the existing design system.

## Verification and continuation

Local tests cover source ownership, prohibited client economic fields, merchant changes, authoritative capture/refund, webhook signature/ordering/replay, uncertainty/retry, terminal replacement rules, private return behavior and PostgreSQL process races. Connected browser tests exercise a committed backend response deliberately lost before acknowledgment, recovery, explicit mock authorization/capture, customer/vendor subscriptions, tenant isolation and responsive theme captures. Connected Flutter tests exercise the same Laravel source, not duplicated financial logic.

See `docs/QA_REPORT.md` and `docs/PROGRESS.md` for actual results and current hosted CI status. Do not infer live PSP, 3DS, bank, hardware or production acceptance from HTTP fakes, local mocks or simulator runs. Continue corporate PSP authority/allocation/refund coordination, remaining Batch A requirements and then native campaigns; do not mark the full batch complete.
