# Native vendor revenue cohort CSV parity

The current Vendor Revenue by invoice date view requests the existing authenticated cohort CSV endpoint with the exact accepted vendor, currency and UTC period. Bounded binary MIME and size checks are followed by strict UTF-8 and CSV parsing (up to 93 days plus header). Every column and cell must match the accepted immutable JSON report. Changed snapshots fail closed. No file is persisted or shared automatically.

The preview clears on filter edit, refresh, session invalidation, client replacement and background transition. Current-session expiry uses the canonical generation-aware predicate. Existing earnings/audit CSV row caps remain unchanged by default.

Pure parser, Flutter widget and local Laravel contract regressions added. Partial 3.6.2/3.5.9/7.1.10, no bank/PSP acceptance. Agent 5 owns integration; coordinate native with Agent 4. Exact-head validation pending.

Follow-up: daily and total economics are independently checked for collection, reversal, vendor/commission allocation and zero-invoice consistency using exact subtraction comparisons. A conflicting export removes the previously accepted report and requires a fresh JSON read. Additional parser and lifecycle widget regressions cover internally consistent forged sums, backgrounded CSV, real current credential expiry and superseded expiry. These are candidate checks, not a hosted test pass.


Follow-up: Native dashboard cards now expose the full verified source breakdown
for period totals and each UTC day, including invoice counts, gross vendor and
commission allocations, captured/refunded funds, unpaid credits, vendor reversal
components and net collection. All display is derived from the immutable report,
not recalculation or a new request. The bilingual compact daily cards now show
net collection and invoice counts alongside issued/vendor amounts. If the
companion #374 as-of metadata is present, its UTC instant is validated and shown;
older reports retain no fabricated cutoff. Added parser and expanded-dashboard
widget regressions, including private clearing on logout. Hosted testing pending.
