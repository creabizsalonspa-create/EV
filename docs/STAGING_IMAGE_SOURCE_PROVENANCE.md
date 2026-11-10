# Agent 10 — staging image-to-source identity proof

This read-only verification closes a release-preparation gap: Compose may show the correct **image tag** while its running containers actually use the wrong or stale image ID. New images built through `./scripts/staging-image-build` are labeled with the exact committed checkout source SHA, public repository origin and a Crea-Biz staging-review marker. Builds with uncommitted tracked or untracked source files are rejected.

## Build a labeled development image

From a clean, fully committed review checkout:

```sh
./scripts/staging-image-build creabiz-ev-business:review-UNIQUE-SOURCE
```

Existing Docker build, BuildKit CA secret and image audit still run. The build wrapper adds:
- `org.opencontainers.image.revision=<exact full git commit>`
- `org.opencontainers.image.source=https://github.com/creabizsalonspa-create/EV`
- `com.creabiz.ev.staging.review=true`

Do not manually apply review labels to unrelated images. These labels are **self-declared metadata**, not signed, independent proof that the built layer bytes came from the commit.

## Offline verification of privately captured Docker inspection evidence

Docker Compose `ps` data alone cannot prove image identity. Collect the private source image and three running application/worker/scheduler container inspections from a **previously authorized** deployment. These records can include internal configuration and should stay owner-only, never be committed or shared publicly:

```sh
mkdir -m 700 -p artifacts/staging-private-evidence
docker image inspect sha256:ACTUAL_64_HEX_IMAGE_ID > artifacts/staging-private-evidence/image.json
docker container inspect EXACT_APP_ID EXACT_WORKER_ID EXACT_SCHEDULER_ID > artifacts/staging-private-evidence/application-containers.json
chmod 600 artifacts/staging-private-evidence/image.json artifacts/staging-private-evidence/application-containers.json
node scripts/staging-image-provenance.cjs \
  --source-sha EXACT_COMMITTED_SOURCE_SHA \
  --image-snapshot artifacts/staging-private-evidence/image.json \
  --containers-snapshot artifacts/staging-private-evidence/application-containers.json
```

This checks the image's immutable `sha256:` ID, its full revision/source/review labels, and that each of the three distinct running Compose containers uses that exact image ID and the same intended tag in project `ev-staging`.

## Live read-only mode (local Docker context only)

```sh
node scripts/staging-image-provenance.cjs --live --source-sha EXACT_COMMITTED_SOURCE_SHA
```

The live verifier first calls the local-only staging Docker Compose runtime gate, rejects non-loopback port bindings and unhealthy application/database services, selects the application, worker and scheduler by their **observed immutable container IDs**, and performs **Docker container/image inspect only**. It rechecks the Compose snapshot at the end and rejects containers that changed during inspection. It will refuse remote Docker contexts.

It does not build, push, deploy, sign, restart, replace or delete images/containers, mutate finances, connect to chargers or change private data.

## Focused tests

```sh
node --check scripts/staging-image-provenance.cjs
node --check tests/staging-image-provenance.cjs
node --check tests/staging-image-build-identity.cjs
node --test tests/staging-image-provenance.cjs tests/staging-image-build-identity.cjs
```

Local exact-source Node 22.16.0: **15 tests passed, 0 failures**. The shell build wrapper is exercised with a fake Git and Docker toolchain. Runtime proof uses synthetic inspect records; **not** an actual staging server or released image.

### Known limits

The proof does **not** establish that a host is authorized, images are cryptographically signed, the deployed code is byte-identical to the Git tree, workers process queues, migrations have completed, private backup recovery works, external OCPP/OCPI networks are reachable, payments/physical chargers are accepted, mobile binaries are signed, or production deployment is approved. Keep PR draft until exact-head CI and Agent 4 review.
