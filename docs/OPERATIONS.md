# Development operations

Use the existing isolated checkout; do not create worktrees for cloud tasks unless the owner requests them.

`./scripts/setup` installs the lock, creates a local .env/key only when .env is absent, creates SQLite only when absent, and applies migrations. It never reseeds, truncates, overwrites credentials, or resets an existing database. If an existing .env lacks APP_KEY, run key:generate only after verifying it is a fresh environment with no encrypted data. Never rotate existing keys during routine setup.

`./scripts/start` creates/starts only the named `ev-business-api` development container and checks both health and the station query. Processes must restart after environment restore. `docker stop ev-business-api` stops it. To change mount/ports, inspect the existing container before removing and recreating it; do not remove unrelated containers. No production image or deployment has been prepared.

Before schema changes, stop writers and snapshot the development SQLite file plus securely held .env. Verify restoration into a disposable path. `migrate:rollback --step=1` drops simulated sessions and is destructive; use only disposable test databases. Restore the matched DB/schema and prior source release for rollback; no backup/restore drill has been performed yet. Production PostgreSQL backup, restore and zero-downtime migration procedures remain required.

Keep APP_DEBUG=false, secrets outside Git, HTTPS secure cookies in staging/production, correct trusted proxies and session domain, supported mail transport, least-privilege DB credentials and internal service isolation. Current local setup does not establish production security readiness.

Git diagnosis: initial checkout had unborn `work`, no objects and no remote branches. Created a dedicated branch and first commit; native authenticated push succeeded. No earlier push stderr was available, so only the empty-ref condition and current successful push are verified. GitHub API query failed with `Get "https://api.github.com/repos/creabizsalonspa-create/EV": Forbidden`. PR creation requires API egress/access; no token reconnection is justified by the successful Git transport alone.
