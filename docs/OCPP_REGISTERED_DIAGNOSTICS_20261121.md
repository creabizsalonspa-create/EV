# Registered-source diagnostics — 21 November 2026

Agent 1 owns this charging slice; Agent 5 owns integration and the canonical requirement tracker. This candidate extends requirement 5.2.9 (charger diagnostics) without claiming completion of all protocol operations or physical-device acceptance. It depends on the audited source-registration/authentication slice in PR #198, frozen at `faac6e671649be503d9421da81a779facf36ce62`.

## Implemented contract

The existing vendor diagnostic-log endpoints now support a canonically registered, owned `direct_unverified` charger for OCPP 1.6 and 2.0.1. No second diagnostics system, charging gateway, or financial logic is introduced. Existing simulator behavior remains independently gated.

Production-facing dispatch is off by default. An operator must deliberately configure `CSMS_PRODUCTION_DIAGNOSTICS_ENABLED=true`, the existing diagnostics feature flag, OAuth2 operations credentials, fixed HTTPS operations/source endpoints and `CSMS_PRODUCTION_DIAGNOSTIC_UPLOAD_ORIGIN` as a bare HTTPS origin. The default enables no production command. Existing admission/certificate checks, approved licensed vendor ownership, authenticated canonical source receipt, source-generation identity and current actor/MFA authority are required. Enabling this feature does not grant charging availability, reservation capacity, reset, firmware or generic command authority.

A request obtains one durable dispatch claim and makes at most one GetDiagnostics/GetLog transport attempt. Source preflight and token acquisition precede the final locked authority check. The network transaction is never automatically retried. Lost acknowledgements retain an unknown receipt; no automatic replay is added. OCPP 2.0.1 log request IDs are exact integers. OCPP 1.6 upload-status notifications have no request ID and remain explicitly unattributed.

Uploads use a 15-minute, hashed, revocable capability, 512 KiB maximum and encrypted file/payload storage. The optional filename suffix supports conforming 1.6 uploads while rejecting paths and preserving the existing 30/minute throttle. Production requires HTTPS. Replays must contain the identical file bytes. Upload consent is pinned to actor role/auth epoch/password and Admin MFA enrollment, admission credential version/digest and canonical registration. Profile or actor changes revoke incoming authority. Private history/download/removal remain available to currently authorized users when business readiness or the configured source endpoint changes; these changes block new outbound work. Files expire after 24 hours. Source observation validates bounded typed request/reply rows and chronology before granting download evidence.

Canonical `ChargerCommand` storage also seals URI userinfo under protocol URL fields, nested arrays and confirmations. This includes 1.6 `location` and 2.0.1 `log.remoteLocation`. Public projections redact legacy plaintext and encrypted URI credentials without decrypting them. Operational access retains the exact transport URI. Envelopes are authenticated against command identity and field path; transplant/tamper fails closed.

## Client boundary

The existing web inventory exposes private diagnostic files for registered direct chargers, with English/Arabic privacy notices and separate `AUTHENTICATED_SOURCE` versus `SIMULATED_SOURCE` evidence. Every projection retains `physical_device_verified=false` and `charging_capacity_changed=false`. Agent 4 owns the matching canonical Flutter consumer; this backend does not edit Flutter files.

## Verification boundaries

See the companion QA record for exact results. The isolated TLS/OAuth test facade forwards only fixture GraphQL and GetDiagnostics/GetLog to actual local pinned CitrineOS, validates a generated RSA service identity and verifies its local TLS certificate. It uses the local development token only behind the loopback test boundary. This is software interoperability evidence, **not** a live identity-provider deployment, physical charger, production ingress, or hardware acceptance. No charging command or financial write is sent. Source/financial digest checks distinguish this explicitly.

Remaining acceptance: exact-head hosted checks and Agent 5 integration; deployment-specific identity/ingress hardening; real charger upload behavior and operator-approved hardware acceptance. Do not enable production controls or increase the 312-requirement completion count on this document alone.
