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

## Operations bearer endpoint binding (5.2.9 / 5.2.10 / 5.2.11)

Configuration reads, signed firmware and reset now capture the exact operations base used to obtain service credentials and reject endpoint or adapter changes before dispatch. The approved configuration-read alternate Compose origin is not interchangeable with the loopback origin once a credential is acquired. The transport uses only the captured base, not a fresh mutable config lookup. Regression cases for both protocols simulate configuration drift during token acquisition and assert no OCPP request, no token transfer to the alternate endpoint and no automatic replay. Firmware retains its provably-unsent state, configuration reads remain not_dispatched, and resets retain their conservative inspection hold. No production authority or physical hardware acceptance is inferred.

## RFID / IdToken publication and retirement (5.2.7)

Rider source publication and retirement now bind each lookup, insert, update and read-back to the GraphQL endpoint captured before the verified capability probe. The simulator adapter flags and endpoint are checked before and after every source request, including before final local state changes. A different approved HTTPS endpoint is not interchangeable mid-operation. Creation must return a positive typed source ID and subsequent read-back must identify that same record; updates and retirement must likewise retain the observed ID. A publication receipt must contain an absolute, strictly parsed expiry matching the still-current local token, not relative text. Drift or malformed evidence retains the inspection state and does not activate or certify retirement of a card. Feature tests cover successful publication/retirement, endpoint changes, adapter revocation, changed receipt IDs and malformed expiry; neither real device authorization nor hardware acceptance is inferred. Exact-head CI and Agent 5 integration are required.

## Continuous incident monitoring (5.3.7 / 5.3.8)

The fault observer pins the approved GraphQL endpoint and source key for each bounded poll, rechecks worker/bridge enablement before and after each source request and again before the observation transaction commits. Authority changes retain the last committed cursor and require review; a corrected poll can recover without skipping the original event or emitting duplicate recovery notices. Equal source timestamps may represent independent connectors or identical repeated receipts, but conflicting status/error evidence for the same EVSE and connector is rejected both within a page and across the durable cursor boundary. These checks do not establish hardware availability or authorize device commands or settlement. Eight dual-protocol regression cases are added; exact-head CI and canonical integration are required.

## Remote session authorization continuity (5.2.6 / 5.2.7)

Capture the GraphQL origin, admin key, rider capability origin and capability key together before requesting the nonce-bound capability proof. Remote publication and verification reject any change in that authority or adapter enablement before and after each source request; a successful insert must return a typed ID that agrees with the subsequent owned row, and publication readback must agree with the exact reservation expiry. No source authority change may be interpreted as successful publication or trigger a new request to an alternate origin. Ten regression cases exercise capability and verification races. Physical authorization and production acceptance remain unverified.

## Meter observation and signed settlement continuity (5.3.6 / 5.3.8)

Each bounded source-consumer poll pins its approved GraphQL endpoint, source key, inbox signing key, current binding and mapped connector/EVSE identity. Recheck authority before and after each GraphQL request, before source row identity pinning, before signed inbox submission and before cursor advancement. Sign the already-verified connector target rather than substituting a newly configured target. Drift stops further progress; already committed inbox receipts remain durable and a restored, independently verified source may resume through existing replay protection. Eight authority-drift scenarios and two expanded mapped-target regressions cover OCPP 1.6 and 2.0.1, no additional commands and one final invoice. CI verification and integration are pending; no hardware or production acceptance claimed.

## Operations service OAuth trust continuity (5.2.6)

The service credential acquisition path snapshots authentication mode, environment, provider endpoint, client identity/secret, issuer, audience, scope and pinned RSA keys before requesting credentials. It discards a provider reply when any trust input changes in flight and verifies JWTs against the original unchanged policy. Development plaintext credentials are limited to fixed loopback and Citrine Compose hosts; malformed host encodings and embedded URL credentials are rejected. OAuth failures never trigger a development fallback. Added twelve regression cases, including dual-protocol dispatch after signing-key revocation; these require exact-head CI and canonical integration. This does not activate or certify production authentication.

## Rider publication and retirement trust continuity (5.2.7)

A single operation now snapshots the approved GraphQL origin and administrator key, rider capability endpoint and client secret, and fixed local callback. The nonce-bound capability proof must be verified under that unchanged policy before acquiring the publication or retirement lease. Every authorization lookup, compare-and-set write, readback and final local receipt rechecks that same policy. Drift before lease acquisition leaves the token unchanged; drift after acquisition retains the existing inspection hold. No active or retired receipt may be inferred from mixed trust generations. Fourteen additional cases cover both operations across capability proof and source I/O; this remains simulator-only evidence pending exact-head CI and integration.

### Registration credential continuity (5.2.6)

The prepared durable source generation remains the existing URL/version/tenant hash, permitting legitimate credential rotation between independent operations without changing recorded source identity. A single registration attempt now captures the current administrator credential in memory and checks that credential and prepared source generation before and after every lookup, one metadata mutation, readback and final local receipt. A changed credential after claiming the attempt leaves the durable record requiring inspection, never an automatic repeat. No key is stored in registration records or output. Current company authorization is checked against the reloaded company, not a stale route model. Six additional dual-protocol cases exercise credential change after the lookup, mutation and readback. This establishes software interlocks only; independent production source authentication and conformance evidence remain required.

### Reset idle-evidence credential continuity (5.2.11)

The shared simulator idle guard snapshots the approved GraphQL endpoint and administrator key for both station and connector/transaction reads, verifies the same authority after each read, and rejects status rows for unknown connectors. Reset dispatch additionally requires that the idle-evidence source and operations origin remain unchanged through token issuance. Adapter enablement is rechecked before creating or reviewing a reset hold. An uncertain claimed reset is not retried, and a failed source review cannot release the existing hold. Eight additional cases cover key rotation during initial station/connector inspection, token issuance and review for both protocols. These are software safety checks, not physical reset acceptance.
