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

The runner creates distinct source and destination Docker-only **internal** networks, separate PostgreSQL 17 containers and applications, and fresh random database credentials stored in mode 0600 temporary files. Both synthetic apps intentionally retain one generated **APP_KEY**, as a real recovered Laravel app must retain the original encryption key to read historical encrypted records. Neither environment publishes host ports or mounts any existing database. It applies all Laravel migrations as the web runtime user, inserts two identifiable synthetic probe records plus a third record encrypted by Laravel into a new review-only table, exports a PostgreSQL custom-format dump, restores it into the *new disposable database* and compares migration count, migration history hash and probe data hash. The recovered application must also successfully **decrypt the original synthetic ciphertext** under the preserved key, while the source and destination database passwords remain different. It also writes a mode-0600 synthetic private-storage file as the application worker, archives and restores the file into the fresh application private volume, and checks byte integrity, ownership/readability and mode. Finally it starts another copy of the application against that restored database and checks migration status and real HTTP station-catalog behavior.

The only SQL `--clean` operation affects a target PostgreSQL database created in the current script invocation. Cleanup uses captured container/network identifiers from that invocation, not wildcards or machine-wide cleanup. There are no charger commands, real provider payments or partner requests. Flags for these operations are explicitly off; the Docker networks have no external egress.

The hosted staging-image job runs the static safety test and the real dump/restore after its existing migration/HTTP/image checks. Successful runs write `artifacts/agent3-recovery/validation.json`, containing only non-secret source-dump and synthetic private-archive SHA-256, schema/data fingerprints, migration count, HTTP result and evidence classification. On failure no PASS artifact should be created. The GitHub Actions `staging-disposable-recovery-evidence` artifact retains this file.

## Acceptance and remaining gaps

- **Code prepared:** isolated restore runner, policy tests, hosted workflow wiring.
- **Pending hosted verification:** actual Docker staging image, custom-format dump and restore, Laravel boot, HTTP, artifact generation. No success claim until exact-head checks pass.
- **Still outstanding:** real encrypted production backup/retention/offsite storage, real object/private document storage and uploads (beyond one synthetic file), queue and transaction consistency, recovery-time and recovery-point objectives, full dataset restore (including canonical finance and charger sources), PITR/WAL, production migrations/rollback, existing-volume ownership, authorized infrastructure cutover, operator runbooks, and independent security approval.
- **Integration boundary:** this rehearsal does **not** copy/restore the separate CitrineOS PostgreSQL or Mongo/Express stores and cannot prove multi-service financial reconciliation. Do not use it as a production procedure.

For this project, no task may be marked PRODUCTION_READY or HARDWARE_TESTED based on this drill.

## Second milestone — separate CSMS PostGIS data recovery check

New files: `scripts/csms-recovery-smoke` and `tests/agent3_csms_recovery_policy_test.py`. A dedicated `csms-recovery` CI job executes them independently of the Laravel staging image.

```bash
bash -n scripts/csms-recovery-smoke
python3 -m unittest discover -s tests -p agent3_csms_recovery_policy_test.py
bash scripts/csms-recovery-smoke
```

The script uses the **exact PostGIS 16-3.5 image digest** from `services/csms/compose.yaml`, launches two isolated run-owned PostgreSQL/PostGIS containers on two internal Docker networks (without published ports), creates two clearly synthetic locations with a geometry(Point,4326) column and JSON data, snapshots the source with pg_dump custom format, inserts a third **post-snapshot** record into the source and restores into the distinct target with pg_restore. The final checks enforce the PostGIS extension, SRID 4326, record count, geometric/data fingerprint, and exclusion of the post-snapshot record. The source must still contain the third record. On success it writes only non-secret hashes and scope declarations to `artifacts/agent3-csms-recovery/validation.json`; CI uploads that JSON.

**Important:** This demonstrates the CSMS PostGIS **database format and isolated snapshot/restore mechanics**, not recovery of real CitrineOS schemas, immutable event journals, pending commands, charger identity/PKI, broker acknowledgments, in-flight RabbitMQ queues, temporal consistency across Laravel and CitrineOS, or Express/Mongo. The separate broker, device protocols and physical chargers are never started. Only a running, exact-source hosted test establishes the synthetic acceptance result; the presence of the script or draft PR alone does not. A production runbook must coordinate application-worker quiescence, PostgreSQL/WAL retention, backup encryption, verified restore/roll-forward, private object storage, and separately assessed RabbitMQ/Mongo consistency before actual cutover.

## Combined same-run recovery acceptance and staging preflight

**New tests and real work:** the dedicated `scripts/staging-release-preflight.py` rejects weak/unresolved credentials, group/world readable or symlinked staging settings, debug/public binding, mutable image tags and any enabled synthetic charging/payment/roaming capability. The staging compose database is now pinned to the exact reviewed PostgreSQL 17 image used in the restore drill; eight high-risk runtime flags are explicitly disabled. This is a *staging-only* configuration boundary and does not authorize production deployment.

Both independent database drills now emit a source Git commit SHA, workflow run ID and attempt and explicit tests of encryption/private storage. A `recovery-acceptance` CI job downloads both actual workflow artifacts, requires their hashes and verification fields, and **refuses stale or cross-run evidence**. The produced summary states `production_ready=false` and `cross_service_consistent_snapshot_proven=false` because neither synthetic PostgreSQL drill covers a correlated real CitrineOS/Laravel/Express/RabbitMQ restore or in-flight financial/charging commands. See `tests/agent3_staging_preflight_test.py` and `tests/agent3_recovery_evidence_gate_test.py` for negative cases.

A passing synthetic gate cannot substitute for data-security approval, authenticated test merchant, hardware safety approval, production PITR/retention, coordinated application and broker quiescence, or an operational release/rollback decision.

## Agent 3 archive integrity negative gate

New `scripts/backup-artifact-guard.py` examines pg_dump custom-format magic bytes and checks archive size and SHA-256 before restoring. For synthetic private files it scans gzip-tar member names, rejects absolute/traversal paths, links and special devices, and limits expanded size/member count. A local mode-0600 integrity manifest is generated then immediately checked. Both business and PostGIS drills call the guard before pg_restore. Negative unit tests verify tampering, invalid formats, unsafe tar entries and stale PASS removal. This is an unsigned local integrity test only, **not** a production authenticated backup, offsite backup or independent source of truth.

## Further acceptance work — damaged archives, snapshot boundary and worker boot

The disposable Laravel backup now introduces a post-snapshot source write, verifies it is **absent** from the restored database, and confirms it remains on the untouched source. Both PostgreSQL backups are copied and deliberately corrupted after snapshot creation; the integrity gate must reject each altered file before pg_restore. The business drill separately corrupts the synthetic private-file archive and requires rejection. All altered files remain exclusively under the run-owned scratch directory and are never restored. A fresh recovered Laravel instance boots the DB queue worker once with an empty queue and lists the scheduler without executing scheduled tasks. These tests cover software startup and synthetic backup boundaries, **not** recovery of real in-flight jobs or externally sourced charging/payment commands.

Evidence fields and negative acceptance tests now require tamper rejection, restored-file checks, source snapshot boundary and worker/scheduler startup. A PASS still means synthetic test-only evidence, not a deployed environment or coordinated live stack recovery.

### Complete private archive payload validation

The private gzip-tar guard now reads **every member's decompressed bytes**, validates the actual byte count against the declared tar header, and calculates a deterministic expanded-content SHA-256 before the restore command runs. Header-only inspection could miss compressed payload truncation; the new test suite verifies that a gzip stream truncated after valid-looking headers is rejected, while unmodified synthetic archives produce stable data digests. This is still a local synthetic integrity drill, not third-party signed/offsite backup acceptance.

### Corrected gzip footer validation (confirmed locally)

The first full-member-reading implementation was not sufficient to reject a gzip stream missing its last eight bytes, because Python's tar iterator can stop at tar end-of-archive markers without consuming the gzip CRC/ISIZE trailer. The guard now independently consumes the entire gzip stream under a bounded expanded-wire limit and requires complete checksum/trailer verification. Local synthetic tests have confirmed that normal archives verify while both truncated and deliberately corrupted gzip trailers are rejected. The tests were performed without production backups or hardware; hosted exact-commit Docker restore still requires CI.

### Key-continuity negative control and corrected acceptance fixture

The isolated restored Laravel application now proves that preserved source `APP_KEY` decrypts synthetic ciphertext and an independent valid 32-byte key cannot. The false-key check constructs a disposable Encrypter and does **not** rotate or expose the restored application's APP_KEY. The combined recovery-evidence gate requires `wrong_laravel_key_rejected=true`. Its CSMS positive-test fixture was corrected to contain the already-required `archive_tamper_rejected=true` field; duplicate business-field entries were also removed. Negative tests now cover both fields explicitly. These gates remain synthetic-only and are not physical/production acceptance.

### Strict private-archive extraction policy

The synthetic archive guard now rejects duplicate tar member names, ambiguous `.` or backslash path segments, path traversal, links and devices, setuid/setgid/sticky or group/world-writable permission bits, and archives containing only directories. At least one regular private file must be present. It still bounds total decompression, reads every member and validates the gzip trailer. New isolated negative tests cover repeated filenames, unsafe modes, ambiguous paths and directory-only backups. This hardened drill remains unsigned synthetic recovery evidence, not production backup acceptance.

### Owner-only private backup file permissions

The synthetic private-file archive checker now rejects **all group and other permission bits**, in addition to setuid/setgid/sticky permissions and links/devices. Private files archived as `0644` or `0640`, and directories archived as `0755`, are rejected even if they would not be writable to outsiders. The isolated staging drill's own payload is deliberately archived mode `0600`. New negative unit fixtures cover both file and directory permission leakage. This is a private-data safety prerequisite, not an independent signature or proof of actual production backup restoration.

### Archive path conflict negative test

The guard now rejects any regular file whose path is used as an ancestor of another archive member, in either tar-entry ordering, as well as existing exact duplicates and traversal paths. This prevents a backup that passes preflight but fails or has order-dependent effects at `tar -xzf` time. Added synthetic tests cover both parent-before-child and child-before-parent collisions, plus a normal single-file archive matching the isolated staging recovery smoke. The guard still does not claim signed/offsite backup verification or cross-service snapshot consistency.

### Unprivileged private-volume restoration

The disposable Laravel staging drill now extracts its verified private archive as `www-data` inside the **new target-only container**, not as Docker's default root user, with `umask 077`, `--no-same-owner` and `--no-same-permissions`. This reduces the risk that archive-controlled numeric ownership or mode bits are applied with elevated privileges. The script retains the subsequent application-user readability, mode and cryptographic private-file checks; a new static policy test fails if the extraction regresses to default-root execution. The actual container image/volume permissions and full restore path still require exact-head hosted Docker CI to verify.

### Stable archive descriptors and in-check mutation detection

Archive fingerprints now use `os.open(O_NOFOLLOW)` plus `fstat` on the actual open descriptor, rejecting symlink substitution between a preliminary path lookup and hashing. The private archive is fingerprinted both before and after full tar/gzip inspection; a replacement or modification during that check fails closed. Local synthetic candidate checks accepted the ordinary archive, rejected symlinks and detected deliberately appended bytes during inspection. This reduces validation-time races but **does not make the later independent restore/extraction atomic**, and it is not signed backup or production acceptance evidence.

### Measured synthetic restoration window

Both independent disposable restore drills record integer UTC epoch boundaries for drill start, earliest snapshot start (including the private-file archive for Laravel), snapshot completion, restore start, and successful restoration verification. The same-run evidence gate enforces chronological order, a bounded 24-hour drill span, and integer-only clock values; it publishes separately labeled `business_*_seconds` and `csms_*_seconds` observations. These are **synthetic recovery elapsed times and snapshot ages only**; they do **not** establish production RTO, RPO, operational restore windows, cross-service snapshots or disaster-recovery contractual SLA. `production_rpo_rto_proven` is permanently `false` in this evidence path. No production data, hardware or PSP operation is performed.

### Ephemeral run-local HMAC manifest verification

Each *synthetic-only* disposable restore now generates a fresh random 32-byte key in a 0600 file under its run-owned temporary directory. The backup manifest is authenticated using SHA-256 HMAC over canonical JSON and is reverified before/after atomic database restoration and private-file extraction. The checker rejects unsigned manifests when a key is required, wrong keys, altered manifest fields, unsafe/symlinked/weak-permission key files, and mismatching archive bytes. The key is never printed, exported or stored in a success artifact and is destroyed with the disposable scratch directory. **This is NOT independent/offsite authenticity:** an attacker controlling both backup and its ephemeral key could recreate the manifest; no production key-management, signed archive storage or retention guarantees are implied.

### Reviewed container volume destinations

The disposable recovery runtime inspector rejects missing, extra, read-only or bind-mounted Docker volumes and requires exactly one writable local Docker volume at the expected location for each run-owned container. The database role is limited to `/var/lib/postgresql/data`; the Laravel application role is limited to `/var/www/html/storage/app/private`. Distinct source and target volume IDs remain mandatory. Unexpected image-declared additional volumes fail closed. No existing production volume is examined, mounted or modified by the unit tests.
