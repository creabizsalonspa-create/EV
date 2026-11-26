# OCPP charger pilot acceptance and release gate

**Status:** Draft software acceptance checklist; **not executed or approved**. This document does not grant hardware, payment or production authorization.

**Scope:** Canonical Laravel business service, separately deployed pinned CitrineOS v2.1.0 CSMS, OCPP 1.6 and 2.0.1, approved charger models and firmware. Use a dedicated non-production pilot environment and written operator approval. Do not connect physical chargers to the local development simulator stack.

## 1. Required approvals before any hardware connection

- [ ] Named station/network operator approves the specific charger assets and the pilot time window in writing.
- [ ] Authorized electrical/charging technician owns physical site safety, emergency stop, lockout and supervision.
- [ ] Charger vendor confirms the exact manufacturer, model, serial, firmware build, supported OCPP version and security profile. Unsupported/missing information is a **no-go**, not an inference.
- [ ] Security owner approves dedicated pilot TLS/mTLS credentials, certificate issuer, rotation and revocation, scoped tenant identity, firewalls, secret storage and audit retention. The local `localDev` tokens, loopback port assumptions and OCPP security profile 0 must not be used.
- [ ] Business owner signs off test transactions, maximum permitted energy/current, country/tax/currency, billing sandbox and compensating/refund arrangements.
- [ ] Incident commander, on-site technician, charging-network operator and platform engineer are identified with primary and alternate contacts.
- [ ] A pre-test station availability window and clear customer notice are approved. Never commandeer a public connector or an active customer session.
- [ ] Written rollback/disconnect authority and the conditions that force the pilot to stop are recorded.

The software agent may prepare code, tests and documentation, but must **not** actuate physical charging, issue reset or firmware commands, provision live certificates, or control live power without an explicitly authorized supervised pilot.

## 2. Evidence manifest and immutable provenance

Create one restricted evidence record **per charger model, firmware build, OCPP protocol and connector topology**. Do not extrapolate from another model or firmware. Keep sensitive station identifiers, tokens, certificates, real customers and personal data in the approved private evidence store, never a repository commit.

| Field | Required evidence |
|---|---|
| Pilot reference | Private pilot ID, owner, authorized window and signed approvals |
| Country and tenant | Approved country, site/operator, tenant and legal authority |
| Charger hardware | Manufacturer, exact model, hardware revision and firmware version |
| Protocol | Negotiated OCPP 1.6 or 2.0.1, security profile, transport and CSMS version |
| EVSE/connector | Physical EVSE and connector identifiers, topology and supported power |
| Authentication | Redacted certificate fingerprint/issuer, JWT/scopes and revocation result |
| Time and source | Source station database ID, source transaction ID, synchronized timestamps |
| Charging session | Reservation and canonical link IDs, issued command IDs, *non-secret* event hashes |
| Measurement | Start/end cumulative Wh, independent meter/tolerance, signed final source evidence |
| Finance | Simulated or explicitly approved payment mode, tariff snapshot and invoice evidence |
| Recovery | Lost ACK, replay, offline, reconnect, fault and manual-review evidence |
| Controls | Runbook revision, exact Git commit SHA, CI run links and rollback result |
| Sign-offs | Physical site lead, platform lead, security owner, finance owner and date |

The source identifier alone, a WebSocket connection or an HTTP command receipt is not proof of physical execution. A final invoice requires independently authenticated source transaction and meter evidence according to the canonical ledger rules.

## 3. Software and environment preflight

- [ ] Record exact deployment SHA, expected migrations, independent backup/restore proof, schema verification and rollback path.
- [ ] Verify the CSMS and Laravel business databases are separate; no business worker has direct CSMS database credentials.
- [ ] Verify pinned CitrineOS v2.1.0 and its approved upstream integration contract; any version drift requires a fresh conformance review.
- [ ] Verify signed event inbox HMAC, timestamp tolerance, tenant isolation, scoped APIs, no secrets in logs, rate limits and certificate revocation.
- [ ] Verify OCPP charge-point admission is restricted to authorized and registered station identities with explicit protocol, model and firmware pins.
- [ ] Verify the country currency, charging tariffs, taxes, refunds and payment sandbox; do not enable real payment capture without separate approvals.
- [ ] Verify exactly one active canonical session per relevant EVSE, reservation expiry and maintenance lockouts.
- [ ] Verify immutable source station id pin, authenticated transaction lineage, meter cursor monotonicity and no uncertain command replay.
- [ ] Verify memory, disk, event-queue, clock/NTP and database capacity at agreed alert thresholds.
- [ ] Verify access to private audit evidence and rollback/disable controls for all relevant operators.
- [ ] Verify operational monitoring and customer/vendor/admin fault notices without disclosing source credentials.

### 3.1 Operator-only simulator source contract probe (not a hardware pilot)

In a separately controlled local or test environment, after configuring the independent authenticated CSMS source/inbox secrets, migrations and schema, an operator may inspect one canonical local simulator binding **without** connecting physical hardware or dispatching commands:

```bash
php artisan csms:preflight
php artisan csms:pilot-probe <canonical_binding_id>
```

The pilot probe is deliberately unavailable in production. It requires a unique typed OCPP 1.6 or 2.0.1 source station, current approved vendor ownership, an exact observed vendor/model and nonempty firmware identity, a fresh absolute CSMS heartbeat, complete bounded source connector rows, recent safe idle status for every connector, exact **typed OCPP EVSE/connector target** parity with current local canonical mappings (not just count parity), no unresolved local charging or reset/firmware command hold, and no active source transactions. The source-to-mapping equality is software-lineage evidence only: it does **not** verify physical connector numbering, rewiring, power contactors or meter calibration. Unexpected connector identities, duplicate/contradictory events, clock regression, malformed status, an active transaction or an occupied/faulted connector **block** a successful software-only source inspection. It independently re-reads the authenticated CSMS station after the connector inventory; changes to source identity, model, firmware or a regressing station clock fail closed, and an interim disconnect cannot produce a successful report. It then rechecks current binding and licensed-owner lineage. The operator probe never generates a token, uploads firmware, changes reservations, sends a reset or writes charging/finance state.

**Protocol-specific idle receipt gate:** OCPP 1.6 `StatusNotification` must explicitly report `Available` with `errorCode=NoError` for each connector. Missing/null errorCode is *unknown*, never safe idle. OCPP 2.0.1 uses the distinct `Available / Occupied / Reserved / Unavailable / Faulted` vocabulary; OCPP 1.6 `Charging / Preparing / SuspendedEV / SuspendedEVSE / Finishing` are **not** OCPP 2.0.1 states. An out-of-protocol status must block a pilot source probe. An operator may run `csms:preflight` with the reviewed internal `charging-graphql` service hostname in a local/test network; that **never** authorizes sending Hasura secrets to arbitrary plaintext HTTP hosts.

A successful `simulated_source_observed` result is only a narrowly defined **read-only software-source snapshot**. A blocked result returns `requires_source_review` or an error without leaking source secrets. The response explicitly reports `device_commands_sent: false`, `physical_hardware_accepted: false`, `hardware_pilot_approved: false`, and `production_accepted: false`. Independently preserve the exact source SHA and scoped sanitized output in the private pilot evidence store; never place real station credentials in CI artifacts or Git. Neither this CLI nor any passing simulator test checks real site interlocks, meter calibration, TLS/mTLS physical credentials, external operator consent, or production acceptance. The written approvals in Sections 1–2 and physical evidence in Sections 4–7 remain mandatory.

## 4. Protocol/model test matrix — run separately for each device build

Every row needs a private evidence link and explicit **PASS / FAIL / BLOCKED / NOT TESTED** state. A simulator PASS is not a hardware PASS.

| Gate | OCPP 1.6 | OCPP 2.0.1 | Required acceptance evidence |
|---|---|---|---|
| Admission | Explicit ID/tag and negotiated protocol | Explicit ID/token and negotiated protocol | Only approved charger/model/firmware can connect |
| Boot & clock | BootNotification and Heartbeat | BootNotification and Heartbeat | Fresh source time and correct tenant/identity |
| EVSE topology | Connector ID mapping | EVSE + connector ID mapping | Exact source-to-physical target, no ambiguous mapping |
| Authorization | Approved, expired and revoked idTag | Approved, expired and revoked IdToken | No replay of revoked credentials; private audit |
| Reservation | Admission/cancel/expiry | Admission/cancel/expiry | No double booking or capacity release on uncertain results |
| Start | One authorized RemoteStartTransaction | One authorized RequestStartTransaction | Correct single charger; no duplicate actuation |
| Metering | Absolute MeterValues Wh/kWh | TransactionEvent cumulative registers | Monotonic cumulative Wh; no rounded/guessed units |
| Stop | RemoteStopTransaction and terminal evidence | RequestStopTransaction and Ended evidence | Source terminal proof, one canonical stopped session |
| Faults | Faulted/status notifications | Faulted/status notifications | Traceable alert, no false available-state release |
| Recovery | Disconnect/reconnect, lost ack | Disconnect/reconnect, duplicate seqNo | No double command, double energy or double invoice |
| Firmware | Signed policy/device eligibility | Signed policy/device eligibility | Separate approved maintenance window; no auto-flash |
| Reset | Authorized idle-only safe reset | Authorized idle-only safe reset | Manual operation approval; no unverified completion |
| Privacy | Token/log redaction | Token/log redaction | No real token/certificate/PII in public evidence |
| Rollback | Safely stop test/admission | Safely stop test/admission | Physical technician confirms site-safe state |

The existing `tests/charging-session.cjs` and `tests/charging-business.cjs` cover local simulation. The Agent 2 feature tests cover signed source/fault reconciliation, malformed/duplicate meter pages and simulated restarts. None of these replace the hardware rows above.

## 5. Approved pilot sequence

1. Record the written pilot authorization, exact hardware/version identity and rollback command authority.
2. Bring up dedicated staging services with approved TLS/mTLS and non-development upstream credentials; verify health without a physical charging command.
3. Confirm the registered station identity, EVSE/connector topology, operator identity and signed firmware compatibility policy through read-only probes.
4. Have the onsite technician independently confirm no occupied connector, active reservation, pre-existing energy flow, or unresolved maintenance command.
5. Obtain a single, bounded, supervised test booking and explicit customer/operator charging authorization under the approved pilot limits.
6. Have the authorized operator execute one supervised start using the approved operations tool. Record **command submission** separately from actual charger receipt and measured energy.
7. Collect source heartbeat/status, transaction ID, cumulative register, event time and physical meter observations. Check tenant/source station ID against the pinned canonical link.
8. Have the operator execute a supervised stop. Do not finalize money on an HTTP ACK alone; verify authenticated terminal transaction and cumulative energy before asserting a stopped session.
9. Verify exactly one canonical invoice after final source convergence, private customer/vendor/admin views and finance ledger consistency. Real PSP settlement is **not** part of simulator acceptance.
10. Independently execute only the specifically approved non-destructive fault/reconnect/duplicate/lost-ACK cases. No unsupervised physical power interruption or firmware update.
11. Confirm final safe connector state onsite, preserve signed/redacted evidence, revoke temporary pilot access, and complete all four functional sign-offs.

## 6. Stop conditions and incident handling

Stop the pilot immediately and have the onsite technician restore a safe physical state if any of these occurs:

- Unexpected power delivery or remote actuation, or the wrong physical connector starts.
- Unrecognized source station/tenant/authorization, certificate compromise, protocol or firmware mismatch.
- Nonmonotonic or unexplained meter readings, transaction identity change, unsupported precision, unverified terminal state or invoice duplication.
- Unexpected station offline/fault during charging; missing safety-critical status; stale heartbeat beyond agreed limits.
- A lost/uncertain command outcome that cannot be reconciled to trustworthy source evidence.
- Backup/restore failure, ledger integrity concern, uncontrolled logging of secrets, tenant cross-access or missing incident response owner.

Record the incident ID, exact observed event/time, safe state confirmed by the technician, owning operator and remediation evidence. Quarantine uncertain commands: **never replay a start just because a request timed out**. New starts remain blocked until a qualified reviewer clears the applicable source and capacity conditions.

## 7. Release decision

A pilot remains **BLOCKED** unless every required protocol/model row is explicitly approved, private evidence is retained, physical site safety is confirmed, certificate and operator approvals are current, full staging CI passes at the release SHA, and the billing/security/operational owners sign off.

**Acceptance record**

- [ ] Source/charging implementation and migration review — reviewer/date: __________________
- [ ] Exact source/CSMS and charger model/firmware matrix — reviewer/date: __________________
- [ ] Security, TLS/mTLS, tenant and certificate lifecycle — reviewer/date: __________________
- [ ] Physical charger technician and site safety — reviewer/date: __________________
- [ ] Fault, outage, backup/restore and rollback — reviewer/date: __________________
- [ ] Tariff, energy, tax, invoice and ledger reconciliation — reviewer/date: __________________
- [ ] Business owner authorizes the next scope — reviewer/date: __________________

**Unexecuted/blocked gates are not passes.** Track simulator code/test/merge evidence separately from physical-pilot acceptance and production sign-off; do not mark requirement `5.4.5` or overall production readiness complete merely because this runbook exists.

### Source authority continuity

The read-only pilot probe binds its station and connector evidence to the same preflight-approved GraphQL endpoint, source credential, inbox credential and enabled local simulator adapter. Each request checks that authority before and after the read; an in-flight endpoint change, key rotation or adapter disablement blocks the report rather than combining evidence from different sources. No alternate source receives the captured credential. The offline preflight rejects ambiguous URL control characters, backslashes and malformed authorities. Dual-protocol regression cases exercise these boundaries. This is a software-only criterion, not a physical or production acceptance result; exact-head CI and canonical integration remain required.

### Offline production configuration audit

Run `php artisan csms:production-config-audit` against the intended production configuration using a restricted operator shell. This independent configuration-only check does not request an OAuth token, connect to a source or charger, read a certificate chain, enable an adapter, or mutate the database. It checks the production environment, disabled simulator/session-bridge and local-HTTP exceptions, HTTPS source/operations/identity endpoints and exact operations audience, OAuth client and scope formats, independent service secrets, pinned RSA public keys, lowercase issuer fingerprint syntax with at least one non-denied configured pin, a bounded HTTPS GraphQL path, an operations base/audience without a trailing slash, and presence of canonical charging tables.

A successful exit means **configuration checks passed, live validation required**; a failing exit means one or more checks are blocked. Output contains only named check booleans, no credentials or endpoint values. The command is not a readiness approval: actual endpoint ownership, TLS certificate trust, issuer key validity, OAuth claims, migration/index parity, worker health, real source interoperability and production transport coverage require independent validation. Existing local-only operational paths remain disabled in production and this audit does not implement or authorize them. Do not enable production transport or connect hardware based on this result.

### Offline evidence manifest audit

For each independently approved pilot scope, keep the manifest and the referenced evidence in the restricted evidence store. Run `node scripts/pilot-evidence-audit.cjs /private/path/manifest.json` on the local manifest; do not commit it. The validator reads a bounded regular JSON file, emits only fixed issue codes and boolean evidence limitations, and does not connect to a charger, CSMS, database, payment provider or evidence store.

Version 1 expects these exact top-level fields: `schema_version` (1), `release_sha` (40 lowercase hex characters), `ci_evidence_sha256`, `csms_version` ("2.1.0"), `scope` ("supervised_hardware_pilot"), `approval_scope_sha256`, `approvals`, `preflight`, `models`, and `signoffs`. Hashes are lowercase 64-character SHA-256 digests of privately retained evidence. Each gate object has exactly `state` (PASS, FAIL, BLOCKED, NOT TESTED) and `evidence_sha256` (digest required for PASS, otherwise nullable). The exact keys are exported by the script:

- `APPROVALS`: eight written approvals in Section 1.
- `PREFLIGHT`: eleven checks in Section 3.
- `GATES`: fourteen protocol/model acceptance rows in Section 4.
- `SIGNOFFS`: seven acceptance sign-offs in Section 7.

Each entry in `models` has exactly `scope_id` (private opaque alias, no actual serial or PII), `protocol` ("1.6" or "2.0.1"), `model_firmware_topology_sha256` (digest of the approved exact device tuple record), `evidence_kind` ("supervised_physical_observation"), and `gates` (all GATES). Both protocols and every approved tuple require distinct scope records. The local checker rejects missing gates, repeated tuple records, simulator labels, malformed digests, unexpected fields, duplicate JSON member names (including escaped aliases), and invalid UTF-8. Contradictory duplicate evidence cannot silently become a PASS through last-value-wins parsing. It does not know the actual approved inventory; the reviewer must reconcile the manifest against the privately retained signed scope and verify every referenced digest, actual artifact and signature. No manifest field or digest is a substitute for that review.

Exit code 2 means blocked; exit code 0 means **structurally complete, independent review still required**. Both results explicitly keep hardware, pilot, production, evidence authenticity and approved inventory acceptance false. Never use this exit code as an approval or deployment trigger. `npm run test:pilot-evidence` exercises the schema and redaction behavior with synthetic data only; the existing OCPP validation script also runs these tests.

**Unexecuted/blocked gates are not passes.** Track simulator code/test/merge evidence separately from physical-pilot acceptance and production sign-off; do not mark requirement `5.4.5` or overall production readiness complete merely because this runbook exists.
