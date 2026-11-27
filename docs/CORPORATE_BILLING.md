# Corporate billing — explicit invoice allocation and consolidation

This finance-owned addition consumes existing fleet IDs, active accepted membership and cost centers. It does not alter fleet operations, vehicle assignments, charging prices, reservations or the underlying immutable invoice/journal. A driver explicitly claims an own **wholly unpaid** charging invoice; the system never guesses historical organization ownership from a vehicle's current fleet assignment.

## Implemented workflow

1. Administrator publishes immutable, non-overlapping agreement terms for an existing fleet and its configured country/currency/precision. Terms include legal company name, text, effective/expiry timestamps, credit limit, payment terms and intended billing day. These are configured test/commercial terms, not a grant of regulated credit or a legally certified contract.
2. The fleet owner explicitly accepts the agreement. Administrators cannot consent for the company. Active agreements permit current owners/accepted active drivers to submit their own invoices with an explicit cost center belonging to the same fleet. Cost-center names/codes are snapshotted.
3. Owner or financial administrator approves/rejects the claim with a reason. Decisions are append-only and irreversible; a rejection cannot silently become approval. Drivers cannot review claims or see organization-wide statements. They see their own claims only.
4. Owner/admin explicitly consolidates approved, unallocated claims from one calendar month into an immutable statement. At most100 claims per API call. Claim and invoice uniqueness prevents duplicate company liability. The statement stores original invoice numbers, subtotal/tax/total and cost-center snapshots; original charging invoices and tax allocations remain unchanged.
5. Outstanding statements in the same company/currency count against the agreement's credit limit. Shared fleet locks serialize credit checks. The due date is issue time plus negotiated payment terms. Statement IDs, claim IDs and receipt keys are idempotent, actor scoped and payload bound. Request payload changes return409.
6. A separately labeled **simulation** operation lets only the original/current fleet owner pay an entire statement using the existing canonical `FinanceService::pay` for every original invoice in one database transaction. The corporate receipt merely links those existing immutable payments and records the actual owner as payer. It creates no second cash ledger, wallet balance or real PSP capture. Production or non-mock provider configurations reject this operation.
7. Private statement detail/CSV includes exact minor units and current canonical paid/refunded/net totals and credit notes. A payment or PSP checkout created outside corporate collection after statement issuance changes its derived state to `collection_attention`; the immutable original statement is retained and corporate collection refuses to double pay. A later canonical refund remains a canonical credit note rather than a rewrite of the corporate bill.

Any existing PSP checkout, partial/full payment, unresolved refund request or open dispute excludes an invoice before claim approval, consolidation and collection. All relevant checks repeat under invoice locks. An invoice already on a PSP path cannot bypass that provider through corporate mock payments. Synthetic corporate collection cannot be mistaken for a real bank/card payment. Payment receipts remain readable when an agreement expires; expired terms cannot authorize new claims or statements.

## Authenticated contract

Mount `routes/corporate_billing.php` under the existing authenticated finance web/native groups. Existing CSRF, private/no-store middleware and rate limits remain in force.

| Method | Relative path | Input / response |
| --- | --- | --- |
| GET | `commercial/corporate?fleet_id={id}` | Current member/owner/admin; agreements with acceptance and `can_manage` |
| POST | `commercial/corporate/fleets/{fleet}/agreements` | Admin: `currency`, `minor_units`, `credit_limit_minor`1..1e12, `payment_terms_days`0..365, `billing_day`1..28, `effective_from`, `expires_at`, `legal_name`, `terms`, `idempotency_key` |
| POST | `commercial/corporate/agreements/{agreement}/accept` | Owner: `consent:true`; idempotent immutable acceptance |
| GET/POST | `commercial/corporate/agreements/{agreement}/claims` | Paginated scoped list; create own invoice claim: `invoice_id`, `cost_center_id`, `idempotency_key` |
| POST | `commercial/corporate/claims/{claim}/review` | Owner/admin: `decision:approved|rejected`, `reason` |
| GET/POST | `commercial/corporate/agreements/{agreement}/statements` | Owner/admin paginated list; create: `period:YYYY-MM`, `claim_ids:[…]`, `idempotency_key` |
| GET | `commercial/corporate/statements/{statement}` | Owner/admin private immutable snapshot, lines, optional receipt, canonical credits and derived collection state |
| GET | `commercial/corporate/statements/{statement}/export` | Private CSV attachment, named minor units/precision, formula-safe cost-center code |
| POST | `commercial/corporate/statements/{statement}/simulate-payment` | Original/current owner, mock non-production only: `idempotency_key`; atomic canonical simulated payments |

Lists use existing Laravel25-row pagination. Cross-company resources return404. Driver membership revocation prevents future claims and private company reads; claim submission rechecks and locks the current membership. Organization-wide financial history remains available to current owner/admin. Transferring fleet ownership does not grant a new owner authority to initiate collection using a previous owner's snapshotted consent; such statements require an explicit future payer reassignment policy.

Migration `2026_10_28_200001` adds eight finance-only tables protected against UPDATE/DELETE by SQLite/PostgreSQL triggers. A populated non-testing downgrade refuses to delete financial history. Backups, forward corrections and approved retention procedures are required; do not delete ledger or organization records to make rollback succeed.

## Verification and remaining work

Executed `CorporateBillingTest`:12 tests/132 assertions on the current source, including the additive reconciliation response; final combined regression remains required. Coverage includes two-invoice canonical receipt/ledger reconciliation, replay, privacy, active membership/expiry, immutable decisions, partial-payment atomic rollback, PSP bypass prevention, credit limit, month/duplicate claims, actor authorization and production/provider gates. PostgreSQL process tests supplied in `CorporateBillingConcurrencyTest`: concurrent competing statements cannot exceed company credit limit; identical issue/payment workers share one statement and one canonical cash journal; two explicit fleet-lock wait/owner-transfer cases ensure former owners cannot decide claims or issue statements after ownership changes. Authorization and payer snapshots derive from the fresh fleet row after lock acquisition. They require the disposable PostgreSQL runner; supplied tests are not claimed executed until the root QA evidence records actual results.

This is a functional **partial corporate financial layer**, not completion of all enterprise requirements. Still missing: negotiated company price/commission contracts applied before charging quotes, automatic cycle issuance using `billing_day`, company-level provider checkout/uncertainty recovery, bank/PSP collection and approved credit underwriting, correction/void/rebill policies when driver payments invalidate company collection, corporate-specific credit-note allocation, grace/collection escalation, granular delegated financial-manager roles, monthly statement notifications and full legal acceptance. Existing fleet identities/cost centers are reused; no native screen or operational fleet logic is recreated. `public/corporate-billing.js` now connects the implemented APIs through a lazy, session-fenced EN/AR responsive web panel. The real-Laravel `tests/corporate-billing.cjs` passed administrator publishing, explicit owner consent, driver own-invoice/cost-center allocation, review, selected-claim consolidation, canonical mock receipt, private CSV download and driver company-statement denial. Light/Dark390/768/1440 and Arabic RTL390 screenshots were captured with no horizontal overflow or JavaScript errors. Login retries are restricted to a single explicit429 with numeric Retry-After1..60 seconds; production rate limits are unchanged. Final combined/hosted evidence belongs in the root checkpoint. Real corporate payments, jurisdiction-specific tax/legal validity and regulated credit authorization require external approvals and provider access.


## Exact immutable claim and consolidated collection source (24 November 2026)

Corporate billing now checks raw persisted invoice monetary components
before Eloquent integer casts can hide an invalid source. All nonnegative
minor-unit amounts and every running subtotal, tax, total, outstanding
credit-limit exposure, payment and refund sum must stay within the exact
2^53−1 bound. Every approved claim must match its own canonical invoice
amount, driver, currency and precision before it is consolidated. A
statement whose gross total matches but whose individual tax/subtotal
components differ from the original invoice fails closed with HTTP 409.

The same source validation is shared by private statement detail/CSV,
atomic mock payment and corporate hosted collection. Before preparing
or retrying a hosted checkout or capturing an authorization, the source
invoice lines, existing checkout fingerprint, amount, currency,
precision and exact held invoice set must agree. The established
checkout fingerprint encoding is preserved for existing requests.
Provider cancel/expiry and read-only reconciliation remain independent;
the validation does not silently replace or rewrite a financial record.

New CorporateBillingTest regressions exercise exact money bounds,
synthetic approved claim mismatch, and a forged subtotal/tax split
with the original gross amount, verifying no statement or payment
posting. CorporateHostedCollectionTest checks valid and mismatched
checkout sources without disabling any immutable database trigger.
Both existing SQL immutability and actual hosted TEST lifecycle
regressions remain mandatory in hosted CI. No real financial collection,
underwriting or corporate legal approval is inferred.

Exact-source Finance/Platform CI and Agent 5-only integration are
required. Preserve any independent pending corporate DTO/privacy tests
in PR #248 when reconciling these source additions.


## Agent 3 — external corporate finance DTO privacy (23 November 2026)

All corporate agreement, claim, consolidated statement and simulated-receipt
JSON responses now intentionally omit the service's private **request_key**
and **fingerprint** replay-identity columns. This applies to creation,
acceptance, scoped lists, single-record detail, statement receipts nested
inside a paginated list, and payment simulation responses. Public business IDs,
amounts, evidence status, agreement terms, and existing client schema remain.
The data is removed only from returned DTOs: the original immutable
PostgreSQL/SQLite records continue to retain exact hashed identity and
request fingerprints for idempotency/replay conflict checks.

An end-to-end Laravel feature regression exercises Admin publication, owner
acceptance, driver claim, owner review, one-invoice consolidation and canonical
mock payment while asserting no private replay material is serialized at any
intermediate response. Original hashed rows and canonical invoice journals
remain unchanged and valid. This is an additive privacy/control slice
toward corporate billing and contract governance (4.4.3, 7.1.7, 8.1.6,
8.1.7); the original requirements remain uncredited until complete
cross-platform provider/legal acceptance and Agent 5 integration.


### Literal corporate statement exports

The already authenticated private corporate CSV also marks any
spreadsheet-leading statement, invoice or snapshotted cost-center identifier
as a literal cell, rather than permitting a formula to execute when opened
in a spreadsheet. It sets `X-Content-Type-Options: nosniff` and preserves
the original UTF-8 business identifier unchanged in JSON and append-only
corporate claim/statement records. A feature test deliberately creates a
synthetic cost center beginning with a spreadsheet formula, proves the
text-only CSV neutralization, and confirms no finance journal mutation.


## Immutable corporate statement CSV integrity (Agent 3 follow-up)

The existing owner/Admin-scoped corporate statement export now validates
each archived source line's subtotal, tax and total as **exact, nonnegative
minor-unit integers** within the web/native-safe 2^53−1 range before
serializing CSV. Values already coerced to PHP float/boolean, malformed
decimal strings and over-range values fail with 409; the serializer must
not round an invoice silently or regenerate source documents. The
report continues reading the original corporate statement lines and
does not create receipts, charge a PSP, update finance journals or
change agreements/claim authorizations.

Spreadsheet-literal CSV source identifiers now recognize Unicode
separators, format characters and controls (including nonbreaking
space, zero-width and right-to-left markers) preceding a formula
initiator. Only exported identifiers are escaped; original
statement/invoice/cost-center source values and JSON are not changed.
`CorporateBillingTest` adds exact source and Unicode formula
regressions, retains the actual authenticated corporate CSV/ledger
journey, and verifies no new receipt or journal mutation on read.

This is partial integrity software toward original 4.4.3 / 7.1.7 /
8.1.6, not an authorized corporate credit facility or live money
collection. Exact-head Finance/Platform validation, Agent 5 merge
and comprehensive integrated acceptance remain required.
