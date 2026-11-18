# Live Admin authority for KYC review queue reads

The KYC queue can expose owner names, emails, approval state and document
presence. The original Agent 7 PR #135 already restricts it to active Admin
requests and audits successful access, but relied on the request's cached
account object without revalidating that it remained an active Admin.

This narrow, stacked safeguard wraps the **entire** KYC queue read and
successful-view audit in one database transaction. It locks the current
reviewer `users` row, verifies the stored role and active status, and
requires identical password hash and `auth_epoch` compared to the
authenticated request snapshot before reading private data. Denied/stale
requests cannot obtain the owner's data or record a success audit.

All existing KYC semantics stay unchanged:
- Local country-calendar license expiry triage
- 25 company records per page and filter-before-pagination
- Approval authority remains at the existing VendorOps review endpoint
- Document contents and trade-license identifier are not exported
- No auto-approval, identity authenticity claim, or charger control
- Private no-store response and successful-view audit only

`AdminKycQueueTest` adds direct regression checks for account demotion,
suspension, auth epoch and password rotation, denied no-side-effect reads,
and subsequent authorized review.

**Stack dependency:** #135 must merge before this PR is retargeted to
`development/fullstack-continuation`. Agent 4 owns merges and must rerun
exact combined-source CI. PR #139 has a similar reusable authority helper
for other Admin endpoints; Agent 4 may reconcile implementations without
dropping this queue's transaction boundary. No production acceptance.
