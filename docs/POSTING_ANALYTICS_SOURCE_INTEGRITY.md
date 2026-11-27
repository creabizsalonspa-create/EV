# Posting analytics: exact source and corporate receivable integrity

The canonical financial analytics report, CSV and PDF now validate individual
nonnegative monetary facts inside the same SQL aggregate as their totals.
SQLite non-integer storage, out-of-bound facts and invalid precision fail closed
instead of disappearing into a plausible total. Charging payment, refund and
unpaid-credit facts additionally require their immutable journal identity,
currency, kind and amount, plus valid reversal components where applicable.
Dispute amounts must retain their original checkout denomination and bounds.

Corporate receivable payment aggregation is limited to the authorized selected
statement lines and checks canonical invoice denomination and payment journal
identity even if the apparent outstanding balance would otherwise be zero.
Invalid fully paid or overpaid lines fail closed rather than being suppressed.
The existing charging and corporate categories remain separate facts; no
external bank or PSP verification, accounting repair or new financial ledger
is introduced. Query output is bounded at the existing 2500-group limit.

Two Laravel regressions exercise imported payment/journal contradictions,
JSON/CSV/PDF denial, tenant isolation and corporate receivable false-zero
prevention. Exact-head Platform/Finance checks and Agent 5 integration are
required. Partial 3.6.2, 3.5.9, 8.1.6 and 7.3.8, no whole-atom credit.
