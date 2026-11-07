# Agent 10 — private staging runtime gate

This **read-only** runtime verifier supplements the configuration preflight (Agent 10 PR #62), the container-image smoke suite, and the HTTP response smoke in this PR. It never starts, changes or stops containers, updates databases, exposes ports, or connects to external services.

## Snapshot mode — offline, non-destructive

Take a private snapshot of an already-authorized local staging deployment, then inspect it:

```sh
docker compose --project-name ev-staging -f infra/staging/compose.yaml ps --all --format json > /private/staging-ps.json
node scripts/staging-runtime-gate.cjs --snapshot /private/staging-ps.json
```

Docker Compose v2 array JSON and newline-delimited JSON are both supported. The script refuses symlink snapshots, oversized input and malformed records. Keep snapshot files private and **never commit** them; container names and network mappings can disclose operations details.

## Live mode — local Docker socket only

```sh
node scripts/staging-runtime-gate.cjs --live
```

Live mode first verifies the default Docker context is a **local Unix socket**, refuses `DOCKER_HOST`, remote `DOCKER_CONTEXT`, or Docker TLS environment overrides, then runs **only**:

```sh
docker compose --project-name ev-staging -f infra/staging/compose.yaml ps --all --format json
```

The script does not call `up`, `down`, `restart`, `exec`, `rm`, or `docker inspect` on customer containers. If Docker is unavailable, this gate fails safely rather than creating or modifying an environment.

## Acceptance checks

The verifier requires exactly one container for each of these expected services: `application`, `worker`, `scheduler`, `database`, and `gateway`. It rejects missing, duplicate or extra services, wrong Compose project, stopped services, unhealthy application or database, and a running worker/scheduler/gateway reporting an unhealthy or starting state. Application, worker and scheduler must have the **same** `creabiz-ev-business:<tag>` image identity. Database and proxy images must be from the expected PostgreSQL 17 / Caddy 2 families.

Docker's internal **EXPOSE-only** port records with `PublishedPort: 0` do not count as public bindings. Only the gateway may publish a host port, and it must be a **single TCP** `127.0.0.1:8443 -> 443` mapping. A wildcard, IPv6 wildcard, unintended public binding, or published PostgreSQL/application/worker ports causes failure.

## Test evidence and limitations

```sh
node --check scripts/staging-runtime-gate.cjs
node --check tests/staging-runtime-gate.cjs
node --test tests/staging-runtime-gate.cjs
```

Agent 10's local Node 22.16.0 focused run: **19 passed / 0 failed**, including fake Docker command capture (proving no mutating Docker commands), malformed snapshot inputs, wrong image/port/project, missing/unhealthy services, and remote Docker environment rejection. Source blobs correspond exactly to GitHub committed copies.

**Limits:** This confirms only the sampled runtime process/health and port state. It does **not** prove that migrations, background jobs, notifications, customer/vendor/Admin journeys, remote commands, finances, physical chargers, OCPI partners, backup restore, delivery artifacts, legal approvals, public TLS, or mobile signing work. Any external deployment or physical charging still requires explicit customer authorization. Agent 4 owns final merge and source-complete integration.

## Dependency image pin enforcement

The runtime checker requires the running database and gateway Compose image references to exactly match the PostgreSQL 17 Alpine and Caddy 2 Alpine reviewed index digests from PR #62, rather than merely matching an image family. An existing staging installation running older mutable tags will fail acceptance until **separately authorized, backed-up, operator-controlled infrastructure maintenance** is performed. Agent 10's application-only rollout and rollback must not restart or recreate PostgreSQL or Caddy.
