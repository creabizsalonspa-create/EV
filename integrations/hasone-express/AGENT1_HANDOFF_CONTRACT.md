# Agent 2 / Agent 1 — verified Express/OCPP customer integration contract

This file documents **staged, not deployed** code from PR #50. It is not a claim that
the Laravel/CitrineOS platform or the connected physical Teltonika has been commissioned.

## Business ownership boundary

- **Agent 1** owns the canonical Laravel/PostgreSQL/CitrineOS charging and finance stack,
  customer/vendor mobile/web, cross-role demonstrations and live PSP integrations.
- **Agent 2** owns this separate, existing Express/Mongo customer-client integration proof
  against the already deployed `api.hasone.io` API and OCPP gateway.
- A paid booking, authorized charger operation and metered session must never produce
  two invoices or two merchant settlements in different backends.
- **Never** treat source `billingStatus: calculated_unpaid` as collected funds or vendor earnings.
- Hardware OCPP start/stop remains disabled without owner-approved commissioning.

## HTTP contract proved in the isolated Express test app

Customer JWT required unless marked public:

| Method | Route | Verified purpose |
| --- | --- | --- |
| GET | /health | Database health (public) |
| GET | /api/v1/app/settings | Disclose safe currency/payment toggles (public); unsupported saved-card disabled |
| GET | /api/v1/NearestStations | Authenticated station discovery |
| GET | /api/v1/StationDetails/:id | Authenticated station info |
| POST | /api/v1/MyVehicles | Customer garage registration with active model |
| POST | /api/v1/Bookings | Date/slot booking with atomic duplicate rejection |
| POST | /api/v1/Bookings/:id/payment | Provider-bound payment contract; **not live PSP accepted** |
| GET | /api/v1/ChargingStation/:bookingId | Source-backed first charging screen; blocks QR replay after source binding |
| POST | /api/v1/ChargingSession/validate-qr | Authenticated station QR check; does not claim real charging has started |
| GET | /api/v1/ChargingSession/:id/status | Read source-backed metering bound to customer booking/charger/token, no mutation |
| GET | /api/v1/ChargingSession/:id/summary | Ended OCPP source with verified kWh, AED rate, VAT and calculated-unpaid total |
| POST | /api/v1/ChargingSession/:id/start | Reject while no verified canonical hardware start adapter |
| POST | /api/v1/ChargingSession/:id/stop | Reject while no verified canonical hardware stop adapter |
| GET | /api/v1/payment/status/booking/:id | JWT and record-ownership enforced |
| POST | /api/v1/admin/bookings/:id/ocpp/issue-token | AdminAuth: create one scoped, expiring charger token for paid+arrived booking |
| POST | /api/v1/admin/bookings/:id/ocpp/bind-source | AdminAuth: bind exact OCPP source transaction after verification |
| POST | /api/v1/admin/bookings/:id/ocpp/finalize-source | AdminAuth: change booking to Completed only after verified stopped source and calculated-unpaid invoice |
| GET | /api/v1/admin/ocpp/booking-sessions | AdminAuth: linked OCPP kWh and calculated but unpaid AED report |

**Server-generated test data:** The isolated suite seeds a dummy paid+arrived booking
solely to exercise authorization and OCPP linkage. This is NOT a real payment.

### Example customer source-backed status fields

```json
{
  "chargingStatus": "Completed",
  "sourceVerified": true,
  "meterVerified": true,
  "source": "ocpp",
  "protocol": "ocpp1.6",
  "energyWh": 5000,
  "kwhUsed": 5,
  "batteryPercent": null,
  "timeRemaining": null,
  "paymentStatus": "not_collected",
  "canStop": false,
  "canExtend": false
}
```

Real charging evidence is shown only when the session is bound to an approved charger,
the booking has a verified source token, the transaction ID and charger ID match,
and meter values agree with the source. No estimated battery percentage is presented
as a physical charger reading.

### Example Admin unpaid session report fields

```json
{
  "currency": "AED",
  "billingMode": "calculation_only_no_collection",
  "totalCollectedAed": 0,
  "entries": [{
    "energyKwh": 5,
    "calculatedUnpaidAed": 5.25,
    "paymentStatus": "not_collected",
    "meterVerified": true
  }]
}
```

Do **not** enable Stripe, SSLCommerz or raw-card settlement without verifying
signed production/sandbox provider events, amount and currency, customer ownership,
idempotency and actual merchant capture. The staging patch hides and blocks the
original direct card flow and raw PAN collection.

## Reproduce

Run these inside a disposable source copy on the authorized server (not a
production directory):

```sh
python3 tools/agent2/stage_payment_safety.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_booking_slot_uniqueness.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_charging_source_safety.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_ocpp_customer_status.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_admin_ocpp_binding.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_google_maps_config.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_ocpp201_token_binding.py STAGED_OCPP_HANDLERS_COPY

node tools/agent2/run_isolated_backend_demo.mjs \
  STAGED_SOURCE_COPY EXISTING_EXPRESS_DEPENDENCY_ROOT STAGED_OCPP_HANDLERS_COPY
python3 tools/agent2/stage_ocpp_customer_summary.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_ocpp_charging_station_screen.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_ocpp_qr_safety.py STAGED_SOURCE_COPY
node --test integrations/hasone-express/tests/*.test.mjs
```

The staged runner binds both HTTP and MongoDB exclusively to `127.0.0.1`,
uses an ephemeral database, and removes it after testing. It does not issue
commands to the connected physical charger.

## Remaining production dependencies

- Signed actual PSP sandbox success/failure/webhook scenarios; bank settlement
  and refund reconciliation, no raw card storage.
- Authorized physical charger full admission/start/meter/stop/QA.
- One chosen canonical billing and settlement authority between the two backends.
- Real vendor app and Admin parity against that authority (Agent 1).
- Approved OCPI live roaming credentials/agreements.
- Verified offsite Mongo recovery and approved deployment/rollback.
- Google Maps server key rotation/restriction plus environment deployment configuration.

**Current status:** This is an isolated integration implementation, not a deployed
release or complete production acceptance.

### Booking completion rule

The authorized Admin finalize endpoint rejects an active OCPP session or missing meter/tariff proof. After an OCPP source-confirmed stop it updates only the **operational** booking status to `Completed`; `paymentStatus` remains `not_collected` for the metered session, and a repeated finalize is idempotent. Flutter should refresh booking history from the backend instead of inferring completion from client timers.

## Customer screen behavior (staging)

The QR validator confirms station identity only; it returns `canStartCharging:false` and `requiresOcppConfirmation:true` until the authorized gateway reports an actual source transaction. The station detail and active status APIs derive session state from OCPP events, rather than the booked slot clock; a source-bound station cannot be scanned again. A verified stopped session exposes `energyDelivered`, `costPerKwh`, `vatAed`, `totalAmount`, `billingStatus:'calculated_unpaid'`, and `paymentStatus:'not_collected'`. Legacy extension payment is blocked to avoid duplicate settlement against a prepaid reservation.

**Latest reproducibility check:** 55/55 Express/Mongo/OCPP tests, 28/28 guard tests and 13/13 HTTP callback tests passed after a clean patch rebuild on the authorized server. No production deployment or real charger command occurred.

## Existing Next Admin report UI

The owner's deployed Admin is Next.js 16, React 19 and uses its authenticated
Axios `@/lib/api` client and the common admin layout. Agent 2 staged a new
`/ocpp-sessions` page and sidebar link, populated by the protected
`GET /api/v1/admin/ocpp/booking-sessions` contract above. It displays verified
source-linked transactions, customer booking references, metered kWh, OCPP
protocol, and `calculated_unpaid` AED totals. It explicitly shows **AED 0
collected** in the absence of a verified PSP settlement.

Source: `integrations/hasone-next-admin/ocpp-sessions-page.tsx`.
Install (staging only): `tools/agent2/stage_next_ocpp_report_page.py`.
On a copy of `admin-password-rotation` from the authorized server, Next.js
TypeScript check passed and **Next.js 16.1.6 Webpack production build succeeded**,
including the `/ocpp-sessions` route.

This is NOT deployed at `admin.hasone.io`; the live Admin remains unchanged.

## Admin booking operations panel and safe workflow state

Agent 2 added an authenticated GET route:
`GET /api/v1/admin/bookings/:id/ocpp/operations`.

The response returns only non-secret booking readiness and OCPP evidence:
`bookingPrepaymentRecorded`, `arrivalRecorded`, `approvedChargerId`,
`authorizationIssued`, `tokenIssuedAt`, `tokenExpiresAt`,
`sourceSessionId`, `sourceVerified`, `meterVerified`,
`sourceEnded`, `energyKwh`, `calculatedUnpaidAed`,
`canIssueToken`, `canBindSource`, `canFinalizeSource` and
`needsPaymentReconciliation`. Raw authorization credentials and hashes
are never returned by this GET route.

The authorized Next Admin Bookings page includes a new **OCPP** action per
booking. The modal uses that GET route, approved station OCPP session listing,
and existing AdminAuth POST routes to issue a one-time token, bind the exact
source ID and finalize only after verified OCPP stop. The raw one-time token
is shown to the Admin once, held only in component state and cleared on close
or when the browser tab becomes hidden. There is no browser charging command.

Frontend source: `integrations/hasone-next-admin/OcppBookingWorkflow.tsx`.
Safe copy patcher: `tools/agent2/stage_next_ocpp_booking_workflow.py`.

**Validation:** 60/60 Express/JWT/disposable Mongo/OCPP integration checks
passed, including operations state before/after token, binding and completion.
The purchased Next.js 16.1.6 Admin passed TypeScript and a full production
Webpack build containing the new Bookings workflow and OCPP report page.

All the changes remain in draft PR #50; not deployed to the live Admin URL.


## Multi-charger contract (Agent 1 integration)

The existing source backend originally assumes one charger per station.
The parallel staged workstream now supports a station with multiple
registered OCPP chargers while retaining the existing single-charger
contract for old records.

All routes require `AdminAuth`:

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/admin/stations/:id/ocpp/chargers` | List approved/disabled station chargers without secrets |
| POST | `/admin/stations/:id/ocpp/chargers` | Add a charger with unique global ID and one-time credentials; remote control stays disabled |
| GET | `/admin/stations/:id/ocpp/booking-sources` | List up to 100 station transactions with charger ID, source session ID and protocol; no authorization hashes |

The existing protected `GET /admin/bookings/:id/ocpp/operations`
also returns `approvedChargers` (ID, protocol, online),
`requiresChargerSelection` and `selectedChargerId`.
For more than one approved charger, Admin **must** send
`{ "chargerId": "<exact selected approved charger>" }` to
`POST /admin/bookings/:id/ocpp/issue-token`.

After token issuance, `BookingModel.ocppChargerId` is pinned. The same
booking cannot receive a new token for another charger. The OCPP access
token is scoped to that charger; the customer status API, Admin bind
and finalize, and source reports must all enforce this exact pin.
The Next Admin source dropdown displays only source transactions from
the selected/pinned charger even when a station has several.

The source-to-finance authority remains unchanged: verified energy
and calculated unpaid AED are not PSP receipts or vendor revenue.

**Production index migration is NOT performed:** The real MongoDB
`ocpp_chargers` collection currently has `stationId_1` uniquely
indexed and only one charger record. Before supporting more stations in
production, use the explicit reviewed migration module
`integrations/hasone-express/migrateChargerStationIndex.mjs` in
a controlled backup/rollback window, replacing unique station index
with a nonunique lookup and retaining globally unique charger IDs.
Do not silently drop indexes inside HTTP handlers or deploy two
charging/payment authorities.

**New validation:** 73/73 integrated isolated backend checks,
36/36 pure guards and 13/13 callback checks; 8/8 headless Chrome
Admin UI checks with mocked API responses; TypeScript and full Next
production build pass on a source copy. All changes remain in draft
PR #50 and must be reconciled with Agent 1's Laravel/CitrineOS ledger
and Flutter/vendor applications.


## Important: single-use charger authorization hardening — awaiting staging retest

The source gateway staging patch now marks an authorized booking charger token
as consumed **atomically on actual start**, rather than merely accepting the
same token until its expiry. The read-only `Authorize` handshake still checks
eligibility without consuming the token; the same consumed credential cannot
start a separate OCPP 1.6 or 2.0.1 transaction. Safe OCPP 1.6 lost-response
retries may reuse the existing active transaction only for the exact same
charger/connector/token hash/meter start.

`tools/agent2/stage_ocpp201_token_binding.py` is the source patcher;
`tools/agent2/run_isolated_backend_demo.mjs` contains replay cases.
The gateway is not production-deployed. Tests of this latest change are
**pending** because the remote test host timed out during file synchronization.
Do not mark it accepted before staged tests pass and charger-specific protocol
retry handling is approved.


## Additional Agent 2 contract: safe reissue, index preflight and binding concurrency

**New AdminAuth endpoint**

`POST /api/v1/admin/bookings/:id/ocpp/reissue-token` accepts an
optional `chargerId` that must match the booking's original pinned
charger. The response on a successful safe reissue includes
`oneTimeToken`, `chargerId`, `expiresAt` and `reissued:true`,
and never represents a real charger start or financial settlement.

`GET /api/v1/admin/bookings/:id/ocpp/operations` adds
`canReissueToken`. The Admin modal shows the action only after the
backend verifies 20-minute TTL + 2-minute safety grace, no valid
old credential, no durable consumed-token evidence, no observed
OCPP source, and no already bound source. Raw token and hash are
never returned by the read-only operations route.

**Gateway requirement:** On an actual OCPP start, atomically claim
the token and remove its TTL expiry field from the consumed record
so its use cannot later be forgotten by Mongo TTL cleanup.
OCPP `Authorize` is read-only. Exact OCPP 1.6 lost-response retries
may reuse only the original active transaction identity.

**Source-binding constraint:** `ChargingSession` source-bound
`{bookingId,userId}` and `sourceSessionId` indexes are unique
and binding update is conditional. Concurrent duplicate Admin
requests must be idempotent or rejected, never reassign a booking.

**Read-only release preflight:**
`integrations/hasone-express/ocppSourceBindingIndexAudit.mjs`
checks duplicate source IDs and bookings before installing either
uniqueness index. Index writes in this helper are intentionally
restricted to the disposable `agent2_demo_ephemeral` database.
Do not let staging auto-index settings become a surprise
production migration; verify production duplicate counts before
a separately approved release.

**WSS configuration:** For physical charger onboarding, set the
actual verified `OCPP_PUBLIC_WS_BASE` URI. The staged Admin API no
longer manufactures a charger WebSocket URL when one is not configured.

**Verification:** The expanded isolated full-stack test runner has
90 scenarios, including new expired-token and concurrency cases, but
the latest additions are pending on-host integration/Next build
because Desktop Commander was offline. Earlier 73/73 backend and
8/8 mocked-browser checkpoints remain the last verified full runs.
40/40 additional pure policy logic checks were executed independently.
This is not a live merchant payment or Teltonika charger acceptance.


## Operator recovery from interrupted OCPP starts (new)

The authorized start credential is consumed atomically by an accepted source
start; `Authorize` itself must never consume the credential. If an OCPP
worker crashes after credential consumption but before source transaction
persistence, **do not automatically reactivate the token**. Instead:
- Query the protected, read-only
  `GET /admin/stations/:id/ocpp/start-exceptions` endpoint.
- Check charger-specific protocol messages, logs and source transaction IDs.
- Respect the two-minute OCPP 1.6 lost-response retry window and
  identify any transaction that was accepted before treating it as orphaned.
- Escalate a consumed credential with no confirmed source to the operator;
  separate authorized recovery/reissue must verify that no live session
  exists and must not create duplicate invoices or a second charge.
- Keep calculated OCPP metering and actual payment capture in different
  states until reconciled by one canonical financial authority.

The new redacted Admin report is intentionally informational and does
not include a button to reset or reissue consumed credentials.
Source `integrations/hasone-express/ocppStartAudit.mjs`;
stager `tools/agent2/stage_admin_ocpp_binding.py`;
UI `integrations/hasone-next-admin/OcppBookingWorkflow.tsx`.

These latest changes await staging rerun because the authorized host
is offline; they must not be merged or deployed on earlier test results.


## Uncertain credential registration recovery — 7 November 2026

Initial token issue and expired-unused token reissue retain the **newest booking
hash, selected charger and issuance time** when credential registration fails.
A database insert may have committed even when its acknowledgement was lost.
Clearing the pin or restoring the expired old hash could otherwise permit a
second credential while the first remains enabled.

The handler attempts revocation of the new credential but never treats a failed
revocation as proof it is absent. It returns HTTP 503 without disclosing a raw
token. Reloaded operations block immediate issue/reissue; the existing expiry,
settling-grace, consumed-token and observed-source checks still govern recovery.
Consumed credentials must never be automatically reactivated.

Six fault-injected integration cases cover initial issue and reissue for:
insert failure before commit, acknowledgement loss after commit, and committed
insert plus unavailable revocation. Each case checks the persisted pin, no raw
credential exposure, eight rejected concurrent issue retries, rejected early
reissue and redacted Admin state. These run only against disposable local Mongo.

The new regression fails against parent source `8a8f292b` (booking hash cleared)
and passes with this fix. Production source and physical chargers are unchanged.

The isolated validation wrapper now uses `exec` when starting its loopback Next
server so cleanup targets the server PID instead of leaving an orphaned child.
The final acceptance run verifies the selected test port is released afterward.


## Complete interrupted-start history review — 7 November 2026

The read-only station endpoint now accepts `?limit=1..100&cursor=OBJECT_ID`.
Default page size is 50. It returns `scanned`, page counts, redacted
`exceptions`, `hasMore` and `nextCursor`. Cursor values are immutable Mongo
record IDs, never credential hashes or raw tokens. Unsupported query fields,
malformed cursors and invalid limits are rejected with HTTP 400.

Pages seek by descending immutable record ID, rather than offset or the
non-unique consumption timestamp. New insertions do not shift subsequent
pages. Refresh restarts review to include new activity; this is not a
transactional snapshot or a total incident count. A page containing only
matched/grace-period records still supplies continuation to older incidents.

All registered station chargers are included, even disabled ones. Disabling a
charger does not resolve its consumed credential or authorize automatic reissue.
Station scoping remains enforced on every page.

The Admin modal accumulates scanned/matched/grace/incident counts and exposes
**Load earlier records** until the end. Failed page loads retain the loaded
incidents and cursor for retry. Closing the dialog invalidates pending audit
responses so stale results cannot refill a reopened report.

Coverage includes equal timestamps across page boundaries, insertions between
pages, matched-only pages, invalid query inputs, disabled-charger incidents,
foreign-station exclusion, credential redaction and browser retry/append/end
states. No production migration, credential mutation or hardware command is
part of this history view.


## Source evidence validation and actual-API browser runner — 7 November 2026

Source verification now rejects missing/coerced timestamps, timezone-less or
impossible ISO string dates, and reversed stops. Mongo Date objects and explicit
ISO timezone offsets remain supported. The billing state starts as
`unverified`; only consistent metering permits provisional state, and only
reconciled ended-source tariff/subtotal/VAT/total proof permits
`calculated_unpaid`. An invoice's own label is not its verification.

**Locally executed:** 94/94 Node tests, including three new source-evidence
regressions and four relay tests against actual disposable Node HTTP servers.
The source-evidence suite applied to parent `8e6f2e1` reports two regression
failures; the candidate passes. Node syntax checks and shell syntax pass.

**New opt-in actual backend browser journey (not executed yet):**
```sh
RUN_AGENT2_BROWSER=1 RUN_AGENT2_REAL_BROWSER=1 \
PUPPETEER_PATH=/authorized/path/to/puppeteer.js \
CHROME_PATH=/authorized/path/to/chrome \
bash tools/agent2/run_all_isolated_validation.sh
```
In addition to the existing mock-response browser suite, this mode starts a
fresh disposable Mongo/Express/OCPP harness while the copied Next Admin runs.
Chrome issues a booking token through the real protected HTTP controller;
the real staged OCPP handler records synthetic start/meter/stop; Chrome binds
and finalizes that source; persisted booking/session records and the customer's
read-only API must agree on 5 kWh, 525 calculated-unpaid fils and one session.

`isolatedAdminApiRelay.mjs` forwards actual HTTP responses rather than fixture
bodies. It accepts explicit loopback origins only, an exact test Admin identity,
and allowlisted GET/POST routes. It rejects API redirects and unrelated
financial/hardware mutations; it forwards neither browser cookies nor
provider response cookies. The browser blocks nonlocal network destinations.
The one-time charger token is not printed, passed on CLI or stored by the UI.
Admin auth uses the application's normal storage in a disposable browser.

**Runtime limitation:** the authorized Desktop Commander host is offline.
The complete new Chrome/Express/Mongo/OCPP journey and the full existing backend
and Next build suites have NOT been rerun for this revision. Earlier counts
101 backend / 13 callback / 11 mocked browser belong to parent `8e6f2e1`.
Only the 94 local tests and syntax checks above are current evidence.
Keep draft pending hosted checks and an authorized host rerun. No live source,
credentials, customer data, financial settlement or physical charger was changed.


## Protocol-sized booking credentials — 7 November 2026

Initial issue and expired-unused reissue now call one CSPRNG generator that
returns 20 uppercase hexadecimal characters from ten random bytes (80 bits).
The previous 48-character output exceeded the OCPP 1.6 identifier field.
The full 64-character SHA-256 stored digest, charger/booking scope, expiry,
consumed-token retention and uncertain-write guards remain intact.

Basis: Open Charge Alliance OCPP 1.6 edition 2, section 7.28 (IdToken /
CiString20Type), authored OCA specification mirrored at:
https://downloads.regulations.gov/FHWA-2022-0008-0403/attachment_6.pdf

The Admin receipt validator and both mocked and actual-API browser runners use
the new 20-character contract. The source stager copies the shared generator
beside the controller. The staged OCPP 1.6 StartTransaction guard rejects an
oversized tag before token consumption or source creation; its integration
fixture now checks that rejection and retains the unused token.

**Current executed evidence:** 97/97 local Node tests, including generator wire
length/character format, SHA-256 identity and atomic single-use behavior.
Changed JavaScript parses and both Python stagers compile. No old 48-character
receipt assertion or randomBytes(24) issuance remains in the changed flows.

**Not executed:** full Express/Mongo/OCPP and Admin build/browser reruns,
including the new oversized-start integration test, because the authorized host
remains offline. Prior runtime counts do not validate this revision. This fix
does not certify a physical charger, enable controls, settle funds or deploy.


## Full disposable runtime acceptance — 7 November 2026

The authorized host returned online. All 51 parent d145b17 PR files were
verified against Git blob SHA-1 before isolated materialization. The complete
wrapper ran with RUN_AGENT2_BROWSER=1 and RUN_AGENT2_REAL_BROWSER=1.
Two harness-only defects were corrected: an unrelated-charger fixture retained
an oversized legacy token, and the mocked finalization test checked the DOM
before the completed-state refresh finished. No application protections were weakened.

Passed: 97 Node guards; 13 callback HTTP checks; 102 backend/OCPP checks;
Next TypeScript and optimized Webpack build; 11 mocked Chrome checks;
7 actual-API Chrome assertions. The second fresh disposable backend reports
103 checks including the real browser journey. Wrapper exited 0 and port 3453
was released. Actual UI calls exercised JWT-protected Express, disposable Mongo
and real staged OCPP handlers; 5 kWh and 525 unpaid fils agreed with persisted
and customer read-only records. This is synthetic loopback evidence, not live
hardware or PSP acceptance.

Restricted host log /home/hasio/agent2-work/pr50-runtime-v3.log, SHA-256
27b8780926d63665b5f1f4e44ad90b84c9f8b12367b6c878fef7ecaf926e7d5c.
Agent 4: review the exact pushed revision and required hosted checks before
integration; hosted checks remain separately queued/pending. Do not infer
production deployment, canonical-ledger migration or physical commissioning.
