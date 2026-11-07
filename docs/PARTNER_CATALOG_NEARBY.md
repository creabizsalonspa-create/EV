# Agent 8 — partner catalog nearby search and source-age evidence

This change extends the **existing** public `GET /api/roaming/locations` endpoint. It does not create a second station inventory, OCPI protocol implementation, command path or finance source of truth.

## API contract

The original optional filters remain: `q` (80-character max), `country` (ISO 3166-1 alpha-3 uppercase), and `connector_standard` (64-character max). The endpoint additionally accepts a complete set of optional `latitude`, `longitude` and `radius_km` parameters (valid ranges: [-90,90], [-180,180], [1,200]). A partial or invalid set returns 422. Queries remain bounded; the great-circle distance is **not** driving distance.

When proximity is supplied, results are filtered to the requested radius and sorted by exact great-circle distance, then descending record ID. Each result has a rounded `distance_km` and `distance_basis=great_circle`. Without proximity, the former newest-first ordering and response fields remain available. Pagination retains validated filters. The public projection adds `source_updated_at`, `reported_source_age_minutes` and `source_timestamp_evidence=PARTNER_REPORTED_ONLY`. No freshness SLA or real-time availability is implied by an age value.

Only `locations` records from currently **authorized** OCPI providers with normalized `publish=true` are eligible. Raw encrypted source payloads, content hashes, connection keys, private tariffs and user favorites remain withheld. Every public record retains `evidence=PARTNER_REPORTED_UNVERIFIED`, `availability=unknown`, `remote_control=false`, `reservable=false`.

The web form exposes manual grouped coordinates and a radius, English/Arabic labels, straight-line distance and partner record age. It does not request device location permission or store user coordinates as a preference. Mobile partner-nearby UI parity remains an outstanding integration with Agent 6.

## Implementation and verification

- `PartnerCatalogGeo` uses parameter-bound SQL against normalized JSON coordinates on supported SQLite and PostgreSQL connections, with date-line wrap and polar cap prefilters.
- `SqliteGeoMath` registers deterministic mathematical functions on SQLite PDO connections when the host lacks built-in SQLite math functions, covering both partner and existing canonical station proximity search.
- Laravel, isolated synthetic test database and no operator credentials: `php artisan test --filter='PartnerLocationDiscoveryTest|CatalogGeoTest' --compact` **12 passed / 72 assertions**.
- Laravel Pint targeted affected PHP files: **6 files passed**. PHP and JS syntax checks: passed.
- Playwright synthetic browser test against an isolated loopback Laravel server: **passed**, including grouped validation, exact query parameters, translated Arabic RTL source/distance labels and no charger-control actions.
- Full Laravel suite on the same local PHP 8.4 environment: **557 passed, 1 failed** (10,426 assertions). Failure is reproducible with the unchanged `SettlementTest` finance migration: SQLite `DROP TRIGGER commercial_adjustments_no_update` has no matching trigger; focused SettlementTest: 9 passed, 1 failed (93 assertions). This PR does not modify the settlement migration or finance code. **Agent 1/4 must resolve or establish baseline before merge**.
- Final exact-head hosted CI, actual PostgreSQL runtime, licensed partner network data, mobile parity, deployment and production acceptance are **not verified**.

## Integration handoff

Agent 4 owns PR review/merge; maintain draft while required CI and regression gates are pending. Agent 1 owns OCPI protocol and finance. Agent 6 owns shared Flutter/mobile infrastructure. Avoid duplicate public catalog and any inferred charging authority. External partner APIs and physical chargers require separate authorization and evidence.
