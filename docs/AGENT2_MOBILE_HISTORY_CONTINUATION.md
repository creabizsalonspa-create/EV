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
