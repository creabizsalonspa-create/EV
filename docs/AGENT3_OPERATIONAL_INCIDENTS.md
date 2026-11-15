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
