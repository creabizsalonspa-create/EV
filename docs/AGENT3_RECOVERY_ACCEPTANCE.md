# Agent 3 — Disposable PostgreSQL recovery acceptance

## Ownership and purpose

Agent 3 owns **independent staging recovery, security and release verification** while Agent 1 continues canonical Laravel/CitrineOS/finance/Flutter work and Agent 2 continues the separate Express/Mongo OCPP bridge. Agent 3 does not replace the canonical billing/charging ledger and does not edit the active Agent 1 or Agent 2 branches.

This first milestone adds an actual PostgreSQL backup/restore rehearsal for the **same pinned built Laravel staging image** already checked by `scripts/staging-image-smoke`. It closes a missing *software verification mechanism*, not the production restore acceptance gate.

## Command

```bash
./scripts/staging-image-build creabiz-ev-business:review
bash -n scripts/staging-recovery-smoke
python3 -m unittest discover -s tests -p agent3_recovery_policy_test.py
bash scripts/staging-recovery-smoke creabiz-ev-business:review
```

The runner creates distinct source and destination Docker-only **internal** networks, separate PostgreSQL 17 containers and applications, and fresh random credentials stored in mode 0600 temporary files. Neither environment publishes host ports or mounts any existing database. It applies all Laravel migrations as the web runtime user, inserts two identifiable synthetic probe records into a new review-only table, exports a PostgreSQL custom-format dump, restores it into the *new disposable database* and compares migration count, migration history hash and probe data hash. It also writes a mode-0600 synthetic private-storage file as the application worker, archives and restores the file into the fresh application private volume, and checks byte integrity, ownership/readability and mode. Finally it starts another copy of the application against that restored database and checks migration status and real HTTP station-catalog behavior.

The only SQL `--clean` operation affects a target PostgreSQL database created in the current script invocation. Cleanup uses captured container/network identifiers from that invocation, not wildcards or machine-wide cleanup. There are no charger commands, real provider payments or partner requests. Flags for these operations are explicitly off; the Docker networks have no external egress.

The hosted staging-image job runs the static safety test and the real dump/restore after its existing migration/HTTP/image checks. Successful runs write `artifacts/agent3-recovery/validation.json`, containing only non-secret source-dump and synthetic private-archive SHA-256, schema/data fingerprints, migration count, HTTP result and evidence classification. On failure no PASS artifact should be created. The GitHub Actions `staging-disposable-recovery-evidence` artifact retains this file.

## Acceptance and remaining gaps

- **Code prepared:** isolated restore runner, policy tests, hosted workflow wiring.
- **Pending hosted verification:** actual Docker staging image, custom-format dump and restore, Laravel boot, HTTP, artifact generation. No success claim until exact-head checks pass.
- **Still outstanding:** real encrypted production backup/retention/offsite storage, real object/private document storage and uploads (beyond one synthetic file), queue and transaction consistency, recovery-time and recovery-point objectives, full dataset restore (including canonical finance and charger sources), PITR/WAL, production migrations/rollback, existing-volume ownership, authorized infrastructure cutover, operator runbooks, and independent security approval.
- **Integration boundary:** this rehearsal does **not** copy/restore the separate CitrineOS PostgreSQL or Mongo/Express stores and cannot prove multi-service financial reconciliation. Do not use it as a production procedure.

For this project, no task may be marked PRODUCTION_READY or HARDWARE_TESTED based on this drill.