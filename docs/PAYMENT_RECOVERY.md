# Durable test-provider payment recovery

Agent1 finance ownership. Branch `development/finance-commercial-v2` continues integrated6d0e33c; PR4/5 are historical merged work. No real provider sandbox, production payment or bank transfer is verified by these tests.

## Durable execution and safety boundary

Stripe-test authorization/capture/cancellation/refund operations prepare a recovery intent in a committed transaction **before** entering the business transaction that contacts the provider. The record binds scope, checkout, operation, actor, exact amount/currency, payload fingerprint and a stable provider idempotency identity. Only opaque-token fingerprints are retained; PAN/CVV, PaymentMethods, client secrets and raw provider payloads are not stored in this journal.

A three-minute lease blocks overlapping execution. A process dying at an intercepted HTTP boundary leaves its committed intent available after restart. Retries of a capture use the same provider identity even when the client changes its request key. A refund remains a separately identified logical operation; an uncertain refund blocks changed-payload or replacement-key refunds. An uncertain operation holds the **whole charging invoice**, including previously authorized alternative checkouts, so an unposted external outcome cannot be escaped by opening/capturing a replacement checkout.

The conservative retry window is23 hours from preparation. Beyond that window, the code refuses blind payment mutations: it requires authenticated callback evidence or authoritative provider GET reconciliation. A failed precondition never erases an earlier uncertain outcome or its retained sanitized observation. Application callers must not wrap outbound operations in their own parent transaction; tests explicitly use guarded disposable databases without an ambient testcase transaction.

Immutable invoices/journals/receipts remain financial truth. Recovery records are operational state, and their event history has SQL mutation guards. `completed` means the operation's outcome was recorded, not that real money was verified or necessarily captured. Declines and SCA-required outcomes may be recorded authorization outcomes without paid entitlements.

## Reconciliation and scheduled processing

`commercial:reconcile-payments --limit=100` reclaims expired leases and matches committed local evidence. `--provider` additionally reads unresolved test-provider outcomes; it never submits a provider charge/refund mutation. The financial schedule registers a bounded every-minute scan without overlapping and preserves the existing charging schedules. `PAYMENT_RECOVERY_PROVIDER_POLL=true` opts the scheduled command into provider GET inspections. Configure the target host's Laravel scheduler separately; a registered schedule is not evidence that deployment/cron is running.

Read-only provider reconciliation checks exact requested PaymentIntent/refund identity, currency, amount, test-mode status and logical operation. Unknown authorization/refund references may be located by the server-generated recovery metadata; ambiguous or absent search/list results never authorize a replacement payment. Uniquely established final facts use the same private transactional application path as authenticated callbacks. The code does not manufacture a webhook signature. Ledger postings and recovery completion commit or roll back together. Subscription refunds reuse the authorized immutable credit request and credit ledger.

Endpoint errors leave the intent visible for review. The bounded worker continues other records and reports unresolved provider reads as failures. Missing credentials, ambiguous search results, non-final status or identity/amount mismatch cannot be dismissed through a client assertion or manual paid checkbox.

## API and financial workspace

Mounted under existing authenticated cookie `/api` and token `/api/v1` groups:

- `GET commercial/payment-recovery?page=1`: tenant-scoped records; own customers, owning charging vendor, or all for financial Admin. Intent fingerprints, internal lease tokens and observations are removed from responses. Amounts include immutable-source currency precision.
- `GET commercial/payment-recovery/{id}/events`: private immutable chronological history.
- `POST commercial/payment-recovery/{id}/reconcile {}`: authoritatively read the configured test provider and reconcile only a proven outcome. It does not send a payment/refund. Inaccessible intents return404 before contacting the provider.

The connected financial workspace contains a lazily loaded recovery queue, explicit uncertain/executing/review states, private history, meaningful errors and provider outcome checks. Financial reads preserve existing rate limits; no blanket retry or limit relaxation was introduced. Native consumers must display these states and refresh shared records rather than infer payment success locally.

## Verification and remaining gates

Current evidence is recorded in `docs/evidence/finance-v2` and the finance continuation file. Unit/API tests cover failed business transaction durability, changed client keys, changed refund payloads, whole-invoice holds, wrong provider identity, expiry, privacy and atomic authenticated callback recovery. Actual PostgreSQL child processes simulate abrupt exit at HTTP interception and overlapping requests; the provider itself is still an HTTP fake.

The SQLite canonical topology downgrade issue discovered by transaction-free fixtures is reported for Agent2 at https://github.com/creabizsalonspa-create/EV/issues/6. Financial tests rebuild only guarded disposable databases using forward migrations; they do not claim that EV downgrade is fixed or verified.

External merchant sandbox acceptance, real callbacks, operational scheduler deployment, load/incident acceptance and production activation remain unverified. Hosted SCA, recurring mandates/dunning, payouts, corporate billing and broader commercial policies are separate required workstreams, not excused merely by missing external credentials.

## Identity hardening follow-up

A rejected POSTresponse can leave a sanitized but unvalidated object ID. Read-only reconciliation now prioritizes the checkout's established PaymentIntent, rejects a mismatched GET identity, requires original checkout metadata for otherwise unknown authorizations, and binds refund observations to the exact recovery-request metadata. An unrelated successful payment/refund with the same currency and amount cannot resolve an uncertain operation. The regression reproduced the wrong-identity problem before the fix; the targeted recovery/payment/subscription/credit suite then passed37 tests/388 assertions. First v2 hosted push+PR checks at41f304d passed platform, financial PostgreSQL and connector PostgreSQL jobs; later revisions retain their own CI evidence.
