# Admin account directory — vendor business-owner filters

The existing private Admin account directory can now find account owners by
**business** metadata, not only the person's account name/email. This is a
nonfinancial Admin workflow, not a public directory or an identity impersonation
feature.

`GET /api/admin/accounts` adds optional:
- `company_q`: bounded (100-character) business legal-name substring.
- `company_country`: uppercase ISO alpha-2 country code.
- `company_status`: draft/submitted/approved/rejected/changes_requested/suspended.

These filters combine with the existing role/account_status/verified/q filters
and are applied inside **one correlated EXISTS subquery** against
`vendor_companies.owner_id = users.id`, before the stable 25-item account
pagination. They must all match **one actual company**: if an account owns an
approved UAE company and a rejected Canadian company, a search for a
rejected UAE company must **not** return that account. The business-owned
summary on matching accounts remains the existing explicit allowlist; no
trade-license number, encrypted review note, uploaded KYC byte/path, password,
or bearer token is serialized. The account search remains active-Admin-only,
audited, and `no-store, private`.

The Admin browser adds EN/AR field labels to the existing filter form. Server
permission checks are authoritative; UI fields are not a security boundary.
No account mutation, role escalation, direct station control, payment or legal
KYC approval is added. All existing account moderation and independent company
review actions remain separate.

Verification required on the exact branch: `php artisan test
--filter=AdminAccountDirectoryTest`, full Laravel/PostgreSQL/browser gates,
and Agent 4 merge review. Fixture tests cover the one-company predicate,
bounded pagination, no business-secret serialization, and non-Admin refusal.
Production rollout and independent acceptance are not claimed.
