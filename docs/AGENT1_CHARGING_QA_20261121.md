# Charging registration milestone — 21 November 2026

Code checkpoint **b9b7f4622406904094b3eb10ba15e37c99566e6f**, branch `development/charging-protocol-continuation`, preserves verified canonical `f4f22e80` through normal merge4dd34371. Earlier auth checkpointea365cab is retained. No force-push, runtime journal reset, finance rewrite or main-branch merge was performed by Agent1.

Targets: original5.2.6, related3.2.6. Delivered source registration/JWT contracts, not broad production transport or physical acceptance. [Functional/API/recovery details](OCPP_SOURCE_REGISTRATION_20261121.md), [service identity](OCPP_OPERATIONS_SERVICE_IDENTITY.md), [machine evidence](evidence/charging-registration-20261121/local.json).

Verified local source:

- Laravel1,831 passes /32,462 assertions; one explicit PostgreSQL-only skip.
- Connector PostgreSQL994 /13,083; independent processes36 /558, including four new two-protocol registration prepare/create races; receipt precision migrations2 /13.
- Pinned CitrineOS JWT provider + API authentication/authorization plugin15 tests; controlled browser authority/loading/recovery/expiry/lifecycle suite9 tests.
- Actual Laravel + isolated pinned CitrineOS/Hasura1.6/2.0.1 source records; owner station/catalog/site/charger creation through normal APIs; actual Admin MFA, once-only metadata registration/read-back/replay; English/Arabic390px existing web admission and owner read-only status. Commands, inbox, charging sessions and canonical financial digests unchanged by registration.
- Original OCPP1.6 and2.0.1 boot/heartbeat/status/authenticated CSMS command + canonical outbox driver passed, each with two simulated inbound calls. The explicit positive-MFA test fixture used real enrollment/confirmation; no gate was disabled.
- Full PHP style848 files; CI trigger tests8; Node/shell syntax and formatting checks passed.

Agent4's dependent canonical Flutter consumer supplies eight focused widget/projection cases and one actual matched-Laravel native HTTP test for both source snapshots. Its exact final checkpoint, wider native regression and Android/iOS hosted build evidence belong to that agent and Agent5; this backend milestone does not claim a native release or merge.

Hosted CI at this new final candidate head is pending push/PR. Agent5 alone reviews, merges, verifies current exact-source checks and updates original312-atom stages. No whole-atom/weighted increase or production acceptance is awarded by this document. Production hardware/model/power, live IdP/CA/OCPI/PSP/bank, signing/stores and deployment approvals remain external and unverified.

Continue immediately with original5.2.9 production diagnostics: preserve default-off controls, canonical registered-source identity/actor guards, OAuth service authority, bounded private uploads and protocol-correlated outcomes. Do not remove the existing charging/payment production gates or claim simulator diagnostics as hardware acceptance. The earlier local failed/uncertain setup records are retained; exact attempts are recorded in the machine evidence.

## Current-head CI route audit follow-up

Hosted run44866783590 stopped at the offline route inventory:13 original bounded routes became16 after registration. The audit now explicitly checks every new company/charger/registration parameter while retaining all original malformed/overflow assertions and matchers. New authenticated HTTP cases reject overflow/exponent/negative/decimal/text identifiers before any source request. Verified64 offline guards,66 focused cases/532 assertions and PHP style. No failed/cancelled/skipped hosted check is credited; the new exact head must rerun all gates. Evidence: [route-audit-followup.json](evidence/charging-registration-20261121/route-audit-followup.json).
