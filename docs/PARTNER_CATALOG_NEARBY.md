# Agent 8 — partner catalog nearby search and source-age evidence

This change extends the **existing** public `GET /api/roaming/locations` endpoint. It does not create a second station inventory, OCPI protocol implementation, command path or finance source of truth.

## API contract

The original optional filters remain: `q` (80-character max), `country` (ISO 3166-1 alpha-3 uppercase), and `connector_standard` (64-character max). The endpoint additionally accepts a complete set of optional `latitude`, `longitude` and `radius_km` parameters (valid ranges: [-90,90], [-180,180], [1,200]). A partial or invalid set returns 422. Queries remain bounded; the great-circle distance is **not** driving distance.

When proximity is supplied, results are filtered to the requested radius and sorted by exact great-circle distance, then descending record ID. Each result has a rounded `distance_km` and `distance_basis=great_circle`. Without proximity, the former newest-first ordering and response fields remain available. Pagination retains validated filters. The public projection adds `source_updated_at`, `reported_source_age_minutes` and `source_timestamp_evidence=PARTNER_REPORTED_ONLY`. No freshness SLA or real-time availability is implied by an age value.

Only `locations` records from currently **authorized** OCPI providers with normalized `publish=true` are eligible. Raw encrypted source payloads, content hashes, connection keys, private tariffs and user favorites remain withheld. Every public record retains `evidence=PARTNER_REPORTED_UNVERIFIED`, `availability=unknown`, `remote_control=false`, `reservable=false`.

The web form exposes manual grouped coordinates and a radius, English/Arabic labels, straight-line distance and partner record age. It does not request device location permission or store user coordinates as a preference. Mobile partner-nearby UI parity remains an outstanding integration with Agent 6.

## Shared interactive map overlay

The existing self-hosted Leaflet station map also presents **only the loaded, authorized, published partner result page** as visually distinct, accessible unverified partner markers. It reuses the existing opt-in tile consent. No tile request occurs merely by loading the catalog. It does **not** claim a complete viewport catalog, online charger status, a partner booking endpoint or permission to send remote commands.

A partner marker popup uses literal text (never HTML insertion) and explicitly states partner-reported/unverified availability. A directions link opens Google Maps only after the user's separate confirmation. There is no "Add charging stop", charge control or booking action on partner markers. Both the roaming catalog and the station-map clear on refresh, provider revocation and identity/session reset; in-flight previous-context public requests are aborted. Unsafe future response capability flags are rejected instead of displayed.

The **Disable interactive map** control tears down the Leaflet instance and hides the map, releases future tile activity until another explicit Enable, and clears one-time geolocation consent/invalidates outstanding browser location callbacks. A network request for a tile that was already dispatched can still complete. Search results remain in the text lists and manual coordinates remain available. Re-enabling builds a fresh map. The **Show loaded locations on map** control fits only the currently loaded first-party and approved partner results; it does not assert that every nearby charger was fetched or offer verified driving routes. These controls and their EN/AR labels are covered by the map browser regression.

The map and original first-party catalog remain separate authoritative sources: server-side OCPI authorization and `publish` flags govern partner presence; Leaflet is presentation only. Colors and accessible marker titles distinguish unverified partners from managed/simulator stations.

## Implementation and verification

- `PartnerCatalogGeo` uses parameter-bound SQL against normalized JSON coordinates on supported SQLite and PostgreSQL connections, with date-line wrap and polar cap prefilters.
- `SqliteGeoMath` registers deterministic mathematical functions on SQLite PDO connections when the host lacks built-in SQLite math functions, covering both partner and existing canonical station proximity search.
- Laravel, isolated synthetic test database and no operator credentials: `php artisan test --filter='PartnerLocationDiscoveryTest|CatalogGeoTest' --compact` **12 passed / 72 assertions**.
- Laravel Pint targeted affected PHP files: **6 files passed**. PHP and JS syntax checks: passed.
- Playwright synthetic browser tests against isolated loopback Laravel: **passed** for partner-nearby filters and station + partner map overlay, including grouped validation, exact query parameters, Arabic RTL, map/tile consent, accessible separate source pins, safe literal popups, external navigation consent, malicious capability fail-closed, provider removal, identity-clear map reset and no charging controls. Existing nearby-catalog, map assets, localization, discovery-routing, frontend and web-session browser suites also **passed** after scoping legacy radius test selectors to the station form. The existing CI `npm run test:maps` command now runs both the mixed-map and partner-nearby Playwright scenarios, preventing the new feature test from being omitted by the established platform workflow.
- Full Laravel suite on the same local PHP 8.4 environment: **557 passed, 1 failed** (10,426 assertions). Failure is reproducible with the unchanged `SettlementTest` finance migration: SQLite `DROP TRIGGER commercial_adjustments_no_update` has no matching trigger; focused SettlementTest: 9 passed, 1 failed (93 assertions). This PR does not modify the settlement migration or finance code. The failure was independently **reproduced on the unmodified integration base** `d2568039d597e9e274be38a4b915bcf70a4d7c78` under the same isolated PHP 8.4/SQLite environment: `SettlementTest` **9 passed, 1 failed / 93 assertions**, identical missing-trigger error. Thus this is a confirmed existing baseline blocker rather than an Agent 8 introduced failure. **Agent 1/4 must resolve or formally account for it before merge**.
- Final exact-head hosted CI, actual PostgreSQL runtime, licensed partner network data, mobile parity, deployment and production acceptance are **not verified**.

## Integration handoff

Agent 4 owns PR review/merge; maintain draft while required CI and regression gates are pending. Agent 1 owns OCPI protocol and finance. Agent 6 owns shared Flutter/mobile infrastructure. Avoid duplicate public catalog and any inferred charging authority. External partner APIs and physical chargers require separate authorization and evidence.
