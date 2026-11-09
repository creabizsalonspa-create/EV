## Local original-source acceptance and portable reproduction

The full-source runner accepts read-only authorized source copies through
`AGENT2_SOURCE_BACKEND`, `AGENT2_SOURCE_GATEWAY`, `AGENT2_SOURCE_ADMIN`, and an
existing local `AGENT2_STAGE_ROOT`. Defaults retain the original host layout.
The stage root is resolved before use and cannot be below deployed source.
Prepare backend/Admin dependencies from their original locks in those local
copies; supply an official `mongod` on PATH plus headless Chrome/Puppeteer for
`RUN_AGENT2_BROWSER=1 RUN_AGENT2_REAL_BROWSER=1`. No production env files are
needed or copied. All test listeners bind127.0.0.1; only owned temporary services
and data are removed. No source files or fixtures need to be written on the host.

Current final local evidence includes186 Node,15 Python,13 HTTP callback,
154 actual Express/Mongo/OCPP checks, the purchased Admin TypeScript/Next build,
21 mocked booking Chrome cases and13 actual API Chrome cases. Four new real API
regressions introduce ambiguous registry records only inside the harness-owned
`agent2_demo_ephemeral` DB; its unique charger index is restored before later
scenarios. Never run these fixture corruptions against an external DB. The
runner creates its own URI and does not accept an external connection string.

Exact hashes and scope: `docs/evidence/agent2-local-original-source-acceptance/validation.json`.
Native/hosted release gates, canonical capacity/identity/finance contracts and
Agent4 integration remain pending; hardware/PSP/production acceptance is separate.

## Explicit booking calendars and read-only capacity preflight

The staged API accepts full `YYYY-MM-DD` calendar identity, rejects impossible
calendar days and overlapping/overnight static station slot definitions, and
blocks unresolved legacy day labels instead of guessing their year. Customer
creation/rescheduling and slot queries use the same year-qualified date. Prices,
prepayments and authorization/source receipts are preserved.

AdminAuth `GET /admin/stations/:id/ocpp/reservation-readiness` returns bounded
non-mutating calendar/resource findings, preserving wildcard legacy station
occupancy and shared-EVSE exclusivity. Scope identity is not allocation evidence.
No request creates/drops indexes. Incomplete scans and protected records cannot
clear preflight. This does not implement the future transactional resource
scheduler; concurrent calendar edits, independent resource capacity and the
explicit migration remain pending.

Evidence: `docs/evidence/agent2-reservation-calendar/validation.json`. Current
validation:122 guards,13 callbacks,133 backend/OCPP,13 mocked Chrome,11 actual-API
Chrome, Next TypeScript/build; backend plus browser/native134. Companion native39
tests and2 separately passed actual-API tests cover year-preserving booking and
private scoped history. Required hosted/native/Agent4 gates remain separate.
Release paired backend/mobile only after ambiguous legacy records are reviewed.

## Connector-scoped authorization and source verification

Admin may supply `resourceScope:{protocol,connectorId,evseId}` when issuing a
booking credential. IDs are integers1–64; OCPP1.6 has null EVSE, OCPP2.0.1 requires
an EVSE ID. The booking and token retain the exact immutable scope. Mongo checks
scope before token consumption, and reissue cannot redirect it. Meter updates,
source binding/status/history and finalization reject conflicting resource
identity. The Admin dialog and customer history distinguish explicit verified
scope from legacy charger-wide authorization. Scope does not prove availability,
commissioning, or independent reservation capacity.

Existing legacy token behavior is retained and labelled unscoped. Requests cannot
silently clear a pinned scope. Malformed OCPP2.0.1 resource starts are refused
before consuming credentials. The existing station-wide booking-slot index stays
in place. Resource-level capacity/migration remains unfinished; this subset does
not complete the original reservation or live-source requirements.

Current source evidence: `docs/evidence/agent2-resource-scope/validation.json`.
116 guards,13 callback checks,129 backend/OCPP checks,13 mocked Chrome checks,
10 actual-API Chrome checks and Next TypeScript/build pass. Native35 tests and
real API scope decoding pass on the companion PR70 source. All are disposable
software checks, separate from hardware, partner, merchant and production gates.

## Latest staged customer booking safety — Agent 2

Customer cancellation/rescheduling uses atomic booking-state comparisons and
retains the existing station-slot unique index. The self-service path only
changes unused unpaid bookings without arrival, session links or token/charger
pins. Paid bookings require the canonical refund/re-quotation/operator workflow;
this compatibility patch does not issue refunds. A selected reschedule slot must
exist at an available station, match the display time and preserve the quote
length. Concurrent target-slot requests have one winner. State changes after a
read prevent stale writes. Cancelled legacy bookings cannot bind new OCPP sources.

Reproduction adds `stage_booking_mutation_safety.py` after the existing ten
patchers; `run_all_isolated_validation.sh` includes it. Node111 guard tests,
13 callback HTTP checks and 117 backend/OCPP checks plus one actual Flutter
history API test passed on disposable data (118 combined). PR70 contains the
native history consumer and reproducible real-API wrapper. Exact blobs and logs:
`docs/evidence/agent2-booking-mutations/validation.json`. No live source/index,
PSP or physical charger was modified. Hosted checks and Agent4 integration remain
required. Historical checkpoints below keep their original verification scope.

# Agent 2: Express/Mongo payment-integrity staging patch

**Status: staged and tested, NOT deployed.** This is exclusively for the owner's separately deployed Hasone V-CHARGE Express/Mongo application, not for the Laravel/CitrineOS application.

## Verified problem in the *live* deployed source (2026-11-07)

Inspected `/home/hasio/ev-deployment/admin/UV Charging/src` through authorized Desktop Commander. The deployed code allowed unverified payment-return paths to alter booked/charging payment state, accepted an unsafe Stripe webhook verification fallback, exposed payment-status polling without authenticating ownership, accepted legacy direct-card paths without evidence from a PSP, and included direct raw card storage.

These are important blockers to a complete, trustworthy customer → vendor → Admin charging-and-invoice demonstration. No payment callback mutation was exercised against live production during this inspection.

## Implemented

- `paymentIntegrity.mjs`: pure fail-closed matching of verified PSP proof, server-created transaction/PaymentIntent, record identity, amount, currency, pending state, and user ownership.
- `tests/paymentIntegrity.test.mjs`: 13 pass/fail regression tests, runnable under Node 22 with no external packages.
- `tools/agent2/stage_payment_safety.py`: preflighted source-level hardening of `app.js`, `paymentRoutes.js` and `userService.js` on a disposable copy. Refuses canonical production directories, rejects changed/unrecognized source patterns before writing, captures raw Stripe request bytes before JSON parsing, rejects unsigned webhooks, gates SSL IPN settlement on verified PSP response/amount and server identity, restricts payment status to record owner, and prevents unsupported legacy card settlement/raw PAN storage.

**Verified on the real host:** The script was run on copies of the three deployed source files under `/home/hasio/agent2-work/src-snapshot-20261107`. All three modified files passed `node --check`, and 13/13 guard tests passed on `host.crea-biz.com`. Live source and processes were not modified or restarted.

## Safe reproduction

Copy the authorized current Express `src` source to a disposable directory **outside** `/home/hasio/ev-deployment`, then from the repo root:

```bash
python3 tools/agent2/stage_payment_safety.py /tmp/ev-express-source-copy
node --check /tmp/ev-express-source-copy/src/app.js
node --check /tmp/ev-express-source-copy/src/routes/paymentRoutes.js
node --check /tmp/ev-express-source-copy/src/services/userService.js
node --test integrations/hasone-express/tests/paymentIntegrity.test.mjs
```

The staging patch only applies to a matching Express source revision. It does **not** run any Stripe or SSLCommerz transaction, create an OCPP session, or modify the financial database.

## Before deployment (mandatory)

1. Take and verify an encrypted/restorable MongoDB backup, confirm deploy rollback and audit record. The existing source snapshot alone is insufficient.
2. Test actual signed Stripe webhook raw payloads with a configured webhook secret and authorized sandbox account.
3. Verify actual SSLCommerz SDK validation response fields and amount/currency behavior with authorized sandbox notifications. An empty/unconfigured provider correctly fails closed.
4. Run route-level authenticated ownership and sandbox invoice tests; cover duplicate webhooks, retries and out-of-order events. Add idempotent acknowledgement and atomic settlement where necessary.
5. Replace legacy raw-card collection with PSP tokenization and review any preexisting stored PAN according to your payment compliance obligations. The staging patch **disables** unsupported direct card workflows; update mobile UI/payment-method displays accordingly.
6. Do not turn on OCPP remote controls, live billing or actual charging without independent source and hardware acceptance.
7. Agree with Agent 1 on one canonical charging and billing authority before syncing Express/Mongo data into Laravel/CitrineOS.

This PR is intentionally draft pending authenticated sandbox acceptance and approved deployment controls.


## Additional Agent 2 connected development — November 7, 2026

Staging-only code now includes:

- **Station booking exclusivity:** partial Mongo unique index on active station/date/time slots; 10 parallel customer requests result in one valid reservation; cancelled booking releases the slot.
- **Source-safe charging:** imported purchased Flutter API's old charging start/stop/time-based meter estimates are disabled until evidence-backed OCPP adapters are commissioned; GET does not generate energy or mutate charging.
- **OCPP 1.6 and 2.0.1:** customer tokens are bound to authenticated bookings and existing charger registrations; raw authorization credentials are not stored in transaction records; 2.0.1 token hash is preserved in the staged gateway.
- **Admin-scoped source binding:** protected `issue-token` and `bind-source` API routes ensure a paid, arrived booking and exact charger/transaction/meter evidence. Simultaneous token issuance yields one credential; a source session cannot bind to multiple bookings.
- **Customer charging screens:** Station QR and initial session screen no longer claim physical charging before OCPP evidence. The final charging summary derives kWh, rate, VAT and calculated unpaid AED charges from the verified source, not synthetic session data.
- **Customer sourced status:** authenticated customer API reads only the matching OCPP session's confirmed kWh, and cannot show imaginary battery percentage or a collected payment.
- **Source-backed booking completion:** Admin-authenticated finalize route moves bookings to `Completed` only after an OCPP Ended event, verified meters and a calculated-but-unpaid invoice. Repeated calls are idempotent.
- **Admin sourced reporting:** AdminAuth-only report shows metered kWh and `calculated_unpaid` AED estimates, never fake received revenue.
- **Server Maps key:** inherited source hardcoded key replaced in staging with `GOOGLE_MAPS_API_KEY` from environment, and distance lookup disabled gracefully if absent. Restrict/rotate the exposed original key before future deployment.
- **Encrypted database backup:** live Mongo ciphertext snapshot retained exclusively on owner's server; archive decrypt + `mongorestore --dryRun` succeeded. Offsite restore remains unverified.

**Validation on authorized host**: 28/28 payment+OCPP pure guard tests,
13/13 isolated route-level payment callback tests, and **55/55**
integrated Express/JWT/Mongo/OCPP scenario checks across authenticated
customer/admin, bookings, payments, OCPP1.6/2.0.1 and source reporting.
These tests use a local ephemeral MongoDB and staged source and do **not**
prove physical charging, live merchant capture, or production acceptance.

Detailed consumer API fields and deployment boundaries are in
[`AGENT1_HANDOFF_CONTRACT.md`](./AGENT1_HANDOFF_CONTRACT.md).
To rerun, stage the source with the independent patchers in
`tools/agent2/` and execute `run_isolated_backend_demo.mjs` with
the required dependency root and patched OCPP handler. The runner
rejects production directories.

**Status:** All new work is in PR #50, not merged or deployed. Hosted
GitHub Actions may be queued; local staged checks passing does not override
mandatory CI or PSP/hardware acceptance.

### Latest clean-staging reproducibility

Rebuilt from the deployed Express source snapshot with all ten stage patchers and the OCPP 2.0.1 handler patch; validated Node syntax, compiled Python patchers, and ran 55/55 integrated, 28/28 pure source/payment and 13/13 real-route payment callback tests on a disposable localhost MongoDB. The full tested staging tree remains only under `/home/hasio/agent2-work/repro-customer-admin-20261107` on the authorized server. It is **not** exposed publicly or deployed into the live app.

## Authenticated source-backed Next Admin reporting

Agent 2 created `integrations/hasone-next-admin/ocpp-sessions-page.tsx` plus
`tools/agent2/stage_next_ocpp_report_page.py`. The isolated Next.js 16.1.6
frontend passed TypeScript and a full production Webpack build, including the
new `/ocpp-sessions` route and sidebar navigation. The existing admin token
interceptor is reused; there is no embedded API secret or browser-side payment
collection. The page is staging-only, not deployed or public.

## Booking-to-charger operations interface (staging only)

Added `AdminGetBookingOcppOperations`, a protected read-only endpoint
for the existing Next Admin Bookings workflow. The new
`integrations/hasone-next-admin/OcppBookingWorkflow.tsx` renders an
Admin-authenticated three-stage operator dialog (issue a scoped/expiring
token → select and bind approved station OCPP source → finalize after proven
metered stop). It never remotely activates charging. The token is held in
volatile component state and no localStorage, logs, URLs or API polling
responses contain raw authorization credentials.

Use `tools/agent2/stage_next_ocpp_booking_workflow.py` on a disposable
copy of the owner's Next Admin source. The workflow is **not deployed**.
The isolated backend now passes **60/60** checks; Next Admin TypeScript
and Next.js Webpack production compilation pass. The financial amounts shown
remain *calculated but not collected*.


## Agent 2 multi-charger + actual browser acceptance (latest, November 7 2026)

**Implemented on draft PR #50, staging only:**
- `integrations/hasone-express/adminMultiCharger.js`: AdminAuth charger listing, additional charger registration and station-wide source transaction listing; no charger commands
- `ocppMultiCharger.mjs`: deterministic charger selection, no implicit first charger for a station with multiple enabled devices, immutable booking-to-charger assignment
- `migrateChargerStationIndex.mjs`: explicit audited migration helper for disposable/staging Mongo; does **not** run during live API calls
- `adminOcppBooking.js`: booking token issuance requires explicit charger when several are approved and pins `ocppChargerId` atomically; all source validation/summary/finalization uses the same pin
- `stage_ocpp_customer_status.py`: customer API rejects metering from a charger that does not match the booking's pinned ID. Legacy single-charger bookings retain a safe fallback
- `integrations/hasone-next-admin/OcppBookingWorkflow.tsx`: approved charger selector and matching source-session filter in Bookings; token remains visible until Admin closes the modal, rather than being lost during a parent-table refresh

**Observed production index shape (read-only check):** `ocpp_chargers`
has a unique `chargerId_1` index and a **unique `stationId_1`**
index, and contained **one** charger record at the time inspected.
The live unique station index has **not** been changed. Real multi-charger
registration must not be enabled until an approved maintenance-window migration
drops that unique station index *after* ensuring unique `chargerId` values,
backing up MongoDB and verifying rollback capability. A nonunique
`station_lookup_multi` index replaces it. The legacy registration route in
staged source no longer recreates the unique station index.

**Verified on the authorized host:**
- **73/73** isolated Express/JWT/Mongo/OCPP integration tests, including
  two approved chargers at one station, denied registration before index
  migration, exclusive booking selection, invalid charger tokens,
  rejected cross-charger session binding and source-backed customer metering
- **36/36** pure payment/source/multi-charger guard tests
- **13/13** actual staged payment callback-route checks
- **8/8** headless Chrome browser checks of the real staged Next.js Admin
  UI (API responses explicitly mocked in this browser test):
  unauthenticated redirect, authenticated bookings, charger selection,
  one-time token issue, correct source filtering, binding, finalization
  and secret cleanup
- Next.js 16.1.6 Admin TypeScript and optimized Webpack production build passed.

The browser test found and helped resolve a genuine UI defect: reloading the
parent Bookings table immediately after token issue unmounted the modal,
hiding the one-time token before it could be copied. The corrected UI defers
the parent refresh until the dialog is closed.

**Important distinction:** isolated backend tests exercise actual staged
Express controllers, MongoDB and OCPP handlers. Browser acceptance uses
explicitly mocked localhost API responses; it does not prove a live user
login, real merchant payment or physical Teltonika charging. Production
remains unchanged, and this PR should remain draft pending operational
readiness and Agent 1 canonical platform integration.


## Staged OCPP start authorization hardening (pending isolated rerun)

A further patch in `tools/agent2/stage_ocpp201_token_binding.py` now:
- keeps OCPP `Authorize` preflight **read-only**; merely checking an authorization cannot use it up;
- atomically changes a valid `ocpp_access_tokens` entry from enabled to consumed
  when **StartTransaction (OCPP 1.6)** or **TransactionEvent Started (OCPP 2.0.1)** is accepted;
- rejects another transaction attempting to reuse the same booking credential,
  even if its 20-minute expiry has not elapsed;
- permits a narrowly scoped OCPP 1.6 retry to receive the original *active*
  transaction ID only when charger ID, token hash, connector ID and initial
  meter value all match. This is not permission to open a second transaction;
- preserves the OCPP 2.0.1 `idTokenHash` source evidence field without retaining raw credentials.

The regression cases in `tools/agent2/run_isolated_backend_demo.mjs` include
OCPP 1.6 preflight, retry and post-stop replay rejection and OCPP 2.0.1
second-transaction rejection.

**Current verification limitation:** The authorized Desktop Commander server
stopped responding during the sync/test call. The code and tests have been
committed but the modified staged gateway has **not** been rerun successfully
this turn. Do not claim the new single-use behavior is deployed or validated,
and keep the live Teltonika gateway untouched. Previous passing 73/73,
36/36, 13/13 and 8/8 checkpoints predate this patch.


## Security/recovery hardening — 7 November 2026, latest follow-up

All work described here is committed in **draft PR #50**, on
`development/agent2-live-demo-readiness`, not deployed.

### 1. Durable single-use OCPP start authorization

`integrations/hasone-express/ocppStartAuthorization.mjs` provides an atomic
MongoDB token claim for OCPP 1.6 `StartTransaction` and OCPP 2.0.1
`TransactionEvent Started`. The read-only `Authorize` request does not
consume tokens. Once claimed, the token record is marked
`enabled:false`, given `consumedAt` and its TTL `expiresAt` field is
**removed atomically**. This matters: MongoDB's TTL cleanup must not
erase proof that a credential was already used, otherwise an expired
booking could be reissued after a source persistence delay.

A narrowly defined OCPP 1.6 retry may receive the existing **active**
transaction ID only when the charger ID, token hash, connector and
initial meter are identical. No second source session or invoice is
created. The gateway staging patch copies the helper beside the
staged OCPP handler; it never writes into production.

### 2. Expired, never-used token recovery

`ocppReissuePolicy.mjs` implements a 20-minute authorization TTL plus
a 2-minute settling grace. The new AdminAuth route
`POST /api/v1/admin/bookings/:id/ocpp/reissue-token` reissues a
one-time credential only if:
- the booking is paid, arrived, Upcoming and pinned to an approved charger;
- the previous credential is expired both by booking issue time and
  actual MongoDB token expiration;
- there is **no consumed token**, **no observed OCPP 1.6 or 2.0.1 source**
  using that token hash, and **no linked source session**;
- a conditional booking update can replace the previous hash, stopping
  simultaneous operators from issuing more than one credential.

Old credentials are revoked before a new one is enabled, and uncertain
insert failures cause fail-closed revocation/rollback. The protected
Admin Bookings modal offers a **Reissue expired unused token** action
only when the backend says it is eligible. The new raw credential is
kept in volatile browser state and cleared when the dialog closes.

### 3. Concurrency and uniqueness for source booking links

`AdminBindBookingOcppSource` now uses an atomic conditional update and
a partial MongoDB unique index on `{bookingId,userId}` for source-bound
OCPP charging sessions. The separate source-session ID index also
remains unique, so a physical transaction cannot be assigned to
two bookings. `ocppSourceBindingIndexAudit.mjs` runs a read-only audit
for preexisting duplicate source/session-to-booking relationships
before indexes are created; its index-mutating utility requires
explicit approval and rejects non-disposable databases.

### 4. Charger registration URI correctness

The new station-charger registration route no longer invents
`wss://api.hasone.io/ocpp/...`. It exposes a WSS URL only when
an explicit, validated `OCPP_PUBLIC_WS_BASE` environment setting
is present. Otherwise it returns `url:null` with
`gatewayUrlConfigured:false`; hardware onboarding still requires
actual gateway/charger configuration.

### Verification separation

- **Last completed full staging checkpoint (before latest changes):**
  73/73 Express/JWT/Mongo/OCPP tests, 36/36 pure guard tests,
  13/13 payment callback-route HTTP checks, 8/8 mocked-API
  headless Chrome Admin UI checks, and Next.js production build.
- **New pure policy validations executed during this follow-up:**
  11/11 migration/rollback, 10/10 atomic token policy
  (with deterministic test hash in the local tool runtime),
  10/10 token reissue policy, 9/9 source-binding index preflight,
  totaling 40/40 independent policy tests.
- **Expanded integration harness:** now contains **90** cases,
  **not yet executed** against the newly patched Express/OCPP gateway.
- **Blocked:** `host.crea-biz.com` Desktop Commander was offline
  during this follow-up. GitHub Actions for the latest branch
  were queued. No physical charger, customer, vendor, PSP transaction,
  database migration or production deployment was changed.

Reverify all backend/browser cases, required source hashes and exact
WebSocket gateway endpoint when the authorized machine is available,
then coordinate acceptance with Agent 1 and the canonical Laravel
charging/finance ledger.


## Agent 2 transaction-start recovery and operator diagnostics — Nov 7, 2026

This branch now includes two additional **staging-only** protections:
1. `sameActiveOcpp16Retry` accepts a lost-response retry of the *exact*
   same active OCPP 1.6 source only in a **120-second window** after the
   original server-side `createdAt` timestamp. A stale/replayed tag cannot
   recover an indefinitely active source transaction. The unit tests cover
   119s/120s/120.001s boundaries, missing timestamps and invalid inputs.
2. `ocppStartAudit.mjs` provides an explicitly **read-only** audit of a
   consumed start token without a matching charger-scoped OCPP 1.6 / 2.0.1
   source transaction. Tokens consumed under two minutes ago are excluded
   from incident alerts during the retry grace period. The audit returns
   only non-secret status, booking and charger references; no raw token,
   authorization hash or automatic credential reset.

Authenticated route:
`GET /api/v1/admin/stations/:id/ocpp/start-exceptions`.
The existing Admin Bookings OCPP modal now has a **Check interrupted starts**
action. It lists redacted exceptions and explicitly requires operator review
of charger and database evidence before any new authorization.

Additional regression tests were committed in
`integrations/hasone-express/tests/ocppStartAudit.test.mjs` and the isolated
Express integration runner; the headless Admin UI test also now includes
mocked exception-report handling.

**Verification limitation:** The authorized host.crea-biz.com Desktop Commander
device is offline, and GitHub hosted checks for the latest commits are
queued. These new changes are committed but have not received a fresh
full-stack staging test run. Previously reported integration/browser totals
belong to the older tested revision. Production payment, Mongo and charging
remain unchanged by these changes.
