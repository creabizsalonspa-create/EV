# Authenticated charger source registration — 21 November 2026

Original target: **5.2.6 Production upstream registration/JWT**, with related preparation in3.2.6. This is source-record registration and verified service-token software. It does not enable production start/stop, rider authorization, diagnostics, firmware/reset transport or a physical charging pilot. Those remain separately unverified requirements. Agent5 owns final whole-atom and integration credit; this document does not award it.

## Delivered workflow

An approved, currently licensed vendor can create `direct_unverified` catalog inventory through the existing station API, select its own country-matching unlinked station through the company catalog selector, and create a linked site and charger through existing idempotent vendor APIs. Registration never converts this inventory into a bookable/controllable source.

The existing web admission pane mounts a separate English/Arabic source-registration module. Current Admin browser authority, including the existing production/staging MFA policy, can explicitly prepare the immutable source identity and then confirm a single source metadata registration. Vendors and accepted inventory readers see a private retained snapshot; canonical Flutter consumes the same read-only API in Agent4's dependent client candidate. No Admin native bearer capability is introduced.

Preparation creates one canonical `csms_bindings` mapping, one **disabled** admission profile and one operational registration receipt. Existing identities/bindings/profiles are refused for separate ownership review, never silently adopted. The source identity is immutable, path-safe and at most36 characters, matching the pinned upstream column. Manufacturer/model must fit its20-byte columns without silent truncation.

An explicit registration request commits its attempt claim before contacting the existing authenticated Hasura control plane. It never updates or deletes an upstream station. A collision, malformed/ambiguous result, wrong tenant/protocol/identity/model, online-state contradiction, changed source generation or lost acknowledgment cannot become registration evidence. Success requires a typed creation reply and matching authenticated read-back of the same positive source record ID. The source record begins offline; no WebSocket connection or device command occurs.

Current actor/status/password/auth epoch/MFA, approved company/license/owner, owned country/site/station/charger, protocol, frozen inventory request hash and exact configured source generation are checked under locks. The external-write transaction is **not retried**. A second process cannot send another source insert. Repeated registered requests return the retained receipt; `registering` after a crash and `requires_inspection` after failure remain non-replayable. There is deliberately no “retry uncertain registration” button or source-absence adoption. A source operator must establish external provenance before any replacement/repair; do not reset the receipt through SQL or infer non-delivery from missing telemetry.

Production credential preparation/admission for direct inventory now requires the matching verified registration receipt and current source/configuration/inventory lineage. Changing manufacturer/model, ownership, mapping or source endpoint cannot reuse an old proof. Existing simulator admission and command paths retain their original guards. Registration does not issue or disclose a charger password, sign a certificate, publish availability, release reservation capacity or touch a financial ledger.

## API

Both existing cookie and versioned vendor-operations mounts expose:

- `GET companies/{company}/chargers/{charger}/source-registration`: inventory-scoped private/no-store status; no upstream request.
- Admin `POST` same path: `{identity, idempotency_key}`;201 on first preparation,200 on identical replay,409 on changed intent or ownership collision.
- Admin `POST .../source-registration/{registration}/register`: explicit one-attempt source creation/read-back or retained outcome. The receipt is its durable operation identity.

States: `not_prepared`, `prepared`, `registering`, `registered`, `requires_inspection`. `verified_at` is nullable explicit UTC ISO8601, retaining the stored microseconds. Numeric receipt/binding/profile/source IDs are nullable where absent. `upstream_registration_verified=true` denotes the **retained metadata creation/read-back proof**, not an online charger. `current_inventory_matches_registration` separately reports current local/configuration lineage on GET; a mismatch must show an operator-review warning. It does not query current source availability.

Responses always disclose `source_snapshot_only=true`, `live_connection_verified=false`, `device_commands_sent=0`, `charging_capability_granted=false`, `charger_hardware_verified=false`, `automatic_retry_allowed=false`. Public responses omit source URLs, admin secrets, request keys/hashes and private credentials. New clients must keep vendor/customer status read-only even if malformed management flags arrive.

## Configuration and recovery

`CSMS_UPSTREAM_REGISTRATION_ENABLED` defaultsfalse. The existing `CSMS_GRAPHQL_URL` must be a fixed credential-free/query-free/fragment-free HTTPS URL and the existing source key must have at least32 characters. An explicitly opted-in local/testing adapter may use the fixed loopback/private simulator host list with `CSMS_UPSTREAM_REGISTRATION_ALLOW_LOCAL_HTTP=true`; production/staging cannot use this exception. `scripts/charger-source-registration-setup` only configures absent local test flags and preserves explicit values. It is not production provisioning.

The separate [operations service identity](OCPP_OPERATIONS_SERVICE_IDENTITY.md) replaces four development-token clients with pinned OAuth2/RS256 service identity. Production cannot mint `/dev/token`; issuer/audience/subject/tenant/role/scope/key/algorithm/time claims must match the explicit contract. The actual pinned CitrineOS JWT provider/API plugin is exercised without listeners or device commands. Hasura registration uses its existing independent authenticated administrative control-plane credential; it does not pretend that a service JWT automatically authorizes GraphQL.

The registration migration refuses populated downgrade. Retain its receipt, canonical binding/profile, source DB and application keys through rollback. No history is reconstructed, deleted or automatically reassigned. Preserve unresolved rows after an environment/transport failure.

## Verification boundaries

Focused API/authority/lineage/failure/replay tests, a four-case two-process PostgreSQL matrix, actual pinned upstream JWT/API-plugin contracts, a controlled browser scheduler suite, and the actual Laravel+isolated CitrineOS/Hasura English/Arabic390px workflow accompany this batch. Final counts and exact SHA are recorded in the QA evidence only after the applicable commands finish. Agent4 provides native projection/widget/HTTP evidence; Agent5 verifies the resulting integrated head, Android/iOS and all required hosted gates.

The initial local runtime attempt failed before readiness because Laravel's reloading development server discarded the external isolated DB environment; the owned server was recreated with `--no-reload`. Two early registration fixtures were quarantined during the local proxy configuration issue. Both `NO_PROXY` and `no_proxy` had to include the private Hasura service for this managed environment. Their receipts were retained and never reset/replayed. Fresh owned fixtures subsequently passed; no claim is made about those earlier uncertain operations.

Physical charger models/firmware, real CA deployment, production provider/issuer enrollment, supervised power/firmware/reset acceptance, live OCPI/PSP/bank, application signing/stores and production deployment still require separate authorization/access/evidence. Broad5.2.7–5.2.11 and5.3 production operations are not completed by this source-registration batch.
