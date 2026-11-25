# Native vendor revenue cohort CSV parity

The current Vendor Revenue by invoice date view requests the existing authenticated cohort CSV endpoint with the exact accepted vendor, currency and UTC period. Bounded binary MIME and size checks are followed by strict UTF-8 and CSV parsing (up to 93 days plus header). Every column and cell must match the accepted immutable JSON report. Changed snapshots fail closed. No file is persisted or shared automatically.

The preview clears on filter edit, refresh, session invalidation, client replacement and background transition. Current-session expiry uses the canonical generation-aware predicate. Existing earnings/audit CSV row caps remain unchanged by default.

Pure parser, Flutter widget and local Laravel contract regressions added. Partial 3.6.2/3.5.9/7.1.10, no bank/PSP acceptance. Agent 5 owns integration; coordinate native with Agent 4. Exact-head validation pending.
