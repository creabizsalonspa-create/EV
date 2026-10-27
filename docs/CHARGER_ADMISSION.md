# Secure charger admission and no-source operations

Status: control-plane/API/web/Flutter and loopback TLS/mTLS-to-CitrineOS integration tested. This is **not production readiness or physical charger acceptance**. CitrineOS remains the protocol server; the new Node24 service is a TLS authentication/reverse proxy, not a replacement OCPP implementation. Existing loopback security-profile0 simulator endpoints remain separate and must not be exposed publicly.

## Scope and credential lifecycle

Cookie `/api/vendor-ops` and bearer `/api/v1/vendor-ops` expose:

- GET `companies/{company}/chargers/{charger}/admission`: current metadata and `can_manage`; never a password/digest.
- POST the same path `{identity,certificate_sha256?}`: prepare one immutable identity/protocol/certificate pin for owned inventory. An existing identical request is idempotent; another identity/pin conflicts. Select the inventory OCPP1.6/2.0.1 protocol first.
- POST `.../admission/credential {expected_version,days:1..90}`: issue/rotate a160-bit random40-hex-character ASCII Basic password, within common OCPP password field limits. The exact on-device encoding and firmware compatibility still require a real pilot. Only its SHA-256 digest is stored. Rotation invalidates the old password immediately; stale/uncertain retries conflict rather than disclose or silently rotate again.
- DELETE `.../admission/credential`: idempotent revocation clears the digest, disables admission and advances the version. The version advance fences an earlier in-flight rotation: whichever transaction wins, a racing revoke cannot be undone by a rotation using the prior version.

Production credential issuance/rotation requires an HTTPS request and fails before changing the digest/version on plaintext transport. Configure trusted TLS termination correctly; no client-controlled forwarded-header exception is introduced. Local simulator HTTP remains supported. Only current company owners or Admin can prepare/disclose/rotate/revoke. Inventory staff may read metadata but cannot mint credentials. Issuance requires current company approval and license; revocation remains available when those gates fail. Identity/protocol matching, unique charger/identity constraints, company/profile locks, append-only credential events and no-store responses apply. Changed source identity or certificate pin requires reviewed reprovisioning; automated certificate renewal/CA revocation policy is still unfinished.

Vendor/Admin web inventory and canonical Flutter company/charger inventory provide actual prepare/rotate/revoke controls, EN/AR text, explicit consent, metadata refresh, safe loading/errors and one-time secret display cleared after30 seconds, navigation or authentication expiry. Neither browser storage nor native session storage receives charger passwords. Lost replies require an authoritative GET then another explicit version-checked rotation. The server cannot retrieve a prior password. Authorized operators can still deliberately save/capture the disclosed password; the UI timeout is not a secure-memory-erasure claim.

## TLS edge and signed admission

`services/charger-ingress/server.cjs` uses Node HTTPS with TLS1.2 minimum. Configure trusted certificate/key files, optional client CA, a server-only ingress HMAC secret file, fixed authentication URL and fixed CSMS upstream URL. It binds127.0.0.1 by default; this task does not publish a real charger endpoint. Credentials are stripped from forwarded WebSocket headers. Only a path-safe identity and one explicit supported subprotocol are accepted; upstream accept/protocol headers are verified. TLS certificate verification is never disabled.

The edge signs timestamp, random nonce, original Basic authorization and exact request bytes. Laravel POST `/api/internal/charger-ingress/authenticate` checks the enabled flag, key strength,60-second signature window,125-second atomic replay fence, transport assertion, Basic username/password, configured protocol, expiry/revocation, optional actual TLS certificate SHA-256, current charger protocol and company/license. Browser/client-supplied forwarded certificate headers are not trusted. Production requires HTTPS to Laravel and shared durable database/Redis nonce storage; array/null/file caches fail closed. A600/minute proxy-IP and20/minute identity admission budget is separate from unchanged business/authentication limits; deployment capacity/load planning must account for these explicit bounds.

Open transports are reauthorized every30 seconds. Revocation, expiration or failed reauthorization closes transport within the recheck/timeout bound; it **does not issue remote stop or prove physical charging stopped**. Downstream CSMS transport must remain private, otherwise a direct connection could bypass the edge. There is no automatic retry of an OCPP command. This edge does not authorize a rider's RFID/IdToken, provision production CSMS REST/JWT identities, register canonical source bindings automatically, or confer remote-command capability.

For local simulation only, `./scripts/charger-ingress-setup` preserves application credentials and generates/synchronizes an ignored mode0600 HMAC file. It refuses non-local APP_ENV. It explicitly enables private-container HTTP for local development; the production HTTPS guard cannot be bypassed by that flag. Do not copy this local policy to a real deployment. `node tests/charger-ingress.cjs` creates a temporary trusted synthetic CA and ephemeral loopback listener, exercises both real CitrineOS protocol boot paths, tests rotated/revoked passwords and old-connection eviction, then exercises actual mutual TLS/pinned certificate admission and rejection without a client certificate. It removes only its own temporary TLS files/listener. No real charger or money is involved.

## Operational recovery without source evidence

Authenticated cookie and bearer routes under `charging-operations` are vendor station-owner/Admin scoped:

- GET collection: paginated uncertain commands and retained cases, sanitized command metadata, current source-proof state and private encrypted notes.
- GET `{command}/case?page=1`: current case and paginated decrypted audit history for authorized operators only.
- POST `{command}/case {note}`: one investigation case per admitted/unknown command. An exact operation replay returns the existing case without another command.
- PATCH `{command}/case {state,note}`: investigating/awaiting_source; resolved is permitted only if the command has an applied-source observation. This is case bookkeeping, not a forced session/capacity transition.
- POST `{command}/inspect-source {}`: after scope/lineage checks, read the existing authenticated simulator source and apply only its validated events. Absence leaves the case awaiting evidence. It never sends a start/stop, infers failure from elapsed time or forcibly releases capacity. Existing verified final-meter processing may call the existing financial finalization service; no financial rule or payment mutation is recreated here.

Web command history and vendor Flutter session navigation expose the case queue, private note/review forms, explicit source inspection and paginated history. Manual commands without a correlated session remain under investigation instead of guessing another source/session. Notes are encrypted at rest and changes append audit entries. No manual paid/ended/physically-stopped checkbox exists.

The web inspection path uses the same signed, validated and throttled inbox in process; it cannot make a nested callback into the single development worker. The CLI observer retains its signed HTTP delivery. GraphQL's URL is server configuration: the web container joins the private CSMS network and uses `charging-graphql:8080`; the host-network observer wrapper overrides to loopback8095. No CSMS database is shared with Laravel.

## Downgrade issue6 and remaining delivery

Canonical topology rollback now drops its unique vendor-charger index before dropping the indexed column on SQLite, and refuses configuration/history before any DDL (EVSEs, explicit source bindings, mappings or parallel legacy bindings). Isolated SQLite/PostgreSQL forward/down/forward checks retain legacy records and restore indexes; no real database rollback is performed. No financial migration was edited.

Still required: production CSMS authenticated registration and rider tokens, certificate renewal/revocation, distributed gateway/observer ownership, monitoring/incident automation, real no-evidence reconnect/terminal policies, authorized hardware/vendor firmware acceptance, full OCPI, maps, remaining customer/vendor/Admin/engagement/fleet/release scope. Existing production command/event guards are preserved. All project requirements remain in force; this milestone is not a final release.
