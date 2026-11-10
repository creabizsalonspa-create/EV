# Agent 10 — private synthetic customer-demo manifest gate

The local Laravel fixture generator (`scripts/client-demo prepare`) creates **synthetic** customer, vendor and Admin accounts, two simulator-only OCPP stations, and random private credentials. Those credentials are written into `artifacts/client-demo/<run>/access.json` with restricted permissions.

Before a controlled internal demo, run the **read-only guard**:

```sh
node scripts/demo-fixture-guard.cjs --manifest artifacts/client-demo/EXACT_RUN/access.json
```

The guard checks that the file is a regular non-symlink file, the run directory and file are not accessible to group/other users, the filename/run convention matches, the schema matches the canonical local PHP fixture, and accounts are exactly `customer`, `vendor`, `admin` at `example.test`. Password structures must match the fixture's random generation format; **this check does not measure password entropy**.

It also verifies the two **simulator** identities for OCPP 1.6 and 2.0.1, unique station/charger/binding IDs, positive canonical connector mappings, an exact `http://127.0.0.1:8000` loopback origin, `fixture_only: true`, `real_money: false`, and `physical_charging_verified: false`. No password, email, hostname, or credential content is printed.

Normal demonstrations must create future bookings through the application. An explicitly seeded `--qa-current-slot` fixture is **rejected by default**. Internal simulator test operators may opt in with:

```sh
node scripts/demo-fixture-guard.cjs --manifest artifacts/client-demo/EXACT_RUN/access.json --allow-local-qa
```

**Do not share the access.json manifest or the run directory with the customer.** Provide test accounts through an approved private process, with passwords rotated or disabled after the session. The gate never creates accounts, starts/stops a charger, calls an API, changes payments, clears an uncertain transaction or makes an acceptance claim.

## Focused regression tests

```sh
node --check scripts/demo-fixture-guard.cjs
node --check tests/demo-fixture-guard.cjs
node --test tests/demo-fixture-guard.cjs
```

Local exact-source Node 22.16.0: **11/11 passed** (schema, secret-file permissions, symlinks, wrong origin, false hardware/money claims, OCPP identities, account credential structure, local QA override). Pair with the separate runtime and HTTP gates to check independent staging prerequisites. None of these gates proves authenticated customer/vendor/Admin journeys, live PSP/charger/network operation or production acceptance.
