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


### Current-owner report and CSV validation boundary

The existing read-only enterprise FleetReport JSON/CSV now confirms the
persisted active account and current owner/Admin authority under account
and fleet locks **before** inspecting any private report filters. A
non-owner gets non-enumerating 404 even when sending invalid dates,
format or cost-center IDs; a former Admin with stale role, password or
auth generation gets 409 rather than 422 or private report material.
The Admin fleet directory likewise checks its live Admin role before
processing owner-lookup filters. Direct plain-browser CSV requests with
invalid filters return an explicit private/no-store JSON HTTP 422,
not a redirect. CSV remains RFC quoted, formula-literal, bounded to
the existing fleet source service and `nosniff`; no claim, payment,
tax, payroll or journal source is mutated.

`AdminFleetDirectoryTest` now covers current owner/Admin reports,
foreign-owner malformed filter denial for both JSON and CSV, invalid
browser CSV 422, revoked Admin report/directory use and unchanged
finance journals. This remains a partial 8.1.8 readiness slice,
not a claim of independently verified fleet charging or corporate billing
release acceptance.
