# Paid-package sponsored campaign management

This financial module reserves bounded **delivery units** from an already-paid vendor subscription invoice. It never opens another payment, creates monetary journals, turns promotional units into stored money or edits station compatibility, OCPP/OCPI capability, availability, tariffs or discovery ranking. Existing subscription plans, checkout, receipts, refunds and immutable journals remain authoritative.

## Commercial workflow and billing

An administrator can publish a normal vendor plan with positive integer entitlements `campaign_slots` and `campaign_units` (maximum1,000,000 each). The vendor purchases/collects that plan using the existing subscription workflow. An active current-cycle invoice with a full canonical receipt, balanced subscription ledger, matching currency/precision, positive price and no credit notes or unresolved payment/refund recovery can back a campaign. Trial/zero-price/unpaid invoices, expired/cancelled subscriptions and invoices without both explicit entitlements cannot grant campaigns. Pending refund requests and executing/uncertain/needs-review payment recovery conservatively stop serving; a known failed refund does not permanently withhold eligibility. Any partial/full package credit conservatively stops serving; no implied refund-to-unit conversion is invented.

The vendor chooses one of their actual stations and a matching station country, paid package invoice, bilingual creative, exact optional region label, placement (`discovery_banner` or `station_detail`), UTC-aware start/end and unit budget. Dates must fit that invoice's paid billing cycle. The account lock serializes concurrent reservations, the canonical subscription row lock serializes entitlement changes, and the station lock protects ownership/country during financial mutations. Invoice terms and receipts are immutable; no inverse invoice/subscription lock order is introduced.

Creating a draft reserves its whole unit budget and one concurrent slot. Draft/submitted/approved/paused/suspended records retain the budget. Rejected/cancelled campaigns release unused units, while already-recorded delivery units remain consumed. Expired records cannot serve; unused reservations return only on explicit cancellation and never migrate into a different billing invoice. There is no automatic next-cycle extension, additional invoice or hidden spend.

Vendor actions: draft → submit; approved → pause; paused → resume; draft/submitted/approved/paused/suspended → cancel. Admin actions: submitted → approve/reject; approved → suspend; suspended → reinstate. Vendors cannot approve themselves or resume a suspended creative. Repeated decisions require the same recorded reason; changed decision retries conflict. Creative, budget, billing anchor and target terms are immutable. To change them, cancel and create another payload-bound request.

Every user action appends immutable campaign and global audit records. New tables protect campaign terms with null-safe PostgreSQL/SQLite triggers, forbid deletion, and make event/delivery history append-only. Non-testing populated downgrade refuses to discard history. Campaign operations do not change the original subscription/cash ledger. Operational status alone is not serving/payment proof.

## Scoped API contracts

Mount `routes/campaigns.php` in existing authenticated/throttled `/api` and `/api/v1` groups. Standard lists use25-item Laravel pagination. Cookie CSRF and Sanctum clients share canonical identities. Vendor sees own campaigns and package invoices; financial Admin sees all. Customer cannot inspect vendor package/campaign administration, but any authenticated consumer can request the strictly labeled eligible-placement feed. Foreign administration IDs return404.

| Endpoint | Contract |
| --- | --- |
| GET `commercial/campaign-packages` | Own vendor/Admin invoice eligibility, billing evidence, exact currency/precision, cycle and snapshotted entitlements. No provider references or credentials. |
| GET `commercial/campaign-stations` | Vendor-only owned station `{id,name,country}` choices; does not grant operational controls. |
| GET `commercial/campaigns` | Scoped campaign page with reserved/delivered units and attributed server clicks. `human_impressions_verified=false`, `additional_cash_charge=false`. |
| POST `commercial/campaigns` | Vendor `{invoice_id,station_id,title,title_ar,description,description_ar,country,region:null|string,placement,budget_units,starts_at,ends_at,idempotency_key}`. Returns201 `{campaign}`. Key is actor-scoped and bound to normalized immutable payload. |
| GET `commercial/campaigns/{id}` | `{campaign,currently_eligible,events,deliveries}` with paginated immutable publisher history. Eligibility rechecks current paid entitlements, station ownership, dates, state and budget. |
| POST `commercial/campaigns/{id}/{submit|approve|reject|pause|resume|cancel|suspend|reinstate}` | Authorized role, `{reason}`5–500characters. Returns `{campaign}`. No implicit approval or extra collection. |
| GET `commercial/campaign-placements?country=AE&placement=discovery_banner[&region=…&page=…]` | `{placements,current_page,last_page,ranking_basis,client_telemetry_billable:false}`; sponsored-ID order, not invented performance rank. Empty filtered pages can precede later pages, so consumers must honor pagination. |

The placement feed exposes only campaign/station IDs and bilingual creative plus `sponsored:true`, `compatibility_verified:false`, `availability_verified:false`, `capability_override:false`. Agent2 must retain all critical station eligibility/compatibility/freshness/source filters and clear sponsored labeling before incorporating this feed into discovery/web/native surfaces. No real placement delivery is claimed merely because a creative appears in this feed or the Admin interface.

Errors:401authentication/HMAC,403role,404foreign/missingresource,409billing/state/quota/keyconflict,422payload/date,429existingthrottle,503disabled/unconfiguredservice. Transport failure must not rotate the campaign creation key or invent a replacement payment. Refresh the same record before another decision. Existing paid subscription checkout/uncertainty contracts are unchanged.

## Trusted publisher contract and honest metrics

Mount `routes/campaign_delivery.php` at the normal `/api` prefix. `POST /api/internal/commercial/campaign-deliveries` accepts a **private trusted backend publisher**, not browser/client assertions. Keep `COMMERCIAL_CAMPAIGNS_DELIVERY_SECRET` server-side (at least32bytes); never send it to Flutter, browser bundles, logs or repository files. `X-Campaign-Timestamp` is a Unix second within300seconds; `X-Campaign-Signature` is lowercase HMAC-SHA256 over `timestamp + '.' + exact raw JSON body`. Verify before parsing into business operations.

Payload: `{campaign_id,event_id,kind:'delivery'|'click',parent_event_id:null|string,country,region:null|string,placement,occurred_at}`. Occurrence must be within the campaign, after its creation, withinfive minutes past/one minute future. A click requires an earlier verified server delivery for the same campaign and can be attributed only once per delivery. One globally unique event ID binds the normalized payload; replay is idempotent, changed identity conflicts. Concurrency cannot spend the final unit twice. Disabled, cancelled, suspended, refunded, expired, foreign-owned or exhausted delivery contexts fail closed. Click attribution may reference the last delivered unit even after its unit budget is exhausted, while all other eligibility controls still apply.

A signed event proves that the configured publisher attested a **server delivery or redirect interaction**. It does not prove a person saw an ad, clicked voluntarily, charged a car or generated revenue. Client-reported telemetry is not accepted as billable proof. No human-impression/CTR/return-on-ad-spend values or bank movements are fabricated. Publisher deployment, integration into Agent2's placement surfaces, actual partner attribution and consumer/privacy/advertising terms remain unverified external/integration acceptance. The software does not claim a live advertising network.

`COMMERCIAL_CAMPAIGNS_ENABLED=false` by default. `scripts/campaign-test-setup` enables only local/testing with the mock payment provider, creates/retains a private random local signing key without printing it and clears config cache. It rejects production/nonmock configuration. Use normal operator-reviewed configuration for an authorized deployment; no secret or fake live partner is seeded by the application.

## Tests and remaining software

`CommercialCampaignTest`: paid eligibility, canonical cash preservation, role/tenant isolation, quotas, retained creation identity, immutable records, admin review/suspend/reinstate, refund/cancellation/ownership/date holds, HMAC, signature expiry, click parent attribution and exhausted budgets. Actual disposable PostgreSQL independently runs feature cases and four process races: same-key reservation, distinct-key quota competition, final-unit competition and same-event replay.

`tests/commercial-campaigns.cjs` exercises actual Laravel and browser management plus an actual HMAC POST generated by a strict local-only CLI fixture. This synthetic publisher is explicitly not production ad delivery. The connected check also verifies an unsigned client is denied, sponsored flags preserve capability truth, moderation blocks serving, literal creative text and responsive EN/AR Light/Dark controls. Screenshots are test artifacts.

Remaining commercial software is separate: standalone cash-priced campaign orders/PSP collection beyond the existing paid-plan anchor, legally reviewed advertising tax/pricing/cancellation/refund policies, external ad/retail partner fulfillment, verified live publisher deployment, privacy/anti-bot measurement, delivery fairness/auction policies, coupons/discounts/referrals, and Agent2 discovery/native placement integration. These are not silently counted as complete by this campaign slice. No whole commercial-growth or production-acceptance claim is made.
