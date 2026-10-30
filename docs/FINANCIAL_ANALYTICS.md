# Financial source analytics and management exports

This is a role-scoped reporting workflow over the existing financial source, not another ledger. It never authorizes money movement, recognizes legal revenue or changes invoices, receipts, refunds, points or settlements. Customer/vendor/Admin web controls provide period/currency filters, comparative activity meters, current operational states, current corporate aging, paid-cycle recurring equivalents and private CSV/PDF downloads. English/Arabic, RTL and the existing light/dark/system design tokens are preserved.

## Scope and time semantics

Customers see their own charging/subscription postings and their currently owned company's consolidated billing. Drivers do not receive company-wide figures. Vendors see their stations' charging activity, allocations and instructions, plus their own subscription costs; another vendor's information is excluded. Admins see platform-wide data. Changing `user_id` or `vendor_id` is prohibited, not a way to select another account. Counts are source-record counts; corporate aging counts underlying statement lines. No personal/provider/banking references are exported.

`from`/`to` are inclusive UTC dates, defaulting to the last30days, with a maximum366days and no future end date. The previous equal-length period is explicit. Monetary postings are filtered by their own posting timestamp: a refund this month against last month's invoice belongs to this month. Future-dated source rows are excluded at the captured `as_of`. All amounts come from one SQL statement/snapshot: payment/refund detail rows are never joined to one another, preventing multiplicative totals. Current instruction states and current receivable aging of period-created statements are explicitly **not historical state reconstruction**.

Facts remain separate: issued invoices, captured receipts, refunds, original/reversed allocations, settlement instructions, payout instructions, provider disputes and consolidated company references. Rejected settlements are instructions, not reserved payable. A company statement references original charging invoices; summing it again as revenue would double count. Provider `paid` on a TEST payout is not proof of real bank money. Lost disputes are reported as exposure requiring accounting review, not silently posted chargebacks.

Corporate aging uses each source invoice's collected amount and exact due timestamp, with not-due,1–30,31–60,61–90 andover90-day buckets. Refunds do not silently create a new charging receivable. Company collection/credit adjustments and legal arrangements remain owned by the canonical corporate workflow.

Each currency/precision/evidence/state is grouped separately. Receipt/credit currencies (and credit precision) must match their immutable subscription invoice or reporting fails409. Totals must fit both exact PHP and JavaScript integer bounds (maximum9007199254740991); narrowing the period/currency is required rather than losing precision. Zero/three-decimal currencies retain their source scale.

## Recurring and points interpretation

`recurring_run_rates` are **current paid-cycle equivalents**: current active, non-cancelling paid cycles, excluding tax and net of posted credits. Monthly plans annualize by12; annual plans divide by12. Rounding is half-up once per currency/precision/evidence group. Amounts do not imply recognized revenue, guaranteed future collection or historical MRR reconstruction. For customer/vendor views these are their own platform subscription costs, not their business revenue. This definition deliberately avoids inventing revenue-recognition or forecast policies.

`points` are a separate `NON_MONETARY_POINTS` unit with `cash_value:false`. Posted earning/redemption/release/expiry/reversal movements are never represented as a currency or wallet. Currency filters apply to money, not the global promotional unit. Pending, not-yet-posted expiry/reversals are not invented; use the loyalty account/reconciliation API for spendability. No monetary loyalty liability valuation is asserted.

## Additive API

Mounted under existing authenticated cookie/CSRF `/api` and Sanctum `/api/v1`. Production authentication/rate limits are unchanged.

| Method/path | Contract |
| --- | --- |
| GET `financial/analytics` | Optional `from`, `to`, `currency`; returns `groups`, `points`, `recurring_run_rates`, bilingual label dictionaries, authorized scope, UTC period/comparison and `as_of` |
| GET `financial/analytics/export` | Same filters; private UTF-8 BOM CSV with distinct money, noncash-point and recurring-equivalent sections |
| GET `financial/analytics/pdf` | Same filters plus `locale:en|ar`; private actual management PDF using embedded licensed Arabic fonts; maximum600rendered groups including recurring components |

Money group: `{period:current|previous, category, currency, minor_units, state, evidence, record_count, amount_minor}`. Points group: `{period,kind,entries,points}`. Recurring group: `{currency,minor_units,evidence,subscriptions,annual_equivalent_minor,monthly_equivalent_minor}`. Both exports are generated from current authorized source, not a stored legal invoice; no public file links or external rendering/font fetches are used. Export/view actions produce distinct `finance.analytics.view|export|pdf` audit events. Responses are `private,no-store`; CSV text is formula-neutralized, including leading whitespace/control/bidi characters. PDFs use a static escaped template with no user HTML or URLs.

Errors:401/419expired session,403role,422invalid/oversized date range or actor selection,409source integrity/exact bounds/PDF group cap,429throttle. Never retry a payment from this screen; it has no payment mutation.

## Verification and remaining scope

`FinancialAnalyticsTest` exercises source sums, multiple refunds without duplicate captures, customer/vendor/native-v1 scope, posting-time comparisons, exact current corporate aging, paid-cycle/credit/cancellation math, zero/three-decimal grouping, noncash points, actual private Arabic PDF/CSV, malformed imported currency, JSON bounds and future-date exclusion. `tests/financial-analytics.cjs` uses actual Laravel for filters, amounts, privacy, exports, current-language download, comparative meters and representative light/dark/RTL layouts. Synthetic fixtures and mock/provider-contract evidence are labeled.

Missing broader software remains explicit: recognized/deferred revenue policy, authorized live-provider/chargeback accounting, full historical mutable-state reconstruction, arbitrary financial BI/drilldown, legally approved valuation/retention rules and production operational acceptance. Campaign billing uses the existing subscription source; do not add campaign reservation units as extra cash revenue. Full original financial scope is not declared complete by this reporting slice.
