# Revenue cohort as-of and source validation

The existing invoice-date revenue dashboard and CSV now capture one UTC
query cutoff, excluding future-dated invoices and lifetime postings until
that cutoff. A half-open UTC calendar boundary replaces the inclusive
microsecond upper bound. The original 93-day scope and empty-day contract
remain intact.

Per-invoice posting subqueries validate journal identity, denomination, kind
and amount, reversal components and noncash-credit lineage within the same
SQL snapshot as the daily totals. Per-invoice collection and vendor reversal
limits prevent a corrupt invoice from hiding inside the aggregate for another
invoice. Exact SQLite source types are verified before aggregation. Invalid
source fails closed for JSON and CSV; no ledger mutation or provider call.
The response adds an as-of timestamp without changing CSV columns.

Two synthetic feature tests cover future same-day source exclusion and
wrong-denomination imported payment rejection. This is partial 3.6.2/3.5.9;
preserve Agent 5 #247 native/client integration and existing #372 independent
posting-time analytics. Hosted validation pending.
