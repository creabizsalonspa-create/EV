# Agent 10 — effective Docker Compose staging isolation

Reviewing private `.env` alone is insufficient when Docker Compose merges `env_file`, shell interpolation and explicit `environment` settings. This read-only tool inspects the **fully resolved** private staging configuration and fails closed if payment, database, charger, runtime or port boundaries drift.

This stage requires **PR #62's canonical `staging-preflight.cjs`** to be merged first. Agent 10's release/rollback scripts use both preflight and this resolved configuration gate before any Docker Compose `up` operation.

## Live resolved-config check — no containers started

Run only on an authorized local staging checkout with the private `infra/staging/.env` (mode 0600):

```sh
node scripts/staging-preflight.cjs infra/staging/.env
node scripts/staging-effective-config.cjs --live
```

Live mode executes only a `docker compose --project-name ev-staging -f infra/staging/compose.yaml --env-file infra/staging/.env config --format json` command. It does **not** contact the Docker daemon, deploy/restart anything, connect to live OCPP devices, contact any external provider, use real payments or write to the database.

For offline, separately captured evidence:

```sh
umask 077
mkdir -p -m 700 artifacts/staging-private-evidence
docker compose --project-name ev-staging -f infra/staging/compose.yaml \
  --env-file infra/staging/.env config --format json \
  > artifacts/staging-private-evidence/resolved-compose.json
chmod 600 artifacts/staging-private-evidence/resolved-compose.json

node scripts/staging-effective-config.cjs \
  --snapshot artifacts/staging-private-evidence/resolved-compose.json
```

**Do not publish or commit resolved Compose JSON.** It can contain real `APP_KEY`, `DB_PASSWORD` and other private values. The checker outputs only generic pass/fail information and no secret values.

## What the gate verifies

- Exactly five Compose services with project name `ev-staging`; application, worker and scheduler share the same Crea-Biz image and identical resolved runtime environment.
- Staging-only Laravel: HTTPS origin, APP_ENV staging, debug false, PostgreSQL through service `database`, encrypted secure sessions, database queue, simulator disabled, and required private key/password presence.
- Real DB URLs, nonmock payment providers and direct SMTP must not be injected through the resolved environment.
- Applies PR #62's canonical isolated-staging policy to the **effective** application environment with staging hostname and certificate contact from the gateway. This rejects active live partner, billing, physical device or hosted key settings instead of relying only on the raw .env file.
- No application, worker, scheduler or PostgreSQL host port. The gateway alone publishes exactly `127.0.0.1:8443 -> 443/tcp`.
- No privileged containers, host networking, device passthrough or capability additions.
- Correct PostgreSQL 17 and Caddy 2 images, private application persistent volume, database volume and gateway certificate/config volumes.

## Test evidence

```sh
node --check scripts/staging-effective-config.cjs
node --check tests/staging-effective-config.cjs
node --test tests/staging-effective-config.cjs
```

Exact checked-in GitHub source verified on authorized isolated Node 24.22.1 host: **15/15 focused tests passed**, zero failures, including synthetic resolved Compose cases, secret-safe fail results, malicious ports, wrong DB_URL/PSP/SMTP, altered worker role, missing volumes and a fake Docker CLI proving config-only execution.

With the full Agent 10 PR #66 suite, **123/123 Node tests passed** after integration of this gate, with fake-Docker staging release/rollback checks and no real deployment. Hosted exact-head CI and real operator-authorized Docker Compose resolution remain pending. Agent 4 owns merge decisions; tests do not constitute production acceptance.
