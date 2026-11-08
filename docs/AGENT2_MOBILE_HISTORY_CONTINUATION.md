# Agent 2 mobile source-history checkpoint

## Current connector-fault draft checkpoint — November 8, 2026

This section supersedes older environment/next-task notes below. The fault batch
is implemented but only partially verified; both PR50 and PR70 must stay draft.
Read docs/evidence/agent2-mobile-connector-faults/validation.json for exact source blobs and check limits.

Active authorized sources can expose a read-only connector report only when its
protocol/EVSE/connector match and its server receivedAt follows the source server
startedReceivedAt receipt. Legacy/device timestamps cannot substitute for that
anchor. Admin and customer mobile show bounded status, receipt age/staleness and
fault review guidance without claiming current availability or energy flow.

API contract: sourceVerified and source binding can succeed before the first
meter reading; meterVerified stays false and energy/cost stay null. Existing
owner, payment/admission, token/hash, resource and uniqueness guards remain.
Finalization/summary still require verified meters and calculated unpaid totals.
Fault reports never stop/restart/finalize/invoice or collect payment. Private
session responses are no-store and vary by Authorization.

Final local checks:134 Node guards,6 synthetic gateway patch-contract tests,
changed JS/Python/shell syntax and whitespace checks pass. Earlier candidate
backend146/browser147, Next build and Flutter61/4-skips/analysis passes are NOT
final-source verification: accepted-start timestamp refinement was not rerun and
host-formatted Dart was not retrieved before the device disconnected. Earlier
charger-choice checks remain evidence for commits1ebae3e/4955037 only.

Exact blocker:Desktop Commander host.crea-biz.com is offline (last seen5h ago).
No local Flutter/Dart SDK or proprietary backend source/dependency fixture.
Next executable task:reconnect the authorized host, upload committed blobs,
stage fresh disposable Express/OCPP/Next copies, rerun complete validation with
browser modes, final Flutter formatting/tests/analysis, actual API/native fault
and missing-meter cases, then the latest PR78 combination. Confirm exact blobs
before updating final-source evidence. Agent4 alone reviews/merges #50/#52/#70
with shared mobile infrastructure and required hosted/native release gates.

Independent connector inventory/capacity/expiry, paid reschedule, concurrent
calendar-edit migration and canonical authority agreement remain unfinished.
No full atom credit, coverage increase, merge or production acceptance claimed.

Current calendar follow-up preserves full year/month/day in booking creation,
slot queries and rescheduling, while keeping display labels separate. Invalid
calendar days are rejected locally; pending/uncertain creation never auto-replays
or invents a receipt, and backend calendar denials are explained to the customer.
Native39 tests pass with2 opt-in API skips; both actual API tests pass separately,
covering scoped history plus persisted booking creation/duplicate denial. Changed
files analyze cleanly. Evidence:
`docs/evidence/agent2-mobile-reservation-calendar/validation.json`.
Paired backend/mobile release and ambiguous legacy-date review are required.
Independent resource capacity, calendar-time-zone expiry and paid rescheduling
remain incomplete. Do not deploy the new date client against the old backend.

Previous resource-scope follow-up consumes PR50 at d4165dd. Customer history shows
verified OCPP1.6 connector identity or OCPP2.0.1 EVSE/connector identity in EN/AR.
Malformed verified scope claims fail closed; legacy unscoped rows never invent a
connector from raw metadata. Native35 tests pass; the separately executed real
private API test checks both protocols and the combined backend/native harness
passes130 checks. The four changed/new native source/test files analyze cleanly.
Evidence: docs/evidence/agent2-mobile-resource-scope/validation.json. Independent
parallel reservation capacity remains unfinished; scope is identity, not proof
of charger availability or commissioned hardware.

Previous native follow-up explains booking-mutation denials and unknown outcomes,
refreshes authoritative bookings after every write result, and never resends a
write automatically. A successful HTTP response without an application success
receipt does not confirm cancellation/rescheduling. Pending mutation screens
ignore receipts after disposal. Controller dependency injection supports actual
failure/unknown-outcome regression tests. The full customer suite now passes 31
tests with one opt-in actual API skip; the API test passes separately. All four
changed/new files analyze cleanly. See
`docs/evidence/agent2-mobile-booking-mutations/validation.json`. Backend safety
counterpart: PR50 at 834bb41; no new production claim.

Standing ten-agent ownership applies. Agent 2 owns PR50, Express/Mongo
compatibility and associated customer/Admin charging features. Agent 4 owns
integration and coverage. This branch is stacked on PR52's purchased customer
source-status changes; it consumes PR50's private history contract. Preserve
both parents. Do not merge this client without the staged API dependency.

Implemented: Bookings navigation to private read-only source history, immutable
25-row cursor pages, bounded/strict wire parsing, same-cursor retry, redacted-row
continuation, independently qualified meters and exact calculated unpaid fils,
disabled-charger history, session details, EN/AR and RTL. Backgrounding removes
private records and invalidates pending reads; resuming reloads authority.
Account changes, refresh and disposal discard stale replies. There is no second
charging/billing ledger, authorization token receipt or native charging control.

Evidence: `docs/evidence/agent2-mobile-source-history/validation.json` records
tested source blobs. Flutter 3.35.7: 27 customer tests pass, one opt-in actual API
test skips in the default suite and passes separately against disposable actual
Express/Mongo/OCPP. Expanded backend harness: 110 checks pass. New Dart files and
the edited booking screen analyze cleanly. Wrapper compiles with Python stdlib.
Required exact-source hosted checks and Android/iOS builds remain pending.

Actual API reproduction requires the PR50 harness and staged copies, existing
Express dependencies, Flutter dependencies and local mongod. The wrapper rejects
deployed source paths, adds an ephemeral local-only Flutter acceptance step to
a temporary copy of the harness, propagates only synthetic fixture tokens to
the child environment, then removes the temporary harness. It never starts a
public listener, writes production data, or operates hardware/payment providers.

## Explicit customer charger choice checkpoint

The protected reservation-chargers API exposes only approved physical identity
and protocol, with bounded/redacted output and explicit unknown hardware
availability/station-wide capacity. Customers choose a charger in the station
screen and send reservedChargerId; the receipt must match before navigation.
A multi-charger station cannot silently select its first charger. Unknown
protocol records require explicit operator correction, never metadata guessing.

The customer choice is stored separately from the later authorization pin.
Admin issuance/reissue, source binding and customer status/history enforce the
selected physical identity. Unused unpaid cancel/reschedule preserve it; a
disabled selected charger cannot be substituted during reschedule. Eight
concurrent choices for different chargers still produce exactly one station slot.
Selected-charger receipts do not invent connector specifications or metered energy.

Verified:130 Node guards,13 callback checks,143 backend/OCPP;13 mocked Chrome,
12 actual-API Chrome; Next TypeScript/production build. Backend plus browser or
actual native reports144. Flutter58 pass/4 default opt-in skips; seven actual
API/native checks pass separately. Six changed native files analyze cleanly;
the existing mixed-case booking model filename has one inherited naming info.
No errors/warnings or analysis suppression added.

Latest isolated PR78 combination at961bba2 passes77 tests/4 explicit skips and
targeted analysis clean. All113 source/config blobs match the tested combination.
Combined app tree346c2c09f1f25bb8a374109f582ea4e68089fa26. No branch merge.
The test runner now keeps disposable Mongo data on its staged filesystem,
preserving Mongo's free-space checks and avoiding a small system temp mount.

Evidence is in the companion charger-choice validation.json. Paired backend
and mobile release plus approved charger protocol metadata are required.
Next: ongoing source-qualified connector fault presentation and canonical
independent-resource capacity/connector selection/expiry/paid reschedule.
Full original atom credit and production acceptance remain unclaimed.
Required hosted/native gates and Agent4 integration remain outstanding.

## Source-ended interruption review checkpoint

Customer charging status now says Ended at source rather than implying charging
succeeded. Bounded reported reasons such as power loss, emergency stop and reset
are separated from final unpaid calculation. Unknown/malformed ended reasons
require operator review; active records cannot claim an ended incident.
The protected Admin booking workflow shows reported end reason and review need.
No automatic restart/stop/payment, fault diagnosis or financial collection is added.

Exact runtime/test verification:127 Node,13 callback,135 backend/OCPP,
13 mocked Chrome,12 actual-API Chrome; Next TypeScript/production build pass.
Backend plus browser or native reports136. Native51 pass/3 default opt-in skips;
six actual API/native checks pass separately across both protocol active/ended
states and history/booking. Six changed native files analyze cleanly.

Isolated combination with Agent6 PR78 at20adfedf passes67 Flutter tests with3
explicit skips and targeted analysis clean. All110 app Dart/config blobs match
the intended combined source. Combined app tree:
014c51a40ab45a57d48c77c48ff7b2285b0fdb61.
This is compatibility evidence, not a branch merge or Android/iOS build.

Evidence: companion source-end-review validation.json includes source blob
manifest and restricted log digests. Full2.7.9 credit remains unclaimed.
Next: ongoing fault-source incident presentation and canonical-compatible
resource reservation/customer selection/expiry/paid reschedule integration.
Required hosted/native gates and Agent4 integration remain outstanding.

## Interrupted source-read recovery checkpoint

Status reads have a15-second deadline and explicit read-only retry messaging
in English/Arabic. Resume invalidates old in-flight requests and starts a fresh
read without waiting for the abandoned reply. Request generations fence timeout,
background, route reuse, disposal and account-change races. A different session
or booking cannot populate this screen. Failed reads remove prior telemetry;
they never imply charging stopped or replay start/stop/payment commands.

Six deterministic widget regressions and the full customer suite pass:
49 tests,3 default opt-in API skips; four changed files analyze cleanly.
Exact-source hashes/logs are in
docs/evidence/agent2-mobile-read-recovery/validation.json.
Backend/API/browser evidence retains its prior246510d/20ba054 scope.

Next: verify compatibility with Agent6 token infrastructure PR78, then complete
remaining charging incident/recovery and canonical reservation workflows.
Required native/hosted gates and Agent4 integration remain pending.

## Accepted-meter active-progress checkpoint

OCPP1.6 and2.0.1 accepted-meter receipts now record server receive time.
Missing, coerced, regressing or oversized values cannot refresh that receipt.
Customer private status exposes verified provisional unpaid amounts separately
from final calculated amounts. Old records without accepted-meter metadata remain
unknown; generic source updates never stand in for meter receipt freshness.
The purchased mobile charging screen formats exact minor-unit AED amounts and
labels collection status and meter age at last check, clears on background/error
and retains disabled uncommissioned controls and unavailable SOC/ETA.

Exact-source validation:125 Node guards,13 callback checks,135 backend/OCPP,
13 mocked Chrome,11 actual-API Chrome, Next TypeScript/production build passed.
Backend plus actual browser or actual native reports136 checks. Flutter43 pass
with3 default opt-in skips; the actual API harness separately passes all4 native
checks (active progress for each protocol, private history and booking duplicate
denial). Four changed native files analyze cleanly. Test source blobs and log
hashes are recorded in the companion active-progress evidence.

Next: continue full real-source incident/recovery UX and canonical-compatible
resource reservations/expiry. Complete original2.7.9 credit is not claimed.
PR50/52/70 required hosted/native gates and Agent4 integration remain outstanding.

## Remaining assigned requirements and next task

- Agent 4 integration of PR50, PR52 and this history consumer after hosted gates.
- Charger/connector-specific Express reservations, concurrent independent
  resources, expiry/reschedule and source-to-selected-connector consistency.
  Canonical Laravel already has connector/EVSE capacity rules; consume that
  contract and preserve legacy station-slot uniqueness until an explicit
  audited compatibility migration is implemented. Never drop live indexes.
- Complete source incident/live-cost and commissioned acceptance scope; agree
  the Express-to-Laravel/CitrineOS authority with Agent 1/4 before activating.
- Actual device/native builds, authorized hardware/PSP/partner and operational
  acceptance remain separate. This history subset does not complete requirement
  2.7.9 or earn a whole-product coverage increase.

Next executable action: resume PR50 from its latest GitHub head, compare staged
CreateBooking/ReBooking, charger mappings and source connector identity against
the canonical connector reservation contract, then implement one compatible
resource-reservation batch with concurrency and legacy-data migration checks.
This assignment remains incomplete until all assigned scope is verified and
Agent 4 integrates it. CONTINUE resumes that exact checkpoint.
