# OCPP 1.6 / 2.0.1 simulator fault, identity and bounded-load matrix (5.4.4)

**Scope:** local CitrineOS/CSMS simulator only. These tests never exercise physical chargers, licensed partner connections, a production adapter, metering calibration, live traffic, hardware pilot acceptance or merchant billing. No production or physical acceptance credit is earned.

## Repeatable validation

On an isolated local/test environment, after the repository's CSMS and business-service setup:

```sh
./scripts/csms-setup
./scripts/csms-start
./scripts/charging-setup
npm run test:ocpp
```

The authoritative GitHub `business-platform` CI workflow invokes the same `test:ocpp` after CSMS and isolated charging-GraphQL setup. Do not run with production API credentials or a production WebSocket endpoint; all generated charger identifiers are prefixed `ev-test-` and the simulator points to loopback addresses.

### Required software evidence

| Protocol | Manufacturer/model | Firmware | Source status transitions | Concurrent authenticated source reads |
| --- | --- | --- | --- | ---: |
| OCPP 1.6 | CreaBiz simulator / Protocol test | sim-fw-v1 | Available → Faulted/GroundFailure → Unavailable → Available | 8 |
| OCPP 1.6 | Synthetic Labs / Protocol Edge | sim-fw-v2 | Available → Faulted/GroundFailure → Unavailable → Available | 8 |
| OCPP 2.0.1 | CreaBiz simulator / Protocol test | sim-fw-v1 | Available → Faulted → Unavailable → Available | 8 |
| OCPP 2.0.1 | Synthetic Labs / Protocol Edge | sim-fw-v2 | Available → Faulted → Unavailable → Available | 8 |

Each test starts a unique local WebSocket client, sends explicit BootNotification and Heartbeat, confirms typed source charger identity and firmware from authenticated Hasura, then confirms persisted connector event order from the same source station. The status sequence ends in Available; the test must never confuse another test's station model/firmware. Eight bounded parallel **read-only** Hasura queries must resolve the same station primary key, vendor, model and firmware.

The connected matrix also requires strictly ordered positive source-event IDs, absolute non-regressing status times, exact connector/EVSE targeting, explicit OCPP 1.6 clearance codes and protocol identity on every parallel read. `npm run test:ocpp` first runs 28 isolated contract tests, including foreign model/firmware, wrong protocol, duplicate IDs, malformed clocks, contradictory clearance and truncated history. These negative projections are synthetic, not additional real charger-model observations.

The existing OCPP command controls remain intact: unauthenticated remote-start must fail; only a local test administrator token may send the simulator remote-start command. The business command is recorded as submitted, not proven to have delivered energy. Source status/fault messages are software observations, not certificates of physical power, wiring, onsite safety, actual connector energization, or production readiness.

### Evidence retention and acceptance

Preserve the exact Git commit, `business-platform` check SHA and logs for each protocol/model. A failed source projection, missing status event, malformed firmware lineage, unknown protocol, mismatched station or lost status must fail the hosted test instead of being marked verified. See `docs/REQUIREMENT_COVERAGE.md` for the authoritative 312-atom ledger. **Do not promote 5.4.4** until all required simulator-model/fault/load criteria, downstream browser/native surfaces where applicable, exact-head CI and Agent 5 canonical integration are verified. Hardware-pilot acceptance (5.4.5) remains separately gated on real approved devices and physical evidence.

### Mixed-protocol concurrent reconnect scenario (5.3.8 / 5.4.4)

The existing `npm run test:ocpp` additionally opens four unique loopback WebSocket clients (two per protocol), with no additional business account registrations. All boot and report Available. One client of each protocol then reports Faulted and disconnects; authenticated Hasura must observe those exact durable station IDs offline while the other two continue heartbeat RPCs and report Unavailable. Both disconnected clients reconnect and boot, preserving their source IDs. All four report Available and their connector histories must contain the correct intermediate state followed by recovery, with no cross-station contamination. This phase sends no remote commands and cleans up all sockets even on failure. Its structured log records simulator-only evidence, not device energy delivery, service-wide capacity, or production acceptance. JavaScript parsing is checked before submission; the complete scenario requires hosted local-CSMS runtime validation.

### Concurrent active transaction and meter isolation (5.3.8 / 5.4.4)

The existing `tests/charging-session.cjs` now runs its two protocol sessions concurrently instead of serially. A bounded synchronization gate requires two distinct active source transaction database IDs on two distinct station IDs in a single authenticated Hasura read before either client disconnects. Each protocol uses a different absolute meter baseline (1,000 Wh and 3,000 Wh), preserving the existing 2,500 Wh energy delta per session. The periodic registers are 2,500 Wh and 4,500 Wh respectively. Both sessions retain the existing authorized start/stop, reconnect, OCPP 2.0.1 duplicate sequence, final energy and fault recovery checks. Finalization must refer to the original active source ID; each persisted meter history must contain its own periodic register and exclude the peer register. Failure of either client releases the bounded synchronization gate and both client outcomes are awaited before the test exits. No live charger or finance acceptance is inferred. Full hosted runtime evidence is required before this new concurrent test is credited.
