# Authenticated CSMS GraphQL transport policy (simulator-source boundary)

**Scope:** read-only OCPP source observation, simulator remote-session token publication, and synthetic configuration evidence. This does **not** authorize physical charging, live payment, upstream service enrollment, firmware application, or production rollout.

## Why this exists

The application sends the privileged `x-hasura-admin-secret` only to an explicitly configured GraphQL endpoint. A URL that is set to arbitrary plaintext HTTP, a cloud metadata IP, a source-lookalike hostname, or one containing URL credentials/query/fragment must not receive the secret. HTTP redirects are disabled by each source reader.

The shared `CsmsGraphqlTransport` transport gate applies before any source HTTP request in:

- `ContinuousFaultObserver::observe()` (before its source read or fault cursor writes).
- `RemoteSessionAuthorization::graph()` (read/mutation source token lifecycle).
- `ConfigurationProbe::graph()` (even if a future caller skips its stricter `enabled()` gate).
- `RiderSourceProvisioner::graph()` (writes and verifies source authorization, including RFID retirement).
- `SourceConsumer::graph()` (reads meter/source events that can ultimately influence charging and invoice reconciliation).
- `ChargerSourceRegistration::graph()` (reads/writes upstream charger metadata; remains independently gated by explicit administrator approval and `upstream_registration_enabled`).
- `ChargerDiagnosticsController::graph()` (authenticated connector/fault diagnostics; no charger command).
- `ChargerResetController::idle()` (checks both source station and connector/transaction state **before** a simulator-only reset can be considered; never trusts an unapproved Hasura host).
- `RiderSourceController::targetMatches()` (returns blocked if the source URL is unsafe).

The direct source registration and charger reset test doubles, and the independent PostgreSQL race worker, now use the reviewed local loopback service rather than the synthetic `source.test` plaintext hostname. The separate charger-fleet read view has its own strict source guard. The pilot probe also has independent `CsmsPreflight` configuration checks. Those stronger, purpose-specific restrictions must **not** be removed when these branches are integrated.

## Accepted transport

- **Local/testing only:** exact reviewed internal hostnames `localhost`, `127.0.0.1`, `[::1]`, `citrine`, `hasura`, `citrine-hasura`, `ev-hasura`, or `charging-graphql`, with `http` and the exact `/v1/graphql` path. This is strictly **development simulator** authority.
- **HTTPS:** operator-configured endpoints with no URL userinfo, query, fragment or control characters. This transport check does **not** certify a server as a legitimate CSMS: trusted deployment configuration, TLS/certificate/security review and independent upstream identity checks are still mandatory.

Unknown HTTP hostnames, suffix-lookalike hosts, URLs with embedded credentials, cloud metadata IPs and malformed inputs are blocked **before any Hasura admin-secret request**. The guard does not permit redirects or silently fall back to development endpoints.

## Test and integration requirements

```sh
cd services/business-api
php artisan test --filter=CsmsGraphqlTransportTest
```

The exact pull-request head must also pass the full GitHub business-platform, connector PostgreSQL and applicable client/recovery checks. Combine the rider-source transport guard with the separately developed exact-connector and OCPP-status verification in PR #282; neither security gate is optional. The full pilot protocol readout and physical site acceptance remain separate blockers.

**Evidence provenance:** software source code and mocked local/HTTPS requests only. No real Hasura deployment, physical charger command, energy meter, external notification delivery, or production acceptance has been demonstrated by this guard.

## Maintenance interlock (5.2.10 / 5.2.11)

Reset and signed-firmware workflows share `ChargerResetController::idle()` before dispatch and before reviewing an existing operation hold. Its authenticated status query now includes `errorCode`. A latest `Available` receipt is insufficient for OCPP 1.6 without explicit `NoError`; OCPP 2.0.1 accepts null or `NoError` only. Invalid fault-code types and contradictory same-instant status/error receipts fail closed. A fault blocks new operation admission and leaves an existing reset/firmware hold unreviewed. The signed-firmware test double uses an approved HTTPS GraphQL origin; no plaintext exception is introduced. These are simulator-only source tests and require full exact-head hosted verification.

## Durable upstream registration source generation (3.2.6 / 5.2.6)

Every source lookup, single metadata mutation and read-back independently validates the exact URL against the generation recorded in the prepared registration and checks that upstream registration remains enabled. Changing to a different approved HTTPS origin after the initial lookup or write cannot produce a verified receipt. Adapter revocation during lookup prevents the mutation; revocation during read-back prevents receipt finalization. Any such interruption preserves the one-attempt inspection hold and never grants charger admission. Both OCPP protocols have regression cases; hosted CI and canonical integration remain required.
