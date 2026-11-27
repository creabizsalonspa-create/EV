# Bounded private campaign review

The canonical management list accepts optional status, country, vendor, station
and subscription invoice filters. They intersect the existing current-account
scope: a vendor cannot use a filter to enumerate another owner's campaigns.
An applied scope echo is additive to the original paginator. Campaign,
package, station and delivery-detail pages validate explicit bounds (page
1–100000, size 1–25), retaining default size 25 for existing clients.

Detail reads retain the campaign row lock used by canonical writers until
control history and publisher receipts are read. Excessive control history
(above 1000 events) fails closed rather than returning a truncated revision
chain. Both publisher counters are read in one aggregate statement and
checked against the reserved budget; an impossible ratio fails closed.
Private delivery receipts expose internal identity and occurrence times, not
publisher idempotency keys or fingerprints. Creation and recovery receipts
are marked private/no-store.

Added regression tests for scoped list filtering, bounds, tenant denial and
private publisher history. No publisher operation, money movement, new
financial system or production acceptance. Filters are API-level in this
batch; client filter controls require subsequent integration. Agent 5 owns
canonical integration. Exact-head hosted CI remains required.
