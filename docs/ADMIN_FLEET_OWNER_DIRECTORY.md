# Admin fleet ownership and inventory directory

This Agent 7 change improves **private, read-only Super Admin fleet governance**
using the existing `GET /api/admin/fleets` route and existing FleetReport
controller. Owner/driver fleet creation, role changes, charger commands,
billing, payments, and real-world physical charging are unchanged.

## Admin directory contract

- `q` — 80-character maximum, case-insensitive **literal** fleet name search
  (SQL `%`, `_`, and `!` are not wildcard operators in user text).
- `country` — optional uppercase ISO alpha-2 country filter.
- `owner_id` — optional exact numeric owner ID.
- `page` — existing bounded paging, 25 fleets per page.

Filters apply before pagination. The original endpoint response remains
**exactly** the existing four-field fleet record (`id`, `name`, `country`,
`created_at`) for existing clients. Only an explicit
`include=owner_inventory` query opt-in adds owner identity (id/name/email)
and current inventory counts for active members, assigned vehicles and cost
centers. The Admin web view always opts in; other consumers do not silently
receive expanded private identity data. Counts are current administrative inventory, **not**
evidence of charging-site physical availability or financial settlement.
No password, bearer credential, private KYC, session secret or driver profile
is included.

The owner and inventory query is batched for the selected 25-fleet page to
avoid per-row reads and cross-tenant joins. The existing live actor/snapshot
authorization check and `Cache-Control: private, no-store` remain.
Successful private directory reads append a scope-only
`admin.private_list.fleets_viewed` audit event.

The Admin EN/AR web interface exposes the optional owner-ID filter and
text-only owner/inventory summaries. On role changes or logout, the existing
`EvFleetReport.setActor` removes private cards and stale request content.

## Verification

`AdminFleetDirectoryTest` tests privilege boundaries, inventory/identity
field allowlist, mixed-status membership counts, owner/country/name search,
literal SQL wildcard handling, pagination, stale authorization and read audit.
The existing `tests/fleet-report.cjs` gains EN/AR browser fixture coverage
for owner-ID filtering and text-only HTML-injection resistance.

Merge only after Agent 4 sees passing hosted exact-head CI and reviews
interactions with other agents. No production acceptance is claimed.
