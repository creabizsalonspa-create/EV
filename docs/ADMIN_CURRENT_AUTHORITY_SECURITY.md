# Admin current-authority transaction boundary

This Agent 7 security patch prevents a request authenticated from an obsolete
Admin identity snapshot from reading private operational or identity histories,
revoking staff permissions, moderating customer/vendor accounts, or changing
shared regional currency/tax/timezone configuration.

## Authoritative rule

`App\\Security\\AdminTransactionAuthority::requireCurrent($snapshot)`
must be invoked **inside the transaction that performs the sensitive read or
mutation**. It locks the current `users` row and refuses the operation unless
both the snapshot and current record have the Admin role and active status.
A rotated `auth_epoch` or password invalidates stale authority. The caller
must not reacquire an old snapshot or write after the guard's transaction
ends. This is defense-in-depth; it does not replace second-factor middleware,
the session-revocation gate, or endpoint-specific authorization.

The guarded surface includes:
- Account directory, account moderation and private moderation history
- Company staff grant directory, emergency revoke/release and private history
- Private Admin overview (audited, at most 50 allowlisted audit entries)
- Audit explorer and privileged CLI role-change ledger
- Country configuration updates, preserving existing commerce-disabled behavior

All private successful reads preserve `Cache-Control: no-store, private`.
Private reasons stay encrypted in storage, are exposed only by the established
authorized review endpoints, and must never be copied into audit payloads.
No Admin permission, charger command, money movement, or compliance approval
is created by this change.

## Verification / integration

Use the exact PR commit and the existing business-platform, PostgreSQL,
financial/browser, recovery and staging checks. Relevant PHPUnit suites:
`AdminOverviewSecurityTest`, `AdminAccountDirectoryTest`,
`AdminAccountModerationTest`, `AdminStaffPermissionTest`,
`AdminAuditTrailTest`, `AdminOperatorRoleHistoryTest` and `EnterpriseTest`.
Country-specific real operations and production acceptance remain unverified
until independent Agent 10 checks and Agent 4 safe integration. The branch is
not a production release.
