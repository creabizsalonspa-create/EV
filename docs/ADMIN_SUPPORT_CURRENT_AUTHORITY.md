# Admin support: live credential authority and private read boundary

**Scope: PRD 4.1.2, 4.1.4, 4.5.7 (partial software controls).**

The Admin support desk can inspect confidential tickets, internal reviewer
messages, and other customers' support history. Prior to this patch, it
rechecked only the current role and could treat a stale pre-password-reset
Admin request as privileged even when the current row had changed its
`auth_epoch` or password hash.

The SupportController now checks both the authenticated snapshot and the
live `users` row before using Admin privileges. Live Admin status must be
active and role `admin`; auth epoch and password hash must match the
authenticated snapshot. Privileged ticket **list and detail** reads occur
within a transaction that locks the current user row until the bounded
query has completed. Existing Admin reply and state transition transactions
reuse the same current-credential check under their existing row lock,
before creating messages, notifications, status changes or audit entries.

A demoted administrator is treated only as the current non-Admin role,
where existing owner-only privacy rules still apply. Customer/vendor
support read and reply ownership, idempotent retry keys, 25-ticket and
50-message pagination, internal-notes visibility, notification creation,
status transitions and legacy/v1 API paths remain unchanged. The new read
responses are explicitly private, no-store.

Regression coverage in `SupportTest` verifies revoked auth epoch,
rotated password and suspension cannot read cross-account tickets or
mutate confidential messages, including the existing legacy/v1 routes.
Denials cannot create a success audit, owner notice or support mutation.
A fresh separately authenticated Admin still obtains permitted access.
Existing replay and customer ownership tests remain mandatory.

This is an additional Agent 7 feature in the consolidated #139 workstream,
not an independent micro-PR. Hosted exact-head PHP Pint, Laravel, Node,
PostgreSQL and browser tests must pass before Agent 4 integration.
No privacy legal/erasure or production security acceptance is claimed.
