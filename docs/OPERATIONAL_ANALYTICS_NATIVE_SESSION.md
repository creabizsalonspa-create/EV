# Agent 3 — Native operational analytics source/session acceptance

**Original requirement slices:** `3.6.3` (uptime/downtime analysis) and
`3.6.4` (energy/demand analysis), still **uncredited** until full verified
integrated cross-platform and approved source evidence is available.

The existing Laravel `OperationalAnalyticsController` and canonical source
`OperationalAnalytics` service continue to define the complete report.
No duplicated meter, EVSE, settlement or ledger calculations are introduced.

The bilingual Vendor Flutter `OperationalAnalyticsView` now:

- Captures the actual authenticated API session generation and scopes all
  pending async JSON and PDF operations to that generation. A logout,
  identity replacement, app-background transition, or change to the company
  cancels the private result. The old request cannot restore cached money,
  telemetry or PDF viewing after invalidation.
- Clears prior values **immediately** when UTC date/site filters are edited;
  only applied filters may be used to open the existing authenticated PDF.
- Enforces the response's exact requested UTC bounds, and exact requested
  site ID where specified. The source must declare 300-second status freshness,
  a bounded 31-day observation window and the precise accepted-session-
  interval-average demand scope; simulations never become claims of verified
  physical uptime, site grid peak or cash settlement.
- Verifies each site's distinct identity and exact 2^53−1-safe nonnegative
  metric precision. Reported status-known plus status-unknown must equal the
  possible connector observation duration; fault time cannot exceed known
  coverage; observed busy time cannot exceed the EVSE window; observed demand
  duration cannot exceed the report window. Unknown site demand stays
  explicitly **unknown**, not zero grid power.
- Preserves the current Admin/vendor/company/staff API authorization. This is
  a read-only native source contract and does not authorize hardware commands,
  wallet balances, PSP money operations, maintenance, or production reporting.

The existing `operational_analytics_test.dart` gains tampered-scope,
impossible-status-coverage, filter-edit erasure, revoked credential-generation
and late-response tests. The existing real Laravel native source test remains
in place. Exact-head Flutter widget/analyze/Android/iOS and Laravel/CI acceptance
is required prior to Agent 5 integration. The current repository environment
cannot assert physical charger uptime or site power without approved hardware
and source observations.


### Connector-coverage denominator displayed separately

Operational status evidence now presents **mapped connector count** and
**current mapped-connector window seconds** independently from mapped
EVSE count and EVSE busy/window seconds, in English and Arabic. This
prevents treating unknown connector status as physical uptime and makes
`known + unknown = possible connector exposure` visually auditable.
The native parser independently checks this invariant and rejects
fault exposure above known source coverage, impossible busy periods or
client-visible claims of verified physical availability. A widget
regression tests the distinct source denominator. No real hardware
verification is claimed.


### Immediate native logout erasure — actual macOS CI regression fix

Native macOS validation for preceding head
`4c14db37bfadb03adc8bc06b8f0afa9bbc33df11`
failed the case *revoked API generation cannot expose cached native finance
or energy*: changing `EvApi` auth generation correctly invalidated its
session fence, but the visible report still had its old PDF action
until an unrelated rebuild. This was a real confidentiality bug,
not an incorrect test expectation.

The shared native `EvApi` now publishes a synchronous token-free
broadcast `sessionEvents` stream whenever an authenticated
generation is invalidated by `forgetSession` or `close`. Only a
non-secret integer generation is transmitted, never credentials,
person identifiers or invoice details. Existing HTTP/logout behavior
is unchanged. `OperationalAnalyticsView` listens for current API
invalidation to immediately erase its old finance/energy sources,
PDF affordance and spinner; listeners are removed when the API
changes or the view disposes. Pending older HTTP responses cannot
restore the report because the view generation increments.

An additional API unit test verifies exactly one synchronous
invalidation on logout and close and immediate invalidation of the
old `sessionFence`. The original failing widget test remains
unchanged and must pass on the latest native macOS/Ubuntu CI. No
hardware uptime/demand verification or production revenue claim.


### Already-open operational uptime/demand private PDF route

An already-pushed private PDF must not continue rendering after logout
when its parent financial report clears. This branch now carries the
**byte-identical optional** `VendorDocumentPreview.revokedSessionEvents`
implementation from Agent 3 PR #272. The native report passes the
current `EvApi.sessionEvents` to its viewer. A token-free account
invalidation immediately hides the PDF/image renderer, evicts any
preview image cache and closes the route on the next frame. The
shared component's already-open-route widget regression lives in
PR #272; Agent 5 should deduplicate this identical implementation
once during integration. Existing private report amount/source
tests and full Native/Platform CI must pass on this branch's exact
HEAD. This does not certify bank, PSP, grid, charger or roaming data.
