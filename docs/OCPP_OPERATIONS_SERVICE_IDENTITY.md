# OCPP operations service identity — 20 November 2026

This component supplies the service-identity boundary in requirement **5.2.6**. The combined registration vertical is documented in [OCPP_SOURCE_REGISTRATION_20261121.md](OCPP_SOURCE_REGISTRATION_20261121.md). It does not complete production upstream charger registration, enable physical commands, remove any existing simulator gate or establish hardware acceptance. The original requirement remains uncredited until its complete scope and integration evidence are verified.

The canonical command dispatcher, configuration/diagnostic probes, signed firmware and safe reset now obtain credentials through one `OperationsCredentials` service. Production environments cannot mint development `/dev/token` credentials. Explicit `oauth2` mode uses a single HTTPS client-credentials request with HTTP Basic client authentication, form encoding, fixed configured token endpoint, bounded timeouts and no redirects, discovery, retries, JWKS fetch or fallback. Tokens remain in process memory for the operation; there is no new database, cache, logging or financial service.

Required configuration:

- `CSMS_OPERATIONS_AUTH_MODE=oauth2`.
- `CSMS_OPERATIONS_OAUTH_TOKEN_URL`: exact HTTPS provider token URL, without embedded credentials/query/fragment.
- `CSMS_OPERATIONS_OAUTH_CLIENT_ID`: the registered service identity.
- `CSMS_OPERATIONS_OAUTH_CLIENT_SECRET`: private provider credential (32–4096 bytes, no whitespace/control characters). Keep in the deployment secret manager, never in Git.
- `CSMS_OPERATIONS_OAUTH_ISSUER`: exact trusted issuer claim.
- `CSMS_OPERATIONS_OAUTH_AUDIENCE`: exact HTTPS operations base URL, also matching the base used by the caller. Existing dispatcher and maintenance base settings must match this configured audience; no URL rewriting is attempted.
- `CSMS_OPERATIONS_OAUTH_SCOPE`: one required scope, default `csms:operations`.
- `CSMS_OPERATIONS_OAUTH_PUBLIC_KEYS`: JSON object mapping approved `kid` values to PEM RSA public keys, at least2048bits. Configuration supports overlapping old/new pinned keys during an operator-controlled rotation. Never accept keys supplied inside a JWT or fetched from a JWT URL.

The provider must return a `Bearer` access token with `RS256`, `typ=JWT`, a pinned `kid`, exact issuer/audience, service `sub` equal to the configured client ID, integer `tenantId=1`, an `admin` role in the roles list, and the exact required scope in its space-delimited scope claim. Issued/expiry/not-before times are strictly typed integers. Tokens must be issued within15minutes, expire within15minutes of issuance, and retain more than10seconds of lifetime. These explicit claims match this adapter contract; a provider with different claims needs a reviewed explicit mapping, not a permissive fallback. No clock-skew allowance silently extends expired authority.

Malformed, substituted, expired or weakly signed credentials,429, redirects, transport failures and provider errors produce a generic503 without provider bodies/tokens/secrets. Dispatch retains its conservative uncertain-outcome handling and never automatically replays a command. A valid credential is service authority only; it is not a device acknowledgment. Current actor/source/reservation/maintenance checks still apply independently. The source observer and immutable finance remain unchanged.

`development` remains compatible with the isolated local simulator and tests, but only in `local` or `testing`. This batch does not ship an identity provider, enroll a real charger, activate external services or authorize production deployment. Deployment must separately establish the trusted provider, issuer keys, CSMS verifier configuration, secure charger provisioning, mTLS/certificate lifecycle and physical protocol acceptance.
