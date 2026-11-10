# Agent 10 — reproducible, private staging-prerequisite evidence

This script **only inspects** the current checked-out Git source, a private Docker Compose snapshot, the private synthetic local demo fixture, and seven loopback HTTP routes. It writes a fresh, owner-only JSON evidence file. It cannot start or stop containers, deploy images, create accounts, access a production database, issue payments, operate a charger, merge PRs, sign applications or approve release.

## Operator prerequisites

1. Use the exact checked-out source branch/commit selected for review. Git must report **no modified tracked files**. A user-supplied source SHA that differs from the checkout is rejected.
2. Prepare a locally captured, private Compose v2 `ps --all --format json` snapshot using the reviewed `scripts/staging-runtime-gate.cjs` instructions. This is **not** a runtime/source-image attestation.
3. Prepare a private synthetic `artifacts/client-demo/<run>/access.json` from the existing **local** simulator fixture. Do not copy customer KYC, real account credentials or live financial data into this fixture. This verifies local synthetic demo metadata, not that those accounts exist on staging.
4. Provide an already-authorized, loopback-reachable staging HTTP endpoint. HTTP is only allowed on loopback; the optional `--tls-loopback` mode uses a trusted HTTPS certificate for a valid staging DNS name while connecting to loopback.
5. Create an owner-only new evidence directory. The output JSON **must not exist**; the tool will never overwrite prior evidence.

## Example commands

From the exact reviewed repository checkout:

```sh
mkdir -m 700 -p artifacts/staging-private-evidence
docker compose --project-name ev-staging -f infra/staging/compose.yaml ps --all --format json > artifacts/staging-private-evidence/runtime.json
chmod 600 artifacts/staging-private-evidence/runtime.json

node scripts/staging-acceptance.cjs \
  --runtime-snapshot artifacts/staging-private-evidence/runtime.json \
  --url http://127.0.0.1:8080/ \
  --demo-manifest artifacts/client-demo/EXACT_RUN/access.json \
  --source-sha "$(git rev-parse HEAD)" \
  --output artifacts/staging-private-evidence/review-UNIQUE-ID.json
```

For an approved staging reverse-proxy and valid trusted certificate, replace the URL with `https://staging.your-approved-domain.example.org:8443/` and add `--tls-loopback`. That mode still connects only to 127.0.0.1, never the public Internet. **Do not disable TLS certificate verification**. The optional `--allow-local-qa` is for an explicitly seeded local QA reservation and should not be used for an ordinary customer demonstration.

Before use, inspect the project Compose configuration, private port mapping and whether the local forward is actually reachable. No port-forward or deployment commands are created by this script.

## What the result means

The evidence file contains the exact requested/checked-out Git SHA, UTC start/end times, fixed seven-route names with pass/fail outcomes, and four prerequisite categories:

- `source_checkout`: the local HEAD SHA matches and tracked files are clean.
- `docker_snapshot`: exactly five required containers, runtime health, private gateway host bind and app-worker-scheduler image consistency passed the **supplied** snapshot (which could be stale).
- `synthetic_demo`: a private, unshared local fixture has the expected three synthetic account roles, two OCPP simulator identities and deliberately false real-money/physical-charging claims.
- `http_routes`: seven read-only HTTP endpoints passed public availability/catalog schema, security headers and anonymous identity/Admin denial at that observation time.

A missing/mismatched source, unhealthy/publicly bound container, invalid local demo, or failed route produces `INCOMPLETE` and a failing exit status. Errors never copy HTTP response bodies, account credentials, input paths or potentially sensitive Docker contents into the output. The output is created with exclusive owner-only mode 0600; its parent must be a real directory inaccessible to group/other users.

`PRIVATE_PREREQUISITES_PASSED` is **not** release acceptance: `production_accepted`, `release_authorized`, `live_charger_verified`, `payment_processor_verified` and `running_image_matches_source_verified` remain `false` in every report. It does **not** attest that the deployed image was built from the chosen Git SHA, that staging accounts exist, that queues/background jobs work, that migrations/backups recover, that Android/iOS binaries match, or that authenticated customer/vendor/Admin, PSP, physical OCPP, OCPI partner and app-store approval requirements are complete. Keep this file private; it should never be supplied as a public readiness certificate.

## Tests

```sh
node --check scripts/staging-acceptance.cjs
node --check tests/staging-acceptance.cjs
node --test tests/staging-acceptance.cjs tests/staging-health.cjs tests/staging-runtime-gate.cjs tests/demo-fixture-guard.cjs
```

Agent 10 isolated local Node 22.16.0 run of the four focused suites: **57 passed, 0 failed**, with a live local HTTP test server, exact staging response/role checks, duplicate route rejection, source-checkout dirtiness safeguards and non-overwriting private evidence output. GitHub blobs of the new checked-in script/test files were compared to the exact locally executed files. This does not replace hosted exact-head CI or actual staging acceptance. Agent 4 owns integration and merge decisions; Agent 9 owns full end-to-end QA.
