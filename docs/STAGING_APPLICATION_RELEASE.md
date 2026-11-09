# Agent 10 — controlled private staging application release and recovery

Agent 10's deployment tools apply **only** an explicitly approved application/queue worker/scheduler image update in the private `ev-staging` Docker Compose project. No production deploy, public exposure, database schema migration, existing persistent-volume mutation, gateway reconfiguration, real charger command or payment operation is performed by this tooling.

**These commands have not been run against a live EV staging deployment.** They are implemented and covered by fake-Docker tests only. Operator-host preconditions, exact-head CI, authorized credentials, Agent 3 backup/recovery acceptance and Agent 4 review remain necessary before real staging apply.

## 1. Prepare and inspect

Use the exact reviewed and committed source, an authorized local Docker daemon (current context must be `default`, with a local Unix Docker socket) and a private `infra/staging/.env` mode 0600. It must contain only the intended staging secrets, domain and DB configuration. Verify the database backup/restoration procedure through Agent 3 before an approved staging change, even though the release tool performs no migrations.

Build a **new tag** through the source-labeling builder and its existing image audit:

```sh
./scripts/staging-image-build creabiz-ev-business:review-NEW-SOURCE
./scripts/staging-image-smoke creabiz-ev-business:review-NEW-SOURCE
```

**CI image-build canaries:** The `staging-image` workflow deliberately writes four `ev-image-guard.sqlite*` test fixtures and `.env.image-canary` before building. Root `.gitignore` lists only those five exact CI canary paths; note that the pre-existing `services/business-api/database/.gitignore` also ignores other `*.sqlite*` filenames. `.dockerignore` independently excludes the canaries from the image context. `staging-image-build` continues to reject modified tracked files and other *non-ignored* untracked files; never broaden these canary exceptions to application source or credentials. These synthetic checks do not authorize a deployment.

Then generate a **read-only plan**:

```sh
node scripts/staging-release.cjs plan \
  --candidate-tag review-NEW-SOURCE \
  --source-sha EXACT_40_CHARACTER_COMMITTED_GIT_SHA
```

This plan additionally compares each running application/worker/scheduler container's **actual immutable image ID, Docker Compose project and role labels** with the original reviewed image and captures stable container identities across read-only inspections. A retargeted old tag with unchanged running containers now blocks release before starting. The same exact-container/role checks and original protected-service ID checks repeat **after the journal is prepared but before the first Compose update**; a concurrent change leaves only a PREPARED record.

The application release plan additionally requires the operator's **clean checked-out Git HEAD** to equal the exact candidate source SHA, rather than trusting a self-declared OCI label alone. The source is rechecked after the private PREPARED journal is written and again immediately before RELEASE_STARTED. A dirty tracked or untracked non-ignored worktree fails before any Compose mutation (matching `staging-image-build`'s source-cleanliness requirement); a post-plan mismatch leaves PREPARED-only evidence. This is still not independently signed build provenance. The release additionally records an **in-memory only** SHA-256 and inode identity of the private `infra/staging/.env` through a bounded 0600 regular-file, non-symlink, single-link descriptor. It checks that exact identity after the isolated preflight and effective Compose review, before release mutation, before and after private HTTPS checking, and before declaring success. A changed configuration after PREPARED prevents Compose mutation; a changed configuration after a candidate starts **blocks automatic rollback with the now-unreviewed configuration** and requires operator reconciliation. These digests and secret values are never written to the journal or console. The checkpoints detect changes but **are not a transactional file lock**: operators must prevent other processes from editing the staging environment during the release. A second independent **source-only SHA-256, device and inode snapshot of the reviewed `infra/staging/compose.yaml`** now protects the application, database and gateway Compose definitions through the same preflight, PREPARED journal, pre-mutation, HTTPS acceptance and rollback checkpoints. The gate rejects modified, replaced, symlinked, hardlinked, oversized or group/world-writable Compose files. Both the private environment and Compose identity checks also reject symlinks in **any ancestor directory**, not only the final file or immediate parent, and verify directory device/inode provenance across each file read. This is additional checkpoint protection, not an atomic lock against privileged concurrent filesystem changes. It never copies, prints or rewrites Compose configuration, and the snapshots are held only in process memory. If Compose changes after a staged application starts, automatic rollback refuses to trust the altered definition and records operator reconciliation rather than certifying an unsafe recovery.

This plan captures the existing rollback image's **40-character source revision label**, repository source URL and reviewed-staging identity and requires those same labels before rollback, even when the image tag and immutable ID have not changed. Old, unlabeled images are not acceptable rollback targets and must be reviewed or rebuilt separately.

This plan verifies current Compose health/network exposure, the existing deployment's immutable image ID, distinct candidate immutable ID, the candidate image's source SHA and review labels, and identical current application/worker/scheduler tags. It will refuse non-default/remote Docker contexts, publicly bound database/app ports, a missing/unsafe private `.env`, image tag reuse, changed source label, or an unready project. No containers are changed.

### Gateway/Caddy prerequisite

The stage reverse proxy supplies a reviewed Content-Security-Policy and **`tls internal`** in `infra/staging/Caddyfile`, required for the private loopback-only deployment. An authorized operator must trust the Caddy **public root certificate** on the local test machine before running `scripts/staging-health.cjs`; the issuer's **private key must never be exported**. Do not disable TLS verification. The application-only rollout **does not reload or restart the gateway**. An authorized staging operator must independently check that the running, trusted-TLS Caddy instance is serving this reviewed configuration before applying a new app image. If the gateway is stale or TLS is unavailable, the rollout will fail safely rather than certifying the environment.

## 2. Explicit operator-authorized staging apply

Once Agent 4's exact-head CI and the application's schema-compatibility assessment are satisfactory, an operator may execute:

```sh
umask 077
mkdir -p -m 700 artifacts/staging-private-evidence
node scripts/staging-release.cjs apply \
  --candidate-tag review-NEW-SOURCE \
  --source-sha EXACT_40_CHARACTER_COMMITTED_GIT_SHA \
  --journal artifacts/staging-private-evidence/release-UNIQUE-ID.jsonl \
  --approval APPLY_PRIVATE_STAGING_FIRST_12_SHA_CHARACTERS
```

The approval marker is an explicit acknowledgement, **not** a secret or a replacement for organizational approval. Replace `FIRST_12_SHA_CHARACTERS` with the first 12 characters of the chosen exact SHA.

The release journal records `old_source_sha` as well as the former image's immutable ID; manual recovery refuses a missing or changed prior source label.

The tool acquires a single exclusive local staging lock, records the old/new tags and immutable IDs **plus the original database/gateway container IDs** in an owner-only append-only JSONL journal, and runs **only** a local Docker Compose `up -d --no-deps --no-build --wait application worker scheduler` with `EV_IMAGE_TAG` set to the new tag. It never uses `down`, `rm`, `migrate`, removes a volume, changes PostgreSQL/gateway settings or updates the private `.env`. It then rechecks staging health, loopback-only gateway binding and **actual running immutable image ID** for all three application roles. It also refuses to certify the rollout or rollback if the original PostgreSQL or gateway container identity changed during the application-only update. The script also **re-reads both old rollback and new candidate image tags at the final safe point before any Compose update and again after runtime/TLS health checks**, rejecting a retargeted tag or changed source-review label. If a tag changes before the first mutation, it leaves only a private `PREPARED` journal entry with zero service changes. If image identity changes after launch, it does not report success; rollback or explicit operator reconciliation is required. Manual recovery rechecks the immutable old image before and after restoring the application roles. After HTTPS smoke, the code **repeats the running-image, role/container-ID, protected PostgreSQL/gateway-ID and private port checks** before recording `RELEASE_VERIFIED`, `ROLLBACK_VERIFIED` or `MANUAL_ROLLBACK_VERIFIED`. A container or public-exposure change during the HTTP probe is not reported as healthy merely because the routes returned HTTP 200. Before recording success, it runs the seven-route read-only HTTPS smoke** against the reviewed staging hostname at loopback port 8443 with normal certificate verification. A failed route, missing CSP/header, anonymous Admin access leak or unavailable TLS triggers automatic return to the former app image; if the former image cannot pass the same HTTP checks, recovery is marked uncertain. The manual rollback tool applies the same TLS check before declaring recovery verified.

If the candidate is unhealthy or a command fails, the tool attempts to restore the exact previously recorded image ID/tag and logs `ROLLBACK_VERIFIED`. If prior image identity has changed or rollback verification fails, it records `OPERATOR_RECONCILIATION_REQUIRED` and exits nonzero. This means an operator must inspect retained state before doing anything else; never reset the journal or dismiss unknown sessions. A crash may leave only `RELEASE_STARTED`; such cases require manual recovery.

The project-wide lock is `/tmp/ev-staging-agent10-release.lock`. A crash deliberately leaves this lock rather than letting multiple releases race. Inspect the lock and current running process before an authorized manual release-lock cleanup; the tools never blindly remove stale locks.

## 3. Manual staging application rollback after interruption

For a truly started release with an intact original private JSONL journal:

```sh
node scripts/staging-manual-rollback.cjs rollback \
  --journal artifacts/staging-private-evidence/release-UNIQUE-ID.jsonl \
  --approval ROLLBACK_PRIVATE_STAGING_FIRST_12_SHA_CHARACTERS
```

The private release journal's event history is a strict state machine: `PREPARED` must transition to `RELEASE_STARTED`, a verified automatic rollback requires a preceding failure/rollback-start event, and a verified manual rollback requires a manual-start event. Impossible/reordered events refuse recovery even when event names and file permissions appear valid. Interrupted manual attempts can be explicitly retried after safety review.

The recovery tool validates the prepared plan, event history, file ownership permissions, old/current image IDs, current local Docker context, private gateway binding, PostgreSQL readiness and absence of an unexpected newer application deployment. It now also snapshots the private 0600 staging environment into an **in-memory only** SHA-256/inode identity before isolated preflight, then rechecks the same identity before journal/mutation, before/after the private HTTPS probe, and before any `MANUAL_ROLLBACK_VERIFIED` or read-only verification result. If `.env` changes after manual rollback begins, the tool must leave `OPERATOR_RECONCILIATION_REQUIRED` instead of falsely certifying recovery. No environment contents or digest is emitted in the journal. It refuses to overwrite a different new release. It only re-creates application, worker and scheduler using the **old reviewed local image**, and independently checks the runtime. A completed earlier rollback is confirmed read-only, without a repeated rollout. Failure records `OPERATOR_RECONCILIATION_REQUIRED`.

Rollback is **application-image-only**. If a human or another tool executed a schema migration, financial mutation or asynchronous task, the script does **not** reverse it or restore data. Those cases require the Agent 3 recovery process and an explicit owner decision.

## Verification evidence

```sh
bash -n scripts/staging-image-build
for p in scripts/*.cjs tests/*.cjs; do node --check "$p"; done
node --test tests/staging-health.cjs tests/staging-runtime-gate.cjs tests/demo-fixture-guard.cjs \
  tests/staging-acceptance.cjs tests/staging-image-provenance.cjs \
  tests/staging-image-build-identity.cjs tests/staging-release.cjs tests/staging-manual-rollback.cjs tests/staging-caddyfile-policy.cjs
```

Exact GitHub-source Node 24.22.1 execution on an isolated test directory with deterministic mocked Docker calls: **248/248 tests passed**, zero failures or skips; Bash and Node syntax checks passed. Source hashes matched the GitHub blobs. No actual local or remote Docker deployment was performed by these tests. Tests include failure/recovery paths, source label and image ID mismatch, public DB/gateway bind refusal, active remote Docker context rejection, journal tampering, stale tag rejection and concurrent release locking.

**Limits:** This does not establish production acceptance, customer use, authenticated role journeys, live OCPP/OCPI, signing, external financial settlement, charger safety, migration compatibility or deployment authorization. Agent 4 owns final merge/integration, Agent 9 independent QA and Agent 3 disaster recovery validation.
