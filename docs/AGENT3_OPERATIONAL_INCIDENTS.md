# Agent 3 — local operational incident timeline

The Admin **Process and queue observations** screen shows a private, read-only English/Arabic incident timeline. The scheduled `operations:scan-incidents` command reads the existing operational heartbeat/queue metrics. It never polls hardware or payment services.

Conditions: stale observed queue worker, stale scheduler, default queue backlog (at least 25 ready jobs, oldest at least 300 seconds), or any recorded failed job in the default queue. Unknown, missing, disabled or unsupported telemetry never opens or resolves incidents. The scanner serializes updates with a DB row lock and writes a transition only on a real state change. A recovered condition creates a resolved event; a later failure reopens it. Neither queue payloads nor provider secrets enter the incident table.

Admin reads reuse the current-account/MFA/rate-limit boundary and private no-store response. The EN/AR browser uses text-only DOM rendering and session/visibility clearance; there are no retry, repair or acknowledge actions.

Disposable validation:
```bash
php artisan migrate --force
php artisan test --filter=OperationalIncidentsTest
php artisan operations:scan-incidents
```

A stopped Laravel scheduler cannot detect its own outage; production requires a separate watchdog and approved alert transport. The feature does not send SMS, email or push, restart processes, retry uncertain hardware/payment actions, validate RTO/RPO or prove production disaster recovery. Hosted PostgreSQL and all exact-source CI remain mandatory.
## Email handoff uncertainty review

The same read-only Admin health report also counts `outcome_unknown` email receipts and `claimed` receipts older than 15 minutes, regardless of whether the separately approved email transport is active. A fifth incident condition, `email_handoff_uncertain`, opens once if either count is positive; an unavailable receipt table is **unknown**, not proof of zero. Existing receipt state and consent, user addresses, email bodies and mail transport are never changed by the scanner. There are no automatic replays or outgoing emails. An operator must investigate the original receipt using approved procedures before any corrective state change; `handed_off` still never means received by a mailbox. Synthetic tests, concurrent PostgreSQL tests and exact-source CI govern this monitoring addition.

## Independent read-only watchdog entrypoint

`php artisan operations:watchdog-check` is a one-shot, **read-only** probe with a fixed, sanitized JSON schema and exit code **0** only if both configured heartbeat observations are recent, the supported database queue has known/acceptable counts, email handoff receipt monitoring is readable with no unresolved unknown/stale claims, and the incident store is readable with no open incidents. Any stale, unknown, disabled, unsupported, failed, backed-up, or unreadable signal returns a fixed nonzero exit code. It does not create a heartbeat, scan/mutate incidents, inspect payloads, retry jobs, dispatch charger/payment commands or contact an external delivery provider. `observed_normal` is **not** production readiness or evidence of all workers, job execution success or physical charging.

An approved external monitoring service may run this command as a **separately supervised process**, for example every minute with a bounded timeout, and alert on a nonzero exit code **or missing/late invocation**. **Do not** schedule this command using Laravel's own scheduler: a stopped scheduler cannot invoke it. Monitoring service, paging/SMTP/SMS routing, operator access and on-call response are **not provisioned by this change**; those require deployment approval and independently tested failover. The monitor must capture only the fixed status/signal codes, never application secrets or private receipts.

Disposable validation:
```bash
php artisan test --filter='OperationalHealthTest|OperationalIncidentsTest|OperationalWatchdogTest'
php artisan operations:watchdog-check
```
On a fresh database with no worker/scheduler observations, the watchdog intentionally exits with code 1, not success. After recording both real process observations, it can return observed normal for the scoped process signals; it must never be used as a self-proving heartbeat.

## Committed scanner liveness versus scheduler heartbeats

A separately supervised `operations:watchdog-check` probe must not conclude
`observed_normal` merely because Laravel's scheduler heartbeat is recent.
The scheduler can run its own heartbeat while the incident-scanning task is
skipped, blocked or broken. A completed `operations:scan-incidents` transaction
now records a **single timestamp** in its serialized scanner-lock record, without
payloads, incident duplication or external delivery. The timestamp is committed
only after the entire scan finishes; disabled or failed scans do not refresh it.

The read-only Admin EN/AR timeline shows the last completed scan state.
The external watchdog requires a committed completion newer than 150 seconds.
Missing, future-dated, unreadable or stale evidence fails closed with fixed codes
`incident_scanner_unknown` or `incident_scanner_stale`. Even a fresh scan
cannot prove all queue workers, incident condition completeness, real hardware,
external alert delivery, or production readiness. No user-account endpoint can
create this observation, and the watchdog cannot refresh it.

Disposable checks: `php artisan test --filter='OperationalWatchdogTest|OperationalIncidentsTest'`,
`node --test tests/admin-operational-health.cjs`, and disposable PostgreSQL
concurrency checks. This is a separately reviewable **stacked branch**;
merge PR #133 before integrating this follow-up.

## Overdue queued email receipts (review only)

Read-only email handoff monitoring also counts receipts still `queued` after 15
minutes. This is not a sent-email claim, an SMTP delivery attempt or a command
to replay. The existing `notification_email_outbox(state,created_at)` index
bounds the count without selecting recipients, addresses, bodies or private
outbox identifiers. An overdue queued receipt records an idempotent
`email_queue_backlog` incident transition and causes the independent watchdog
to emit `email_queue_overdue` with a nonzero exit status. Unknown or missing
receipt storage must fail closed, not become an invented count of zero.

The private read-only Admin EN/AR screen shows counts and a fixed incident label,
never any receipt ID, recipient, email body or provider token. Only separately
authorized, human-reviewed work may resolve pending records; there is
**no automatic retry**, SMS, push, SMTP or charger/payment side effect.

## Reserved database jobs awaiting human review

The default database queue can contain a *reserved* job even when another queue
worker is still recording healthy process heartbeats. Monitoring now returns only
the count of currently reserved jobs and the bounded `oldest_reserved_age_seconds`
from the configured queue. A reservation older than 1,800 seconds triggers the
fixed `queue_reserved_stale` incident and an independently supervised watchdog
nonzero status. A missing, future-dated or unreadable reserved timestamp is
**unknown**, never silently counted as a completed job. The Admin private EN/AR
dashboard renders the aggregate age and the incident lifecycle without queue
payloads, worker tokens, job exception messages or owner-private data.

These observations do **not** confirm a job failed, prove all workers are idle,
or authorize releasing the reservation, retrying an uncertain SMTP/charger/
payment task, or changing an invoice. A later fresh reservation timestamp
resolves the *local observation*, not the business outcome. Disposable SQLite
tests and PostgreSQL independent-process scanner races check one open/resolve
transition, immutable job records and zero charger/finance side effects.
