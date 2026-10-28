# Native loyalty final local validation

Full Flutter **135 passed / ten explicitly gated live-only skips**, clean analysis. Seven new focused widget tests and one separate real-Laravel native integration passed; the latter is excluded from default tests unless its isolated fixture path is supplied. No skipped test is counted as a pass. Native financial calculations/mutations are unchanged; this is read-only API parity. Hosted Android/iOS and current candidate integration remain pending.

# Native loyalty API consumption

Customer/vendor account menus open **Points and rewards** in the canonical Flutter client. The view reads the existing PR18 account, paginated ledger, current reward catalog and own redemption history/detail endpoints. It displays server-provided noncash unit/evidence, posted negative points debt, conservative available points, reconciliation/hold flags, per-currency tiers, bilingual reward descriptions/stock and pending/manual fulfillment status. It does not calculate economics, convert points to money, post earning/redemption or imply benefit delivery.

Only allowlisted fields render. Unsupported monetary/transfer/evidence flags and malformed points fail closed; unknown ledger/status codes get a localized neutral label. Refresh and pagination are GET-only. Errors remove private rows and expose localized status classes; current401/419 expires the owning session, while disposed/older reads cannot affect a new screen. EN/AR RTL, existing themes and narrow layouts are preserved.

Seven focused widget tests pass: debt/hold/tier, false monetary semantics, ledger paging/safe labels, Arabic390px catalog/history, pending request audit, session expiry and disposed response fencing. A separate real-Laravel native test passes canonical70-point account/tier, ledger100/-30, remaining reward stock, pending owner history and foreign/vendor404; synthetic seed uses existing canonical FinanceService/LoyaltyService only. No financial business rule/schema change. The seed accepts create-only before loading the existing guarded local/mock SQLite fixture.

Connected CI retains all eight previous suites and adds loyalty in a third three-login batch after a fresh61-second window. Authentication10/IP/minute is unchanged; no cache clearing or assertion retries. Expanded iOS TLS-fixture UI must render points and reward catalog with an explicit synthetic-economics marker. Host guards reject zero-exit/missing-marker runs. This candidate needs exact-source hosted Android/iOS/full regression and merge before completion credit.

Native earning/redemption/reconciliation actions, payout/corporate/dispute/document parity, coupons/referrals/promotions/advertising and real reward fulfillment remain unfinished. These read-only views do not earn the broader complete-native-commercial atom. Production mutations remain disabled and no live legal/merchant/reward-partner acceptance is inferred.
