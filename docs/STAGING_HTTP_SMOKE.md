# Private staging HTTP smoke — Agent 10

This is an **operator-invoked, read-only, loopback-only** post-start check for an already approved staging deployment. It supplements the existing disposable Docker image smoke test and the separate offline configuration preflight. It does **not** deploy services, provision credentials, create accounts, operate chargers, issue reservations, or collect payments.

## Commands

From the repository root, test an already-running local Laravel instance exposed only through a private loopback forward:

```sh
node scripts/staging-health.cjs --url http://127.0.0.1:8080/
```

For the separately authorized staging Caddy gateway bound to `127.0.0.1:8443` with a **valid, trusted certificate** for the configured hostname:

```sh
node scripts/staging-health.cjs --url https://staging.your-authorized-domain.example.org:8443/ --tls-loopback
```

Replace the sample hostname with the **real approved staging DNS name**. The TLS-loopback mode connects to `127.0.0.1` while preserving the approved hostname for the HTTP Host header, TLS SNI and certificate verification. It does not resolve or connect to the public DNS address, disable TLS verification, or follow redirects. Do not add `NODE_TLS_REJECT_UNAUTHORIZED=0`.

## Read-only checks

- `GET /up` returns 200.
- `GET /` returns an HTML page with CSP and nosniff headers.
- `GET /api/stations` and `GET /api/v1/stations` return successful JSON pagination with `data` and nonnegative integer `total`.
- Anonymous `GET /api/me`, `GET /api/v1/me` and `GET /api/admin/overview` all deny access with 401/403, rather than exposing account/Admin content.

The script issues exactly these seven unauthenticated GET requests, never includes credentials/cookies, does not follow redirects, caps each response to 512 KiB, and times out each request after 6.5 seconds. Failure output contains only route names and coarse status information, not response bodies. Every connection is forced to loopback. Tests use a temporary Node HTTP server, not actual staging or production.

## Evidence and limits

```sh
node --check scripts/staging-health.cjs
node --check tests/staging-health.cjs
node --test tests/staging-health.cjs
```

A passing result verifies only the sampled public HTTP response and anonymous security boundaries at that moment. It does not establish migration/queue/worker health, charger connectivity, vendor/customer/Admin authenticated workflows, mobile builds, financial settlement, backup restoration, production authorization, or customer acceptance. Agent 4 must still review and merge only after required exact-head CI gates pass.

If the gateway is not running or the certificate is not ready, the check will fail as intended; do not make staging public or weaken TLS to pass it.
