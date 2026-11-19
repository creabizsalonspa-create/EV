# Admin session revoke-all: authority from a current session

The existing Admin `POST /api/admin/sessions/revoke-all` workflow validates
the supplied current password, revokes all of the caller's browser/native
tokens and increments `auth_epoch`. Previously, its locked-role check did
**not** also compare the initiating session's saved password hash and epoch
against the live account.

A password reset or another session revocation must invalidate old
authenticated snapshots, including when an old session holder happens to
know the new password. Otherwise a stale session could unexpectedly delete
newer sessions and tokens. This patch calls
`App\\Security\\AdminTransactionAuthority::requireCurrent($actor)`
**inside** the existing database transaction, before password verification,
token/session deletion, epoch increment and success audit.

The guard requires the same active Admin role, password hash and auth_epoch
from a locked `users` row. No change was made to the existing current
password confirmation, Admin TOTP middleware gate, logout/session invalidation,
response shape, token/remember-token disposal, or cross-account isolation.

`AdminSessionRevocationTest` now verifies demoted/suspended or stale epoch
and rotated-password snapshots cannot delete existing SQL sessions, native
access tokens, or append a success audit. Existing tests verify legitimate
same-account revocation still succeeds and other accounts remain untouched.

**Stacked dependency:** #139 provides the shared current-authority helper.
Agent 4 must integrate #139 first, retarget/rebase this PR onto the current
integration branch, rerun combined-source exact-commit CI, and only then
consider merging. No deployment or production acceptance is claimed.
