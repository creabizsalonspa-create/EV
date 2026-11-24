# Durable partner reward claim intent cancellation

Partial software for original partner reward governance and native commercial
parity. The previous native "clear saved claim" action erased a device
request even if an outstanding network request could still commit; the
browser also discarded its key after some conflicts. Neither provided a
server-side guarantee that a replacement would not duplicate the intent.

The new authenticated customer endpoint
`POST /commercial/partner-rewards/claim-intents/cancel` accepts
`{review_id,idempotency_key}`. Under the same current member lock as
claim creation, it returns the original committed claim if one already
exists (state accepted), or appends a durable immutable cancelled intent
(state cancelled). A delayed or retried claim with that key cannot reserve
a reward after cancellation. Changed offer identity conflicts. Different
members' keys remain independently scoped. The endpoint does not reserve
stock, create points, approve fulfillment, edit existing claims, send
provider requests or move money. It remains available to resolve existing
intents when synthetic offer activation is disabled.

The response includes the exact review ID, state, claim or null, and
a SHA-256 correlation binding over the domain, review and hashed
member-scoped request identity. It is not an authentication credential or
financial authority; raw replay keys are omitted. Browser and Flutter
must verify the correlation, preserve the durable key on malformed or
uncertain acknowledgement, and display the actual prior receipt if the
claim had committed first. The native view also clears private data on
session invalidation/background, preserves the opening account identity,
and uses the exact shared #265 secure-storage acknowledgement fence.
No automatic replacement or replay is introduced.

The new additive migration protects cancellation evidence from update/delete
on PostgreSQL and SQLite and refuses destructive populated non-test
downgrade. Existing reward/claim records are unchanged.
Laravel tests cover cancellation-first, accepted-first, same-key replay,
changed review, tenant separation, disabled activation, no reward/points/
cash side effects and immutable evidence. Flutter tests exercise incorrect
binding retention and committed-claim recovery. Hosted browser/native/
finance validation and Agent 5-only integration are required. Preserve
the identical shared financial_operation_store.dart source from #265 and
#325. No live partner supply, legal terms or production acceptance is
claimed.
