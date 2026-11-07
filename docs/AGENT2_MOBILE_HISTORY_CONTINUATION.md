# Agent 2 mobile source-history checkpoint

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
