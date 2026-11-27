# Vendor revenue by invoice-issuance cohort (simulated-only)

GET /commercial/vendors/{vendor}/revenue-cohorts?currency=AED&from=YYYY-MM-DD&to=YYYY-MM-DD is an authenticated read-only report for the owning vendor or an authorized Admin. The same bounded 93-UTC-day report can be exported at /revenue-cohorts/export as a private CSV. The route ID is limited to 1–18 digits. Cross-tenant requests are 404 and malformed dates/currencies are 422.

**Basis matters:** Each row groups canonical invoice identities by original invoice issuance date in UTC, including invoice subtotal/commission/vendor allocation and all simulated payments, refunds and noncash credits attached to those invoice IDs **as of the query**, even if the payment or refund happened after the issue date. This is not cash flow by transaction date. It is not a revenue-recognition, legal tax, bank or settlement-eligibility determination. The report deliberately displays both gross allocated vendor amount and net vendor allocation; actual payout eligibility, holding periods and carried debts are governed separately by SettlementService.

SQL preaggregates invoice-linked payment, refund and unpaid-credit tables before joining to invoices, preventing cartesian amplification. Currency/minor-unit consistency reuses canonical SettlementService currency policy; a driver-portable DATE(created_at) groups by UTC configured connection, bounded empty-day buckets are explicit, and every aggregate is validated against exact browser-safe integer limits (2^53−1), rejecting unrepresentable amounts before JSON formatting. No PSP requests, ledger writes, refund reservations, payouts or customer PII are produced. Both JSON and CSV are marked SIMULATED_CANONICAL_FINANCE and never as external bank/PSP reconciliation.

This advances only a **partial backend/data-contract component** of original requirement 3.6.2 (vendor revenue dashboard). Responsive vendor web and native dashboard visuals, verified real provider data and production acceptance are still missing.

## Web workspace

The existing bilingual Vendor/Admin financial workspace exposes an accessible report form selecting a vendor, ISO UTC invoice-date window (default past 30 days) and currency. The UI validates source provenance, vendor identity, currency and its no-live-money/no-payout flags; renders issuance/collection/vendor-allocation totals as literal text, daily rows and a private same-origin CSV link. Editing filters clears stale results; account/session changes cancel pending reads through the workspace's epoch and AbortController. This is a read-only web integration and does not add Flutter-native graphs or production financial acceptance.

## Live-account authorization and fail-closed refresh (Agent 3 handoff)

The JSON and CSV endpoints now validate the persisted active role, suspension state, password hash and authentication epoch against the authenticated request snapshot under a transaction-scoped account row lock. A stale owner or formerly privileged Admin session receives 409 before any cross-vendor aggregates or CSV bytes are produced. The lock is held while constructing the read-only report, including the stream-export snapshot, and no financial mutation is performed. Tests explicitly suspend, demote, rotate epoch and change password through SQL while a Laravel web actor remains cached; JSON and CSV denials, restored-session access and unchanged journals are checked. The bilingual Vendor/Admin web report now erases previous report totals and its private CSV link before each new read; a 409 or other failure leaves no stale private report rendered. A Playwright denial fixture verifies this alongside the existing currency/date, export and RTL flows. These changes require fresh exact-head Laravel, PostgreSQL, JavaScript/browser and full CI validation before Agent 5 integration. They do not claim production payouts, bank reconciliation, legal tax revenue, or hardware acceptance.


## Canonical Flutter Vendor revenue dashboard — Agent 3 candidate (23 November 2026)

The existing **Vendor Finance** source-currency group now opens a bilingual English/Arabic
native **Revenue by invoice date** dashboard for that same currency. Its only
account-verified source is the existing authenticated
`GET /commercial/vendors/{vendor}/revenue-cohorts` endpoint. The widget
asks the canonical API for the current account, requires the Vendor role,
queries the account's own vendor ID and binds every result to the original
account session generation and exact requested currency/UTC period.

The native parser independently enforces the canonical invoice-issuance cohort
basis, lifetime posting attribution, explicit simulated-only/no-real-money
labels, daily ordered contiguous UTC dates, 93-day maximum, correct vendor
ownership and currency precision, exact 2^53−1-safe integer totals, and the
original net collection/vendor-allocation accounting arithmetic. Any malformed,
foreign, mismatched, inflated, or live-verified projection fails closed. The
visual view provides 30-day default UTC filters, current issuance/collection/
refund/net-vendor totals and daily read-only activity bars; no client-derived
settlement eligibility, bank proof or payment mutation is introduced. Private
data is erased on filter edits, inactive lifecycle, session/API replacement,
and stale responses never repopulate it.

A dedicated Flutter test suite covers integer/precision and evidence rejection,
tampered totals, date identity, account gating, Arabic UI, invalid-filter
erasure, session expiry and background stale-reply suppression. Integration
needs exact-head hosted Flutter analysis, widget tests, Android/iOS builds,
and canonical Laravel/PostgreSQL + browser regression before Agent 5 acceptance.
This is a **functional native dashboard candidate toward 3.6.2**, but no
verified live PSP revenue, legally recognized revenue, bank reconciliation, or
production acceptance is claimed. Keep the original atom uncredited until
all required software evidence is integrated and audited.


### Real-Laravel native source contract

The existing owned local finance fixture runner `tests/native-financial-analytics.cjs`
now executes `live_vendor_revenue_cohorts_test.dart` sequentially after the
existing native financial analytics contract, using the same ephemeral
synthetic vendor/customer/foreign accounts. It reads real Laravel HTTP
responses through `EvApi`, rejects customer/foreign vendor access,
confirms the vendor's immutable issued-and-collected cohort evidence,
parses an empty older period without inventing revenue, rejects
malformed UTC dates and proves the original customer invoice payment,
refund and ledger balance remain unchanged. No live payment or production
provider acceptance is implied. The added test requires the full owned
loopback fixture and exact-head hosted execution; it is not a production test.

### Plain-browser issuance cohort export safety

The canonical Laravel cohort CSV now handles invalid period/currency form GETs
with an explicit HTTP 422 private/no-store JSON error instead of Laravel's
default browser redirect. It retains the same authenticated owner/Admin scope,
source-only issuance-date semantics, 93-day bound and stable row ordering
as the native/web JSON report. UTF-8 CSV uses explicit RFC quote/escape
controls and `X-Content-Type-Options: nosniff`. A new feature test
covers malformed uppercase currency, invalid calendar dates, reversed or
oversized UTC periods and confirms no invoice or journal change on rejection.


### Additional native vendor invoice-earnings CSV parity

The already implemented canonical `GET
/commercial/vendors/{vendor}/earnings/export` now has a strictly bounded
**native copyable CSV preview** in `VendorEarningsView`, alongside the
existing paginated invoice source. This is a viewing/copying surface, not
a persisted file, device share, tax invoice, bank statement or transfer.

- Flutter's `EvApi.downloadVendorEarningsPage` uses the same authenticated
  cookie/native session as the JSON report and pins the exact vendor,
  uppercase currency, current requested page 1–10,000 and `per_page`
  1–25. It refuses non-CSV MIME and bodies over 64 KiB.
- `VendorEarningsCsv.verifiedText` parses quoted UTF-8 RFC-style CSV
  and requires the exact original canonical 18-column schema, exact row
  count, same original invoice IDs, numbers, currencies, precision,
  evidence and every integer amount as the previously validated and
  loaded JSON snapshot. Formula-leading invoice identifiers must match
  the literal-cell prefix from the existing server CSV sanitizer.
- A source-changing or inexact/mismatched export is **never displayed**.
  The viewer holds a copyable in-memory string only, clears it on
  refresh, pagination, account/API change, screen disposal and
  background transition; late downloads from revoked sessions cannot
  repopulate it. No permanent file or real payment action is created.
- Pure CSV parser regressions cover commas/quotes, `=,+,-,@` spreadsheet
  identifiers, invalid UTF-8, wrong vendor/page/source, incorrect
  amounts and header/order, over-limit files and empty pages.
  Widget tests cover view/refresh, native page identity, rejected
  tampered CSV and stale account downloads. The existing genuine
  Laravel loopback financial fixture now requests, verifies and checks
  the real CSV bytes for the logged-in vendor and rejects customer/
  foreign downloads (no journal changes).

This contributes a partial native-source slice to original 3.5.9 and
7.1.10 alongside the revenue-cohort 3.6.2 candidate. All remain
**uncredited until exact-head Flutter/Android/iOS/Laravel acceptance and
Agent 5 integration**; no independent tax-law or provider certification.


## Immediate account-change privacy on rendered native revenue

The native `VendorFinanceView` payouts overview,
`VendorEarningsView` owner invoice/CSV preview and
`VendorRevenueCohortView` daily income can each finish rendering
*before* an account is logged out. Rechecking their captured
`sessionFence()` only when the next widget build happens does
not itself trigger repaint, and can leave confidential amounts,
a CSV affordance or finance navigation visible on screen.

This branch now adds the **same non-secret synchronous
`EvApi.sessionEvents` generation broadcast** as Agent 3 PRs #243,
#265 and #272. Each of the three vendor finance views observes its
current API, immediately clears already painted owned finance
source/CSV/PDF and pending spinners on generation invalidation,
and detaches the observer on client swap or disposal. An old
HTTP response cannot resurrect data because its request generation
is invalidated; no automatic payout, CSV saving or remote settlement
is initiated.

Three real Flutter widget regressions log out **after** the
original vendor finance overview, a 1-page owner earnings report,
and daily cohort total were completely rendered. Without waiting
for another request or building a new app widget, the tests require
private amount/CSV/control elements to disappear and confirm no
additional HTTP request was sent.

The additive API event implementation should be integrated **once**
by Agent 5 when the independent native PRs are merged, retaining
each domain's three native listeners. This is still TEST financial
source and has no real bank/PSP collection or payout authority.

## Browser revenue cohort source parity

The existing browser dashboard verifies the same exact vendor, currency, UTC period, evidence, precision, daily dates, bounded source amounts, row net arithmetic and reconciled aggregate totals before rendering any report. Integer arithmetic uses BigInt to avoid intermediate browser rounding; optional server UTC cutoff is displayed without inventing one for older responses. All eleven source metrics are visible in the period totals and expandable daily breakdown. The existing private CSV endpoint rechecks the selected period on download; the UI does not imply byte identity with an earlier snapshot. A detached or hidden report cannot dispatch a new read or CSV action. The existing financial-analytics browser suite runs the exact parser against valid and malformed synthetic source rows before its connected tests. No new revenue recognition, settlement eligibility, money movement or production acceptance is introduced.
