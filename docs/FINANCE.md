# Finance implementation and evidence

This module implements **simulated billing and accounting**, not live payment processing, legal tax invoicing, or vendor payouts. Every issued document and money event carries `SIMULATED` evidence; invoice/credit-note numbers start with `SIM-`. Production invoice creation, simulated payments, and simulated refunds are rejected. No payment card data, PSP API key, or live provider call is used.

## Domain flow

1. An owning vendor or administrator appends a station tariff version; only administrators can set a nonzero commission. Versions specify integer rate per kWh in the currency's minor units, tax basis points, commission basis points, currency precision and effective time. The API does not accept backdated effective times. Tariffs are restricted to simulator inventory.
2. At session start, the caller selects `FinanceService::activeTariff($station)`, uses its rate (or the legacy test rate 100) for the charging session, then calls `snapshotSession($session, $tariff)`. The quote records customer/vendor/country/currency/precision/rate/tax/commission immutably. Later tariff changes cannot reprice that quote. Legacy sessions without a start quote use their captured session rate with zero simulation tax/commission; they never acquire newly published tariffs retroactively.
3. Only stopped simulated sessions can issue an invoice. Energy × rate is rounded half-up using integer math; the resulting subtotal must equal the finalized session total. Tax and platform commission are rounded independently, commission applies to pretax subtotal, and vendor amount is subtotal minus commission. The invoice is unique per session.
4. Invoice posting debits customer receivable and credits vendor payable, platform commission and tax payable. Simulated payment debits simulator cash and credits receivable. Explicit idempotency keys prevent duplicate posting and reject payload changes.
5. Refunds require owning vendor/admin authority and cannot exceed captured simulated funds less prior refunds. Each refund creates an immutable credit note and reverses the relevant revenue/tax/liability components against simulator cash. Integer largest-remainder allocation uses the remaining invoice components, so partial refunds never create negative allocations or lose minor units and a full refund reverses all original components exactly. Credit notes reduce the invoice while the cash refund reduces collections; an already-paid invoice does not become payable again solely because it was refunded.
6. Journal posting checks equal debits/credits and the expected amount before inserting. Immutable-record model guards and database triggers prohibit updates/deletes of tariffs, quotes, invoices, journals, journal lines, payments and refunds on SQLite and PostgreSQL. Corrections require appended documents/events. PostgreSQL triggers are supplied but have not been executed against PostgreSQL in this module's current local evidence.

This is an accounting foundation, not a guarantee of legal accounting/tax suitability. Direct database insert permissions must remain restricted to the trusted application; additional production DB constraints/privilege review are still required. Vendor payable accruals are not evidence of collected or payout-eligible funds. Settlement eligibility and payout reconciliation are not implemented here.

## HTTP contract

`routes/finance.php` contains unprefixed routes; its parent must apply authentication, throttling and the `api` (web session) or `api/v1` (Sanctum native) prefix. Web mutations retain Laravel CSRF protection.

| Method / relative path | Access | Payload / result |
|---|---|---|
| GET `stations/{station}/tariffs` | Owning vendor/admin | Paginated immutable versions |
| POST `stations/{station}/tariffs` | Owning vendor/admin | `rate_minor_per_kwh` 0–1,000,000, `tax_bps` 0–10,000, `commission_bps` 0–10,000 (vendor must use 0); creates immediately effective simulation version |
| GET `finance/invoices` | Customer own / vendor own / admin | Paginated invoices |
| POST `finance/sessions/{session}/invoice` | Session owner / owning vendor / admin | Idempotent by session; returns invoice summary |
| GET `finance/invoices/{invoice}` | Owner / owning vendor / admin | Invoice, payments, credit notes, integer totals, ledger balance and explicit simulation/provider labels |
| POST `finance/invoices/{invoice}/simulate-payment` | Invoice customer | `amount_minor`, `idempotency_key`; positive payment up to unpaid total |
| POST `finance/invoices/{invoice}/simulate-refund` | Owning vendor/admin | `amount_minor`, `idempotency_key`, `reason`; captured-funds bound |
| GET `finance/invoices/{invoice}/journal` | Owning vendor/admin | Immutable journal headers/lines |

Summary fields include `paid_minor`, `refunded_minor`, `net_collected_minor`, `credit_notes_minor`, `amount_due_minor`, `state`, `ledger_balanced`, `payment_provider: SIMULATOR_ONLY`, and `real_payment_processed: false`.

## Executed verification

From `/workspace/EV`: `./scripts/php artisan test --compact --filter=Finance`.

Current focused result: **13 passed, 4,994 assertions**. The large assertion count includes exhaustive small-integer component/allocation cases, not thousands of separate end-to-end flows.

- Unit coverage: half-up rounding, overflow rejection, invalid component rejection; all small three-component refund allocations preserve amount, component bounds and exact final reversal.
- Feature coverage: stopped-session reconciliation, captured tariff/quote immutability, database rejection of direct invoice mutation, no retroactive tariff selection, idempotent invoices and partial payments/refunds, overpayment/overrefund/reused-key rejection, tenant access, vendor commission restriction, and disabled production simulation.
- SQLite is the executed database. PostgreSQL concurrency and migration triggers, external PSP checkout/webhooks, real refunds/disputes, legal invoice/credit-note review, payouts/settlements, subscriptions and billing-grade OCPP/OCPI CDR reconciliation remain unverified/unimplemented.

The complete product roadmap remains mandatory. This module does not independently satisfy finance, commercial or full-platform release acceptance.

## Finance review additions

Numeric ID and money attributes are cast to integers by the immutable model base so HTML numeric strings, strict snapshot comparisons and JSON contracts behave consistently across supported database drivers. A regression test creates tariffs from numeric-string fields and snapshots one without a false rate mismatch.

Payments/refunds lock an invoice, while request keys are actor-scoped across invoices. Two distinct invoices can therefore race on the shared journal request-key constraint. The service now rolls back a uniqueness failure, re-reads the committed journal and returns the existing result or a typed 409 payload conflict; it does not turn unrelated database errors into success. The journal's unique constraint remains the final duplicate-posting defense.

`tests/Postgres/FinanceConcurrencyTest.php` is an explicit, destructive-to-test-data integration suite intended only for a disposable PostgreSQL database with `APP_ENV=testing` and `DB_CONNECTION=pgsql`. It synchronizes independent PHP processes and temporarily delays journal insertion to reproduce cross-invoice payment/refund key races. It is outside the default SQLite suite. The worker is `tests/Support/finance-concurrency-worker.php`. Example command after supplying an isolated test DB/runtime with PDO_PGSQL: `php vendor/bin/phpunit tests/Postgres --testdox`.

The reproducible isolated runtime/runner is retained in `tests/Postgres/Dockerfile` and `tests/Postgres/run-finance`. The runner creates only a uniquely named disposable database/network/image, keeps generated credentials in a private temporary file, preserves command exit codes in evidence logs and removes its own resources on exit. It never reuses the business or CSMS database. Tests additionally require a PostgreSQL testing database name ending in `_review`, `_test` or `_testing` before migration operations.

The isolated review PHP/PostgreSQL runtime was prepared with TLS verification intact, but PostgreSQL initialization failed with `No space left on device` before those concurrency tests could execute. The failed empty review database and its temporary images/cache were removed; existing CSMS/business data was untouched. **PostgreSQL migration/concurrency is not claimed verified.** The SQLite finance regression suite passed 14 tests / 4,999 assertions after these changes. Its large assertion count still reflects exhaustive small-integer allocation checks, not thousands of independent business workflows.

A later resource-expanded PostgreSQL attempt reached the runner but found a readiness bug: the temporary initialization server accepted a Unix-socket `pg_isready` probe before the final TCP server started. That run had 3 pure money tests pass and 11 feature setup errors (connection refused), with no PostgreSQL finance migration/concurrency success. Readiness now explicitly probes TCP; a corrected rerun was canceled at the 2 GiB free-space safety floor during cached image/container setup. Therefore PostgreSQL acceptance remains unverified. The runner now gives its PHP container an explicit unique name and cleans it on termination as well as normal exit, preventing canceled container creation from leaving an anonymous test resource. No unchanged local retry was treated as a passing result.

## Integrated PostgreSQL verification

The full-stack continuation executed the isolated runner successfully after correcting CREATE OR REPLACE for the immutability function (migrate:fresh retains PostgreSQL functions). Finance: 14 tests / 4,999 assertions. Concurrent cross-invoice payment/refund key races: 2 tests / 26 assertions. No duplicate journal posting/refund occurred; conflicting payloads return conflict. Reports: evidence/fullstack-postgres-finance.txt and evidence/fullstack-postgres-concurrency.txt. This supersedes the earlier unverified PostgreSQL checkpoint for these tests only; full production concurrency/load and deployment acceptance remain open.

## Web/native read-only history

Web Invoice history supports all authenticated roles; native Account → Invoice history supports customer/vendor roles. Both use scoped numeric pages and invoice-prefix/currency/UTC date filters, then GET existing document detail without reissuing it. Detail includes recorded simulated collections/refunds; vendor/admin web additionally shows immutable allocation snapshots explicitly before refunds and without payout claims. Logout/expiry removes private records. Read-only UI acceptance is distinct from payment checkout, refund mutations, PSP integrations and commercial settlements, which remain unfinished. Tests: FinanceTest history filter/privacy cases, `npm run test:invoices`, native invoice/history tests and opted-in real-Laravel smoke.

## Versioned pricing interfaces and commission authority

`GET finance/stations` lists only owned simulator stations for vendors and all simulator stations for admin, with current currency precision or null when regional configuration does not match. Customer-role company staff are not implicitly granted finance permissions. Tariff history returns additive `pricing_context` (current currency, minor_units, provenance); the old pagination remains. Native Account → Simulated pricing is vendor-only; Super Admin pricing remains web-only.

Vendor publication must still send commission_bps=0 as its permission sentinel; the server now retains the current commission policy. Only an admin may change that policy. Clients publish decimal prices/tax percentages through exact integer conversion, send a fresh intent identity and expected currency/precision, and retain history/inputs on appropriate failures. Existing session quotes and invoice allocations remain immutable. These are simulated price versions, not legal tariff approval or settled earnings.

Additive migration 080001 adds nullable publishing hashes to existing tariff versions without modifying old records. Rollback drops only the new unique index/columns; preserve database backups and immutable finance. Test evidence includes committed lost-ack replay and PostgreSQL concurrent same-station publication. Region and station locks protect current precision and commission; unique publishing identity binds actor/station/payload.


## Recurring authorization continuation — 28 October 2026

Saved provider-hosted consent, off-session subscription collection and customer/vendor web/Flutter controls extend the canonical financial gateway. See [RECURRING_PAYMENTS.md](RECURRING_PAYMENTS.md) for scoped endpoints, versions, scheduler, recovery, migration and test-mode limits. No live merchant acceptance or full-platform completion is claimed.
