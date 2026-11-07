# Agent 10 — combined private customer-demo readiness

This command is for an authorized **isolated local lab**, not a live staging or physical charging environment. It combines the synthetic OCPP simulator and browser role smoke tests into one reproducible customer-demo prerequisite with a new private evidence report.

On a Linux host where the Laravel test server has already been authorized on `http://127.0.0.1:8000` and the two local CitrineOS simulators are under operator control:

```sh
./scripts/client-demo prepare
./scripts/client-demo start
umask 077
mkdir -p -m 700 artifacts/client-demo/private-evidence
node scripts/client-demo-readiness.cjs \
  --manifest artifacts/client-demo/EXACT_RUN/access.json \
  --output artifacts/client-demo/private-evidence/readiness-UNIQUE-ID.json
```

The tool does not create accounts, start or stop a device or send OCPP commands. It first requires both synthetic OCPP 1.6 and 2.0.1 sources to be idle, recent, nonphysical and owned by the exact local managed simulator process. It then exercises three separate **synthetic** customer, vendor and Admin browser sessions using the `example.test` fixture only, verifies authorization and denied Admin privileges for nonadmins, and logs each session out. Finally it requires both simulator processes to remain idle. No charging transaction, booking or payment is written by this operator tool.

The output contains only the fixed three role labels, both protocol labels, a UTC check window and boolean **false** for physical charging, real money, live OCPI and production acceptance. It never copies passwords, cookies, CSRF tokens, private user details, PIDs, device commands or raw HTTP responses. Files are created once with 0600 permissions in an owner-only directory; a second run cannot overwrite evidence. A partial failure writes only an `INCOMPLETE` verdict.

The command is not proof of full charging behavior, live Teltonika functionality, remote commercial partners, financial settlement, production readiness or customer acceptance. These require separate tests and approval by Agents 1–4, 6 and 9.

Tests:

```sh
node --check scripts/client-demo-readiness.cjs
node --check tests/client-demo-readiness.cjs
node --test tests/client-demo-readiness.cjs
```

Regression coverage includes both protocols, three role boundaries, failure before authentication when the simulator is unsafe, changed device state during browser authentication, private non-overwrite output, and redaction of failing component details. Hosted exact-head CI and an authorized live *synthetic lab* run remain pending.
