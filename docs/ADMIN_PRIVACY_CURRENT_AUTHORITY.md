# Admin privacy review: live-authority enforcement

This Agent 7 patch extends the existing Admin transaction-authority guard
from dependency PR #139 to the canonical `AccountPrivacyController` private
Admin review surface. **It does not implement automated erasure, copy/export
or legal compliance acceptance**, and does not alter customer privacy intake.

## Sensitive endpoints

- `GET /api/admin/privacy-requests`: page-limited private requests and
  encrypted request details, authenticated/authorized under a live Admin
  lock and audited without plaintext.
- `GET /api/admin/privacy-requests/{case}/reviews`: page-limited encrypted
  internal reviewer history; live Admin lock and successful access audit.
- `PATCH /api/admin/privacy-requests/{case}/review`: transaction-locked
  current Admin authority before case lock and immutable encrypted review
  note/status update. Existing expected-status, allowed transition and
  independent-reviewer checks remain authoritative.

The caller's session snapshot alone is not proof of live Admin access.
Use `AdminTransactionAuthority::requireCurrent` **inside** the same
database transaction as the sensitive read or write. The guard rejects a
suspended/demoted Admin and rejects a stale password/auth_epoch snapshot.

Successful private response contracts and `Cache-Control: no-store, private`
are unchanged; internal decrypted notes remain withheld from owners.
History and review notes are never written in plaintext to audit records.
Any denied request must not append a success audit event, write a review
note, or advance the case. Existing Admin TOTP, self-review safeguards,
idempotency, external retention handling and manual review remain intact.

## Verification / dependency

`services/business-api/tests/Feature/AdminPrivacyAuthorityTest.php`
contains stale demotion, suspension, credential epoch, privacy/no-side-
effect and authorized behavior tests. All existing privacy self-service
and privacy export tests remain required.

**Stacked dependency:** This PR targets
`agent7/admin-privileged-mutation-safety-20261115` (PR #139) because it
uses `App\\Security\\AdminTransactionAuthority` introduced there.
Agent 4 should merge PR #139 first, reconcile the base of this stacked PR,
and require new exact-head CI against the integration branch before merge.

Production acceptance is not claimed.
