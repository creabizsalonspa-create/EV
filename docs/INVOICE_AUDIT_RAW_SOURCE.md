# Internal ledger diagnostic: raw source and reversal checks

This batch extends the existing canonical invoice reconciliation diagnostic
(`3.5.9`, `7.3.8` partial). The immutable invoice model's ordinary integer
casts are not acceptable evidence validation; the audit now verifies the raw
hydrated invoice values, including identity and currency precision, before
using them. Derived net and due values must also be browser-safe exact
integers. A zero or negative payment, negative refund component, partial
unpaid reversal or mixed cash/noncash-credit history is classified using
existing audit findings, retaining the web/native report contract.

The canonical unpaid-credit service only permits one full reversal of an
untouched TEST invoice. The audit never repairs or changes financial records,
does not issue credit, and does not contact a bank or PSP. Tests hydrate a
malformed in-memory invoice snapshot without disabling immutable database
triggers, and insert a synthetic zero payment to show that balanced sums alone
do not establish a valid collection. Exact-head Finance and Platform CI,
including PostgreSQL, remain mandatory. No external reconciliation acceptance
or complete atom is asserted.
