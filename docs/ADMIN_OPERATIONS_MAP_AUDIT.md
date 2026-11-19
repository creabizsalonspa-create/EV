# Admin operations map: private review audit

The existing Super Admin operations map exposes cross-company station
inventory for authorized operations, with truthful unknown availability and
no remote charger or payment authority. A successful read previously left no
audit record identifying the reviewing Admin.

This Agent 7 patch adds a scope-only
`admin.private_list.operations_map_viewed` audit event **inside the same
transaction** after the existing locked-role/session credential check,
validated filters and bounded map query. Event fields:
- `actor_id`: current authorized Admin ID
- `action`: fixed private map review action
- `subject`: `company:<number>`, `country:<ISO2>` or `all`
- `created_at`: server time

Do **not** persist map coordinates, search text, private vendor names, URL
query strings, credentials, raw source events or geolocation in this audit.
Denied or invalid requests must not create a successful-view record.
Empty-but-authorized result sets are genuine, audited review actions.

The existing private `no-store` response, 25-row pagination,
country/company/viewport filters, anti-stale Admin authentication,
unknown physical availability labels and no-command guarantees remain
unchanged. This is not a monitoring heartbeat; the Admin UI invokes the map
as an intentional search rather than a continuous operational poll.

Regression: `AdminOperationsMapTest` verifies successful scope and actor,
privileged access control, no audit on denial/invalid filters, and absence
of private station names in the audit. Merge only after exact-head hosted
Laravel, PostgreSQL, browser and recovery CI. Agent 4 owns integration.
