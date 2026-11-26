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

The existing OCPP command controls remain intact: unauthenticated remote-start must fail; only a local test administrator token may send the simulator remote-start command. The business command is recorded as submitted, not proven to have delivered energy. Source status/fault messages are software observations, not certificates of physical power, wiring, onsite safety, actual connector energization, or production readiness.

### Evidence retention and acceptance

Preserve the exact Git commit, `business-platform` check SHA and logs for each protocol/model. A failed source projection, missing status event, malformed firmware lineage, unknown protocol, mismatched station or lost status must fail the hosted test instead of being marked verified. See `docs/REQUIREMENT_COVERAGE.md` for the authoritative 312-atom ledger. **Do not promote 5.4.4** until all required simulator-model/fault/load criteria, downstream browser/native surfaces where applicable, exact-head CI and Agent 5 canonical integration are verified. Hardware-pilot acceptance (5.4.5) remains separately gated on real approved devices and physical evidence.
