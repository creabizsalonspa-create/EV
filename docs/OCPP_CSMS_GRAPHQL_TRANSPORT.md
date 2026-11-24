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
- `RiderSourceController::targetMatches()` (returns blocked if the source URL is unsafe).

The direct source registration test doubles and the independent PostgreSQL race worker now use the reviewed local loopback service rather than the synthetic `source.test` plaintext hostname. The separate charger-fleet read view has its own strict source guard. The pilot probe also has independent `CsmsPreflight` configuration checks. Those stronger, purpose-specific restrictions must **not** be removed when these branches are integrated.

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
