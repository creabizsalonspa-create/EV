# Source-locked private fleet administration (Agent 3)

This is an additive security and authorization slice toward 8.1.8
(fleet/enterprise reporting and administration). It does not implement a new
fleet ownership model, business ledger, external PSP, or regulated service.

Authenticated customer/enterprise fleet **list**, **single-fleet detail**,
**invitation list** and **fleet creation** now check the persisted active
account row under the same database transaction used for the private read or
creation. A cached session is rejected with 409 when the user's role,
password or authentication generation changed, or their account was suspended.

Single-fleet detail additionally reloads the current persisted fleet row
under lock, then reevaluates current ownership or active membership and scopes
vehicles accordingly. Old route-bound fleet objects and revoked memberships
cannot grant private company data. Shared membership lists remain
owner-only. Current pagination and private/no-store behavior are preserved.

`EnterpriseTest` adds role/status/password/auth-epoch stale session cases,
proves no fleet creation after revocation, and checks membership revocation
immediately removes detailed access without leaking other fleets.
Original 8.1.8 remains uncredited until exact-source CI, vendor/admin/native
review and Agent 5-controlled integration are complete.
