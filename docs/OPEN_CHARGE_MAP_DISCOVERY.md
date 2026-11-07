# Open Charge Map — licensed discovery-only adapter

Owner: Agent 8. This does **not** provision chargers, authenticate an OCPI roaming partner, reserve connectors, control hardware or authorize charges/payments. The source is disabled by default. It is separate from managed stations and the approved OCPI partner catalog.

## Before any live enablement

1. Review the current [OCM API documentation](https://www.openchargemap.org/develop/api) and [data/attribution terms](https://www.openchargemap.org/about/terms), including country/source redistribution and applicable provider-specific licenses. The API may include third-party data subject to different licensing. This adapter explicitly requests `opendata=true` and discards any record missing `DataProvider.IsOpenDataLicensed=true` or a nonempty provider `License`.
2. Obtain and independently secure an OCM API key via an approved application; use the **server environment only**. Set `OCM_DISCOVERY_API_KEY` (minimum 12 characters), `OCM_DISCOVERY_TERMS_ACCEPTED=true` only **after legal/attribution approval**, and `OCM_DISCOVERY_ENABLED=true` when launch/operator policy authorizes it. All three conditions are required. The public capability endpoint returns only a boolean, never the key.
3. Configure privacy notice, API quota/billing policies, upstream monitoring and data-provider attribution. OCM supplies no live SLA. Do not claim a current available plug from an OCM status/update report; its data is discovery-only and may be incomplete or outdated. Any bilateral roaming, OCPP control or payments require independent provider authorization and canonical source binding.

## Endpoint contract

- Browser: `GET /api/discovery/open-charge-map/capabilities`, `POST /api/discovery/open-charge-map/search`.
- Native: `GET /api/v1/discovery/open-charge-map/capabilities`, `POST /api/v1/discovery/open-charge-map/search`.
- A request must include `consent=true`, `latitude` [-90, 90], `longitude` [-180, 180], and `radius_km` [1, 200]. The precise position is posted only after an explicit user action. The web panel remains hidden while the source is disabled. Neither account login nor automatic GPS prompts enable it.
- Outbound calls use a fixed HTTPS origin `https://api.openchargemap.io/v3/poi/`, an API key **header** (not a URL query), an identifying User-Agent, 3-second connect/8-second total limits, no redirects and a 2 MiB response cap. The server requests up to 25 results, open-licensed records only, and no comments/media. This endpoint uses the project's existing `throttle:discovery-lookups` budget and caches a source-only result for five minutes under a hash of the search location and credential identity, not raw coordinates in a cache key. Public responses use `Cache-Control: private, no-store`.
- Exact great-circle distance is recalculated server-side, duplicates/out-of-radius records are removed, and results are nearest-first. The result count is a **bounded subset**, not an exhaustive map of all nearby stations or driving distance.
- After an opt-in licensed OCM search, validated crowd reports can be mapped as a **third separate pin layer** with a distinctive unverified badge, lower stacking priority than canonical and OCPI partner sources, safe literal-text popup, the original source license, and the official fixed OCM URL. The pin toggle is purely local: it does not call a provider or acquire GPS. Starting a new query, provider errors, or identity changes remove old OCM pins. No crowd pin ever offers charging, reservation or payment actions.
- Each item includes `source_status_evidence=OPEN_CHARGE_MAP_DISCOVERY_ONLY`, `availability=unknown`, and `remote_control=reservable=billing_eligible=false`. Reported connector types and power, operator name, and source-time fields are labelled unverified. Future/missing source clocks never become proof of freshness. Only an explicit allowlist reaches the API; arbitrary raw POI fields, personal details, credentials, comments and media do not.
- Every public result carries its Open Charge Map source link, the original data provider name and **provider-specific license**. The web view displays them using literal text and a fixed official source URL. Only up to 25 records are shown. No OCM result is placed in the canonical charger/control/financial ledger.

## Verification and remaining gates

Standalone no-provider tests: `php tests/open-charge-map-projection.php`, `php tests/open-charge-map-adapter.php`, and `node tests/open-charge-map-ui.cjs`. The Laravel HTTP fake contract lives at `services/business-api/tests/Feature/OpenChargeMapDiscoveryTest.php`. It must pass on the integrated Laravel exact head before merging; hosted CI, real provider/credential/license approval, live source availability, PostgreSQL and mobile device acceptance are independent unresolved gates.

Current availability is **disabled/unverified**: source code and synthetic offline tests do not establish that an authorized OCM provider key exists, that the upstream API has been called or that any live charger is actionable.
