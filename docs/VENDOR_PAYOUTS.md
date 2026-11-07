# Vendor payout operations and TEST bank adapter

## Readiness and boundary

This module adds a complete **software workflow** from an approved settlement and consenting vendor account through separate administrator approval, durable dispatch, provider observation, failure/retry, reconciliation and reversal. It does **not** activate real money. The default execution gate `PAYOUT_TEST_ENABLED=false` and the production environment reject every financial mutation. Supported providers are deterministic `mock` and Stripe **TEST HTTP** only. Existing immutable invoice/payment/refund/journal records remain the financial source; payout operations do not create a second cash ledger or edit charging journals.

The Stripe adapter creates a **bank payout from an existing connected test account's available balance** with `POST /v1/payouts` and `Stripe-Account: acct_…`. It never calls `/transfers`, provisions a real beneficiary, funds the account, accepts an IBAN/card number or promises a vendor has received money. Commercial account approval, ownership/consent, a funded test account, appropriate bank capabilities, merchant/Connect access, regulatory/tax/contract sign-off and eventual bank acceptance are external gates. Contract tests fake official API responses and are not merchant-sandbox or bank acceptance.

## Account and approval controls

An administrator configures **PSP identifier tokens only**: `acct_…` and `ba_…` for Stripe, or `mock_…` identifiers for the mock. References are encrypted at rest, omitted from all read APIs and immutable after creation. A changed account requires another configuration record. Configuration retries are actor-scoped and payload-bound. Only the owning vendor may consent to `vendor-payout-v1`; administrators cannot impersonate that consent. Revocation prevents new dispatch, while existing source reconciliation remains available.

Administrator verification performs authoritative GETs of the exact connected account and external bank token. It requires enabled payouts, submitted account details, a supported platform-controlled account type, matching `creabiz_vendor_id` metadata, and a verified/validated destination for the exact currency. Stripe account/bank objects do not uniformly carry `livemode`; the adapter validates their expected object identity and rejects explicit live flags, uses only `sk_test_…` credentials, and requires explicit `livemode:false` on balances and every payout object. No redirected endpoint or user-supplied API origin is accepted; API version is pinned to `2024-06-20`.

Each payout requires an already approved, unexecuted settlement. A different administrator must approve the payout maker's request. A retry of a known failed/reversed operation returns to `prepared` and requires fresh approval by a different administrator from the retry maker. Currency/precision, source lines, mature holding periods, enabled schedule, refunds, disputes, unresolved charging PSP evidence, signed adjustments and negative payable debt are checked under the same vendor/invoice locks as settlements before dispatch. The exact connected TEST balance is checked immediately before an initial payout. Unknown outcomes can replay the **same attempt** without requiring a second balance that may already have decreased.

## Provider-hosted recipient onboarding

A consenting owner may request `POST /commercial/payout-accounts/{account}/onboarding` with `idempotency_key`. This supports an existing administrator-configured, vendor-bound Stripe **TEST** connected account through the official `POST /v1/account_links` API. It does not provision an account, collect raw bank details or enable real payments. The server first retrieves the exact account and validates its owner metadata/type. Mock recipients, foreign owners, administrators impersonating vendors, revoked accounts and absent consent are rejected.

The no-store response contains `{url,expires_at,evidence:"STRIPE_TEST_HOSTED_ONBOARDING",real_money_moved:false}`. The URL must use HTTPS on `connect.stripe.com` without a custom port or credentials and expire within an hour. Return/refresh destinations derive only from trusted `APP_URL`, requiring HTTPS outside localhost development/testing; no client redirect is accepted. The hosted link is ephemeral and not persisted in financial audit records. Returning with `payout_onboarding=return` or `refresh` proves nothing: the account remains unverified until an administrator performs authoritative account/bank verification. If the bank destination changes, the administrator must configure a new immutable account record for its exact token.

## Durable outcomes, reservations and reversals

A payout owns one settlement, with immutable economic terms and one or more numbered attempts. Dispatch commits an operational lease and stable provider idempotency identity before the external POST. A second process cannot dispatch while that lease is active. If a process dies after the sent marker or a response is lost, the attempt remains executing/uncertain and the settlement remains reserved. A bounded replay uses the exact original identity and immutable destination/amount for at most23hours, safely shorter than Stripe's24-hour minimum idempotency retention. Expired uncertainty requires source reconciliation or external operational investigation; it cannot create a replacement attempt.

Provider references are unique across attempts. Confirmed pending/in-transit, paid, failed/canceled and reversed states are retained with immutable event/audit history. The paid source fact marks the settlement `provider_paid`; that state remains included in reserved/completed vendor earnings. A later customer refund becomes negative payable debt against subsequent earnings. If the provider reports paid after a concurrent refund, the paid fact is recorded rather than rolled back based on stale eligibility. Payout cancellation/rejection never substitutes for a customer refund.

A failed payout may retry after fresh maker/checker approval. An unknown/pending/paid payout cannot be locally cancelled or retried as a replacement. Legacy `simulate-payout` and settlement rejection are blocked while any non-cancelled payout claim owns the settlement. An administrator may safely cancel only prepared, approved, known failed or reversed operations; the original request record remains immutable/auditable, and the settlement may then be rejected/rebuilt.

An authoritative bank failure observed after paid creates `reversed`, restoring the settlement to approved/unexecuted but preserving the original source fact and reversal event. Repeated or stale notifications cannot silently restore an older paid/pending state. Reconciliation performs GET only, using a known payout ID or a bounded connected-account payout search matching the exact attempt metadata. Absence in a bounded search is **not proof of failure**: uncertainty remains reserved.

## API contracts

Authenticated parent mount: `routes/payouts.php` under existing cookie `/api` and optional bearer `/api/v1` groups. Administrator actions require the current admin role. Owning vendors may read their records and consent/revoke; customers have no payout access; cross-tenant reads return404. Amounts are exact integer minor units with explicit authoritative `minor_units`.

| Endpoint | Payload / result |
| --- | --- |
| `GET /commercial/payout-accounts` | Vendor/admin paginated `data`; sensitive references and request fingerprints omitted |
| `POST /commercial/vendors/{vendor}/payout-accounts` | Admin: `provider`, `currency`, `account_reference`, `bank_reference`, `idempotency_key`;201 `{account}` |
| `POST /commercial/payout-accounts/{account}/consent` | Owner: `consent:true`, `consent_version:"vendor-payout-v1"`; `{account}` |
| `POST /commercial/payout-accounts/{account}/onboarding` | Owner: `idempotency_key`; ephemeral official Stripe TEST hosted onboarding URL, no activation |
| `POST /commercial/payout-accounts/{account}/verify` | Admin; authoritative account/destination verification; `{account}` |
| `POST /commercial/payout-accounts/{account}/revoke` | Owner/admin; `{account}` |
| `POST /commercial/settlements/{settlement}/payouts` | Admin: `account_id`, `idempotency_key`;201 `{payout}` in `prepared` |
| `GET /commercial/payouts` | Vendor/admin paginated `data` |
| `GET /commercial/payouts/{payout}` | `{payout,account,attempts,events,real_money_moved:false}`; lease token never returned |
| `POST /commercial/payouts/{payout}/approve` | Another admin; `{payout}` |
| `POST /commercial/payouts/{payout}/execute` | Admin; dispatch or bounded same-attempt replay; `{payout}` |
| `POST /commercial/payouts/{payout}/reconcile` | Admin; GET-only source observation; `{payout}` |
| `POST /commercial/payouts/{payout}/retry` | Admin; known failed/reversed only, fresh approval required |
| `POST /commercial/payouts/{payout}/cancel` | Admin; safe unsent/known-failed lifecycle cancellation only |

Read records expose `provider`, `evidence` (`MOCK_PAYOUT` or `STRIPE_TEST_HTTP`), economic snapshots, approval identities and timestamps, and `real_money_moved:false`. Status `paid` describes the selected TEST/mock provider result; it is not a real bank receipt. Failed preflight returns503 with a recorded safe failure and no new bank POST; unknown dispatched outcomes return503 with a durable uncertainty hold. Changed-payload keys, invalid lifecycle, in-flight leases, unresolved source state and economic holds return409.

Public stateless mount: `routes/payout_webhooks.php`, `POST /webhooks/payouts/stripe-test`. Configure separate `PAYOUTS_STRIPE_TEST_WEBHOOK_SECRET` securely; never commit it. Raw-body Stripe timestamp/HMAC verification uses300-second tolerance and event identity/fingerprint deduplication. Only test-mode payout notifications for the exact connected account, destination, settlement, amount, currency and attempt are accepted. Signed notifications trigger authoritative GET retrieval rather than trusting reordered notification payloads. No raw bank/provider payload or secret is stored.

## Running and recovery

- Enable `PAYOUT_TEST_ENABLED=true` only in an isolated approved development/test environment.
- Supply existing `STRIPE_TEST_SECRET=sk_test_…` and the payout-specific TEST webhook secret through environment secrets when authorized external sandbox access is available. Mock workflows need no external secret.
- `php artisan finance:payout-reconcile --limit=100` checks at most100 committed unresolved/pending outcomes using GET only. It reports attempted/checked/unavailable counts; `checked` can still mean an unresolved no-match outcome, never a payout success claim. Scheduling a command does not prove a deployed cron/queue exists.
- Migrations180001/180002 add operational records, immutable economic terms/events and a unique provider source identity;180002 rejects preexisting duplicate source IDs beforeDDL.
- No background worker sends new payouts. Only an explicit administrator dispatch after maker/checker approval may POST.

## Verification sources

`VendorPayoutTest` exercises the actual authenticated HTTP routes and shared finance foundation, including encryption, consent, maker/checker, full payable dispatch, identity replay, funding checks, pending/failure/reversal, authenticated/deduplicated/stale webhooks, GET recovery, timeout holds, late customer refund debt, immutable economic terms, currency mismatch, privacy and production/live-key rejection. Stripe calls are HTTP-faked; no real payout is verified.

`tests/Postgres/PayoutConcurrencyTest.php` uses separate PHP processes and a barrier against a guarded disposable PostgreSQL database. It covers identical/different settlement claims, overlapping dispatch/legacy transitions and process death after the durable sent marker followed by same-identity recovery. These tests must actually pass in the PostgreSQL runner before any PostgreSQL concurrency claim; SQLite tests do not substitute for that evidence.
