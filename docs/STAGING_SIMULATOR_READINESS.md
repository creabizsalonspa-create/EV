# Agent 10 — private two-protocol OCPP simulator readiness

This is a **read-only** demonstration preflight for the two persistent *local* CitrineOS simulator processes that the existing `scripts/client-demo prepare/start` commands manage. It **never starts, stops or commands** a charger, swipes a card, creates a reservation, connects to real hardware, moves money or changes a user's account.

After an authorized operator has prepared synthetic local accounts and started both simulators in an isolated lab environment:

```sh
./scripts/client-demo prepare
./scripts/client-demo start
node scripts/staging-simulator-readiness.cjs \
  --manifest artifacts/client-demo/EXACT_RUN/access.json
```

It rejects anything not explicitly labeled `fixture_only` with the three synthetic `example.test` identities and simulator-only stations. Both OCPP **1.6** and **2.0.1** stations must have a non-symlinked, owner-only 0600 private PID/status file, consistent station/protocol/PID metadata, the evidence marker `SIMULATED_CITRINEOS`, `physical_charging_verified: false`, and a current `idle` observation **no older than 35 seconds**. A pending swipe, unresolved device journal, active/connecting/charging state, expired heartbeat or a PID not owned by the exact local `client-demo-charger.cjs` + manifest is a hard failure.

The verifier checks the local process via signal zero and a matching Linux `/proc/<pid>/cmdline` entry; it does **not** transmit a Unix signal or run device commands. If `/proc` cannot establish ownership on a machine, it fails closed. Do not treat a synthetic station as a Teltonika or other real charger.

No private station names, account credentials, session tokens or event details are printed. A successful result only proves the two currently observed synthetic idle processes, not that reservation processing, OCPP transport, remote charging, OCPI roaming, reconciliation, physical hardware or customer production acceptance works.

Focused source tests:

```sh
node --check scripts/staging-simulator-readiness.cjs
node --check tests/staging-simulator-readiness.cjs
node --test tests/staging-simulator-readiness.cjs
```

The 13 regression tests cover idle two-protocol readiness, false hardware claims, forged PIDs, missing/symlinked/public-readable status files, active/stale transaction state, queued swipes and failed process ownership. Hosted exact-head CI and actual authorized simulator readiness remain separate acceptance requirements.
