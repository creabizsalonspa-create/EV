# Dynamic sponsored campaign placements — eligible-first pagination

**Agent 3, original 3.7.7 and 4.4.5: advertising/marketing software candidate (partial).**

## Concrete defect

The existing authenticated discovery and station-detail
`GET /api/commercial/campaign-placements` query used SQL
`paginate(25)` on rows whose status was `approved`, then
removed rows failing `CampaignService::eligible()` from the selected
page. This produced a falsely empty/underfilled first page, a
`last_page` that included disqualified campaigns, and lost
currently entitled placements hidden on later candidate pages.

Campaign approval is **not** irrevocable permission to deliver ads.
Package cancellation, credits, unresolved PSP collection, delivery
budget exhaustion, station transfer or account changes can invalidate
a campaign without changing its immutable approval audit history.

## Implemented

1. Apply immutable source filters first (country, catalog station IDs,
   placement, region, time window, approved status).
2. Use joined, correlated station ownership/country identity and the
   trusted server-delivery counter to eliminate obviously invalid
   candidates before more expensive entitlement assessment.
3. For each remaining candidate, use the existing authoritative
   `CampaignService::eligible()` to enforce live fully paid package,
   unsettled-refund, active entitlements and actual delivery budget.
   There is **no** copied payout calculation, independent money ledger,
   client impression billing, new click path or auto delivery event.
4. Paginate the **eligible results**, not unfiltered candidates, in
   deterministic ascending campaign ID order. Return accurate
   `current_page`, `last_page`, `per_page` (default 25, max 25),
   `total` (eligible only) and source-only sponsored flags. The
   existing response keys remain compatible with discovery clients.
5. Return an explicit HTTP 422 with a message to narrow the station
   catalog query if more than 1000 prefiltered active candidates exist,
   rather than silently reporting incorrect totals, unboundedly
   querying invoice eligibility or delivering previously invalidated
   ads. A single selected discovery page supports up to 25 station
   IDs. There is no partial/untruthful result.
6. Retain `Cache-Control: private, no-store` and unchanged
   non-billable client telemetry and lack of physical compatibility/
   availability verification.

## Verification

Added real Laravel feature tests with paid vendor packages, real
approved campaigns, actual synthetic subscription credit refund and
two active campaigns. Verify eligible placement 1 starts with the
actual first valid campaign despite an earlier still-approved but
ineligible row; page 2 contains the other, page 3 is empty,
and `total/last_page` refer only to eligible placements. Also verify
catalog `station_ids` is filtered **before** eligible pagination,
invalid page-size rejection and unchanged canonical finance journals,
subscription ledger and signed delivery count after all lookups. A second
real-source regression signs and records a delivery that fully consumes the
first vendor's approved campaign budget, then proves page 1 shows only the
other vendor's still-entitled sponsored station, with no additional
signed delivery or package-spend mutations.

**No production sponsor impressions, billing-provider collection, ad
content compliance review, or feature completion is claimed.** Exact
Laravel/PostgreSQL, browser/native, integration CI and Agent 5 merge
remain mandatory; Agent 4 should verify the discovery placements UI.


A third integration-style Laravel test creates **26 actual authorized vendor
campaigns in one paid package** through the canonical
`CampaignService::create` / `action` workflow (subject to 30 paid
campaign slots and 50 prepaid units). It records one **HMAC-signed real
server delivery** against the oldest approved campaign, exhausting its
one-unit budget, and then verifies the default `per_page=25`
discovery response contains exactly the *other 25 still eligible
campaigns* in ascending ID order, with `last_page=1` and
`total=25`, without spending further units or modifying finance
journals. This is specifically the old post-pagination hole across a
complete default-size page; no test-only SQL alteration to protected
source campaigns is necessary.


## Disabled adapter behavior

When `campaigns.enabled=false`, an authenticated placement request
returns an honest empty report (`total=0`, `last_page=1`,
`per_page` equal to the requested validated size), even when previously
approved campaigns and paid packages remain in immutable source tables.
The disabled response does not query invoice entitlement, register
server deliveries, or override station discovery availability. A
feature test confirms no sponsored placement or financial journal
changes occur while the adapter is disabled.


## Revoked vendor business authority (Agent 3 follow-up)

A previously **approved, paid** campaign must not remain deliverable when
its **owning vendor account is suspended or demoted**. The canonical
`CampaignService::billing()` eligibility check now requires both
`role=vendor` and persisted `account_status=active`; the very same
source-of-truth check is used for catalog placement eligibility and
HMAC-signed delivery ingestion. Admin approval history and prepaid
invoice/receipt remain immutable and are not recreated or edited.

`CommercialCampaignTest` now exercises two independently paid vendors.
A suspended/demoted vendor disappears from eligible-first
`total/last_page` while another vendor's eligible placement remains;
fresh signed delivery is rejected, no ledger/finance journal entry or
delivery unit is created, and reinstating the original active vendor
restores the existing approved campaign. One valid signed delivery
is then recorded, and identical replay returns the original receipt.
The test is a runnable source candidate only; exact-head CI and
Agent 5 integration are still required. No real ad impression, PSP
payment, account moderation approval, or production acceptance is
inferred.
