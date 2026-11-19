# Admin MFA transaction authority

This Agent 7 patch is a narrow defense-in-depth improvement to the existing
Admin TOTP and recovery-code workflows. It changes **no** code length,
authenticator algorithm, second-factor policy, session duration, retry
allowance, backup-code entropy or permitted identity role.

## Problem and authority

A request can enter `AdminSecondFactorController` with an old Admin `User`
snapshot and complete a code challenge after the account has been demoted,
suspended, had its password rotated, or its `auth_epoch` revoked. Merely
locking the Admin row without checking current authority does not bind
factor enrollment, challenge consumption, or recovery-code replacement to the
current account.

The controller now calls
`App\\Security\\AdminTransactionAuthority::requireCurrent($snapshot)`
within the existing database write transactions for enrollment confirmation,
TOTP verification, backup-code consumption, backup-code rotation, and
recovery-only authenticator reset. The `begin` endpoint also validates the
current Admin row and password under an authorization transaction before
offering a short-lived enrollment secret; the existing confirmation step
performs a second independent authoritative check before persisting it.

The shared guard checks active Admin role, account status and password/
auth_epoch snapshot equality against the row locked with `FOR UPDATE`.
Any stale/demoted account is refused before factor/recovery code writes and
before success audit. Existing original TOTP/recovery and Admin session gate
remain authoritative, including purpose-limited recovered sessions and
one-time proof/version protections.

## Regression and integration

`AdminSecondFactorCurrentAuthorityTest` covers stale role, suspension,
auth_epoch and password changes across enrollment, verification, recovery,
backup-code rotation and recovery reset, confirming no successful mutation
or audit on denied attempts. Run it alongside the existing
`AdminSecondFactorTest` and full hosted browser, Laravel/PostgreSQL,
native and recovery checks. This is a **stacked PR based on Agent 7 #139**
because it imports the reviewed security guard from that PR.

Agent 4 must integrate #139 first, retarget/rebase this patch onto the new
integration state and rerun exact combined-source tests before considering
a merge. This does not claim production rollout or physical charging.
