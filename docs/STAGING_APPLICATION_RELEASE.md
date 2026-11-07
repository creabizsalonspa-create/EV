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

Then generate a **read-only plan**:

```sh
node scripts/staging-release.cjs plan \
  --candidate-tag review-NEW-SOURCE \
  --source-sha EXACT_40_CHARACTER_COMMITTED_GIT_SHA
```

This plan captures the existing rollback image's **40-character source revision label**, repository source URL and reviewed-staging identity and requires those same labels before rollback, even when the image tag and immutable ID have not changed. Old, unlabeled images are not acceptable rollback targets and must be reviewed or rebuilt separately.\n\nThis plan verifies current Compose health/network exposure, the existing deployment's immutable image ID, distinct candidate immutable ID, the candidate image's source SHA and review labels, and identical current application/worker/scheduler tags. It will refuse non-default/remote Docker contexts, publicly bound database/app ports, a missing/unsafe private `.env`, image tag reuse, changed source label, or an unready project. No containers are changed.

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

The release journal records \`old_source_sha\` as well as the former image's immutable ID; manual recovery refuses a missing or changed prior source label.\n\nThe tool acquires a single exclusive local staging lock, records the old/new tags and immutable IDs **plus the original database/gateway container IDs** in an owner-only append-only JSONL journal, and runs **only** a local Docker Compose `up -d --no-deps --no-build --wait application worker scheduler` with `EV_IMAGE_TAG` set to the new tag. It never uses `down`, `rm`, `migrate`, removes a volume, changes PostgreSQL/gateway settings or updates the private `.env`. It then rechecks staging health, loopback-only gateway binding and **actual running immutable image ID** for all three application roles. It also refuses to certify the rollout or rollback if the original PostgreSQL or gateway container identity changed during the application-only update. The script also **re-reads both old rollback and new candidate image tags at the final safe point before any Compose update and again after runtime/TLS health checks**, rejecting a retargeted tag or changed source-review label. If a tag changes before the first mutation, it leaves only a private `PREPARED` journal entry with zero service changes. If image identity changes after launch, it does not report success; rollback or explicit operator reconciliation is required. Manual recovery rechecks the immutable old image before and after restoring the application roles. **Before recording success, it runs the seven-route read-only HTTPS smoke** against the reviewed staging hostname at loopback port 8443 with normal certificate verification. A failed route, missing CSP/header, anonymous Admin access leak or unavailable TLS triggers automatic return to the former app image; if the former image cannot pass the same HTTP checks, recovery is marked uncertain. The manual rollback tool applies the same TLS check before declaring recovery verified.

If the candidate is unhealthy or a command fails, the tool attempts to restore the exact previously recorded image ID/tag and logs `ROLLBACK_VERIFIED`. If prior image identity has changed or rollback verification fails, it records `OPERATOR_RECONCILIATION_REQUIRED` and exits nonzero. This means an operator must inspect retained state before doing anything else; never reset the journal or dismiss unknown sessions. A crash may leave only `RELEASE_STARTED`; such cases require manual recovery.

The project-wide lock is `/tmp/ev-staging-agent10-release.lock`. A crash deliberately leaves this lock rather than letting multiple releases race. Inspect the lock and current running process before an authorized manual release-lock cleanup; the tools never blindly remove stale locks.

## 3. Manual staging application rollback after interruption

For a truly started release with an intact original private JSONL journal:

```sh
node scripts/staging-manual-rollback.cjs rollback \
  --journal artifacts/staging-private-evidence/release-UNIQUE-ID.jsonl \
  --approval ROLLBACK_PRIVATE_STAGING_FIRST_12_SHA_CHARACTERS
```

The recovery tool validates the prepared plan, event history, file ownership permissions, old/current image IDs, current local Docker context, private gateway binding, PostgreSQL readiness and absence of an unexpected newer application deployment. It refuses to overwrite a different new release. It only re-creates application, worker and scheduler using the **old reviewed local image**, and independently checks the runtime. A completed earlier rollback is confirmed read-only, without a repeated rollout. Failure records `OPERATOR_RECONCILIATION_REQUIRED`.

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
