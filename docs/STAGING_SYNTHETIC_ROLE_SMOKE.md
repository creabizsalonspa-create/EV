# Agent 10 — local customer/vendor/Admin synthetic role smoke

This is an **opt-in, local-only** demonstration verifier for the existing `scripts/client-demo prepare` fixture generated in a separate isolated Laravel test environment. It is designed to catch incorrect role boundaries, broken login/session recovery and stale browser authentication **without making charging, invoice, payment, booking, OCPP, OCPI or vendor-data mutations**.

## Run after creating a private local fixture

On the same authorized local machine where the isolated Laravel test app is already running on `http://127.0.0.1:8000` and the original test fixture is available:

```sh
node scripts/client-demo.cjs prepare
# The prepare operation creates isolated synthetic accounts and test stations;
# inspect its returned private manifest path. Do not share the account manifest.
node scripts/demo-fixture-guard.cjs \
  --manifest artifacts/client-demo/EXACT_RUN/access.json
node scripts/staging-synthetic-role-smoke.cjs \
  --manifest artifacts/client-demo/EXACT_RUN/access.json
```

The role smoke **does not run prepare**, create a customer, start a simulator, seed a reservation or access a production service. It only connects to TCP `127.0.0.1:8000`, has a fixed five-path allowlist, and refuses redirects. It checks three separate browser sessions—synthetic customer, vendor and Admin—with their `example.test` identities and private temporary passwords. For each session it requests a CSRF token, authenticates, verifies `/api/me`, confirms that the Admin overview is inaccessible to customer/vendor but accessible to the Admin fixture, logs out and checks that `/api/me` returns 401/403.

These are the only HTTP actions it uses:

- `GET /api/csrf`
- `POST /api/login` (the synthetic fixture account)
- `GET /api/me`
- `GET /api/admin/overview`
- `POST /api/logout`

The verifier maintains per-role isolated cookie jars, passes Laravel's CSRF token explicitly, caps responses to 64 KiB and timeout to six seconds, and **never prints passwords, emails, session cookies, CSRF tokens, account IDs, Admin audit records or raw response bodies**. It rejects a fixture flagged with the `--qa-current-slot` reservation bypass even when the optional generic guard allows it for internal tests.

**The commands are run only on a local approved synthetic app with the created test accounts.** Do not use real customer credentials, publicly reachable staging/production servers, real physical OCPP sources, production databases or payment providers. This test does not mean platform staging or customer acceptance has passed.

## Verification

```sh
node --check scripts/staging-synthetic-role-smoke.cjs
node --check tests/staging-synthetic-role-smoke.cjs
node --test tests/staging-synthetic-role-smoke.cjs
```

Agent 10's isolated Node 24.22.1 exact-source test result: **16/16 passed**, no failures/skips. Tests cover distinct synthetic roles, denied Admin access, logout session invalidation, manifest privacy, unauthorized origins and methods, no-redirect transport, explicit CSRF headers and real ephemeral loopback Node HTTP behavior. No real Laravel instance, account or charging session was changed in the test run. Agent 9 retains independent end-to-end QA; Agent 4 coordinates full integration and production acceptance.
