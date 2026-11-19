# Admin MFA denial evidence (read-only)

This Agent 7 component makes existing Admin authenticator **failure audits**
reviewable without inventing incident or compromise verdicts. It is an
operational investigation aid, not an automated threat detector.

## Backend

`GET /api/admin/security-denials?window=1h|24h|7d&actor_id=<optional>`

Requires authenticated browser Admin under the existing platform API
throttle and RejectAdminPersonalAccessToken gate. It locks and revalidates
the current account role/status/password/auth epoch before any query.
Non-admin, demoted, suspended, expired or stale Admin contexts cannot read
the evidence or append a successful-view audit.

The endpoint includes **only six established Admin MFA denial actions**:
- enrollment password denied
- enrollment authenticator code denied
- verifier code denied
- recovery code denied
- recovery rotation denied
- reset-for-reenrollment password denied

These actions are counted from the existing `audit_events` table without
using or returning `subject`, private password/code content, IP,
email, phone, map data, raw source events or administrator names.
An explicit window and optional actor ID determine the query. At most 501
matching records are loaded, only the most recent 500 are counted, and only
25 actor-ID/count aggregates are shown. `sample_truncated` is true if
there are more than 500 matching rows and
`complete_for_selected_actions` is then **false**. This prevents a
partial sample being described as global incident statistics.

`suspicious_activity_verified`, `incident_confirmed`, and
`automated_action_taken` are always **false**. A denial can result from
typing mistakes, genuine account threats, rate limits or other causes;
this endpoint cannot identify which without independent evidence.

Successful review writes a fixed
`admin.private_list.security_denials_viewed` audit action, with a
bounded `window:all` or `window:actor:<id>` scope, never source payloads.

## Super Admin UI and verification

The existing EN/AR Admin audit explorer offers a **manual** "Review MFA
denial evidence" control with 1h/24h/7d selection. It fetches only after a
click, uses text-node rendering, shows a conspicuous partial-sample
warning, and clears pending requests on account change. No background poll
or automatic lockout results from this view.

`AdminSecurityDenialSummaryTest` covers privilege and fresh-credential
checks, private allowlisting, filters, period behavior, bounded 500-event
samples, explicit non-verdict semantics and successful-view audit.
`tests/admin-audit-trail.cjs` tests lazy loading, text-only rendering
and stale-reply prevention.

Acceptance requires exact-head hosted business-platform and PostgreSQL
CI, security/privacy browser tests, and Agent 4 safe integration. This
feature must not be called complete suspicious-activity detection or
production security acceptance.
