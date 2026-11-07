# Admin two-factor authentication — engineering and acceptance

**Scope:** The Crea-Biz EV browser Admin role. This implementation does not grant
physical charger, live payment, or partner-network authorization.

## Enforcement

- Staging/production browser sessions with \`role=admin\` must enroll and
  complete an authenticator challenge before any protected web/API operation.
- Existing local simulator and PHPUnit fixtures remain unaffected by default.
  To exercise the production gate locally, explicitly set
  \`ADMIN_2FA_LOCAL_TEST=true\`. There is no environment flag that disables
  enforcement in staging/production.
- Admin authentication continues to use the established password login and
  server-side session. On login, old second-factor grants and pending secrets
  are discarded and the session ID rotates.
- \`GET /api/me\`, \`GET /api/csrf\`, login, logout and dedicated MFA routes are
  the minimal pre-verification exceptions. Protected JSON requests receive
  403 + \`admin_second_factor_required\`, page navigation redirects to
  \`/admin/second-factor\`. Guest and customer/vendor behavior is unchanged.
- A successful challenge rotates the session ID and binds a 12-hour approval
  to the current user and encrypted authenticator enrollment version. Removing
  or replacing the enrollment invalidates prior approvals.

## Enrollment, challenge and recovery

1. Admin password signs in; browser navigates to the private Admin challenge.
2. With no existing enrollment, Admin confirms the account password; one
   160-bit Base32 TOTP secret is displayed once for manual setup, valid for a
   ten-minute pending enrollment session. No third-party QR provider is used.
3. A correct six-digit RFC 6238 (SHA1, 30-second) code confirms enrollment
   and stores only an application-key-encrypted secret. Eight single-use,
   80-bit hexadecimal recovery codes are shown exactly once; only SHA-256
   digests are stored in the database.
4. Later password sign-ins require a fresh authenticator code, verified with
   a ±one-step drift window. All accepted TOTP steps are locked and persisted,
   so a consumed step cannot authorize another request or session.
5. A saved recovery code can be consumed once to open a recovery-scoped
   session. To replace a lost authenticator, this session must confirm the
   current password. The old enrollment and all remaining recovery codes are
   deleted transactionally, then the account must enroll a new authenticator.
6. Enrollment, verification, recovery and reset emit bounded audit event types
   without codes/secrets. Code attempts are limited by the existing
   \`throttle:authentication\`; page/status reads use \`throttle:platform-api\`.

## Security boundaries

- No plaintext authenticator secret or recovery code is written to audit,
  logging, User JSON or persistent browser storage. The server-side temporary
  setup value is encrypted and the response has \`Cache-Control: no-store\`.
- An attacker with a password alone cannot complete an enrolled Admin login,
  recover a device, or reset enrollment. A lost authenticator **and** all lost
  recovery codes needs a separately authorized, identity-verified operator
  recovery process; no bypass endpoint is provided here.
- Password or role changes must invalidate browser/mobile credentials. Merge
  and verify **Agent 7 PR #76** (role-transition token revocation) before
  shipping this change. This PR does not claim native Admin login support or
  negate the independent Admin bearer-token restrictions.
- Run migrations and confirm the application encryption key is durably backed
  up before staging deployment. Losing the encryption key makes previously
  enrolled TOTP secrets unreadable.
- This is a software-candidate feature, not proof of compliance, independent
  penetration-testing acceptance or production security certification.

## Reproduction and integration

- \`php artisan test --filter=AdminSecondFactorTest\`
- Existing \`MobileAuthTest\`, \`AccountTest\`, \`VendorOpsTest\`, and the full
  required platform/web/browser/financial/native CI matrix.
- When testing against actual staging, enable an approved Admin test account,
  confirm TOTP enrollment and one-time recovery replacement with a private
  account, verify same-step replay fails, verify non-Admin unaffected, and
  inspect HTTP caching/referrer/CSRF controls. Never upload real codes or
  credentials to GitHub Actions artifacts.
- Agent 4 owns conflict resolution and merging with active changes to
  \`routes/web.php\` and \`public/platform.js\`; never overwrite other agents'
  customer, notification or vendor workflows.
