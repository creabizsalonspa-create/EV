# Private access-copy preparation: current Admin authority

This narrow Agent 7 change prevents an obsolete Admin session from generating
a new, sensitive, encrypted customer/vendor account copy after the same Admin
has been demoted, suspended, had its session epoch revoked, or had its account
password changed.

## Scope

The existing `POST /api/admin/privacy-requests/{case}/access-copy`
controller previously checked the Admin role, active status and auth epoch
on a locked row, but did **not** compare the saved request-session password
hash with the current user record. A stale request snapshot could therefore
prepare a private copy by supplying the latest password even though the
session originated before the password reset.

The private copy `prepare` transaction now uses
`App\\Security\\AdminTransactionAuthority::requireCurrent($r->user())`
before evaluating the supplied current password, locking the owner/case,
collecting allowlisted data and writing encrypted export and reviewer
records. This helper checks live Admin role/status plus the **matching
password hash and auth epoch** against the authenticated snapshot under
a row lock. A revoked or stale session cannot mutate the privacy case,
record a success audit or produce an encrypted copy.

No copy-download endpoint, account owner lifecycle, billing data,
invoice, tax, charger command or legal erasure behavior changed. Existing
separate-reviewer, case-kind/status, idempotency, 24-hour expiry, private
ciphertext and response restrictions remain intact.

## Verification / merge

`PrivacyAccessExportTest::test_stale_admin_cannot_prepare_private_access_copy_after_role_epoch_or_password_change`
provides direct regression coverage, no-side-effect checks, and proof that
a fresh Admin can still prepare an authorized copy.

**Stacked dependency:** `App\\Security\\AdminTransactionAuthority`
is introduced by #139. Agent 4 must merge #139 first, retarget or rebase
this branch onto the current integration head, rerun exact combined-source
CI, and handle the separate #149 privacy-review changes independently.
No production acceptance is claimed.
