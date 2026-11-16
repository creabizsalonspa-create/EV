# OCPI 2.2.1 partner catalog — read-only market coverage-quality readout

**Candidate owned by Agent 2, 16 November 2026.** This feature extends the existing tenant-scoped, read-only
`GET /api/ocpi/providers/{provider}/catalog-observations?module=locations` endpoint. It does **not**
create a second station catalog, add external network calls, infer availability, authorize roaming,
book a connector, initiate a payment, modify OCPI source evidence, or claim that a country's charging
network is completely known. Original PRD coverage atom 6.2.4 remains **partially addressed**, not
production accepted.

The response already describes recorded source/receipt ages, malformed or unknown coordinates,
published/unpublished source markers and reported connector data under a strict 10,000-row limit.
A larger report still fails closed without partial counts. This candidate adds two consistently
named per-market summaries to the **locations** module:

- `published_by_reported_market` — count of stored partner location *records* marked
  `publish=true`, grouped as `AE`, `US`, `CA`, or `other_or_unknown`.
- `published_with_usable_coordinates_by_reported_market` — the subset of those published
  location records with bounded, finite geographic coordinates. Coordinates at (0,0) are valid
  geometry; they do **not** certify that an actual charger exists there.

The region is determined solely by the partner-reported location's ISO 3166-1 alpha-3 field
(`ARE→AE`, `USA→US`, `CAN→CA`). We **never** infer a physical location from the OCPI
country/party identity, provider name, browser IP, user GPS, or a license agreement. Unsupported,
missing and malformed location countries are counted under `other_or_unknown`, without
inventing another mapped country. An unpublished record is excluded from these new published
counts, though the original total and publication-facet counts remain unchanged.

Top-level evidence explicitly states
`market_classification=PARTNER_REPORTED_LOCATION_COUNTRY_ONLY`,
`coverage_exhaustive=false`, `live_availability_verified=false`,
`physical_charging_verified=false`, `remote_control=false`, and
`billing_authority=false`. Counts are provider-scoped and retrieved only by that vendor or
an authorized web admin with a current session/role/epoch. Customer and other-vendor access
is still blocked. The HTTP response remains `Cache-Control: private, no-store`.

The existing English/Arabic operator readout (`public/partner-catalog-observations.js`) now
validates the complete source-only schema and shows both market counts as separate
facets. It rejects contradictory totals, including an aggregate exceeding
`stored_publish_true`, out-of-bounds values, and usable-coordinate counts
greater than published counts. It does not render raw location names or party
identifiers as market labels. The browser fixture in
`tests/partner-catalog-observations.cjs` covers both languages, source labels,
privacy, bad contracts, and stale-session blocking. A standalone V8 mocked-DOM
probe of the exact JS head confirmed that a coherent report renders and that
an inflated market total is rejected; this is **not** a substitute for real
Chromium or PostgreSQL/CI verification.

**Validation requirements:** `OcpiCatalogObservationsTest` on SQLite and the existing
PostgreSQL connector matrix, covering country/party separation, all three supported markets,
unpublished records, unknown countries, malformed coordinates, privacy, no external requests,
and no charger/financial writes. The hosted exact-head Platform/Native matrices are mandatory
before the sole integration controller (Agent 5) may merge this PR. Real OCPI partner coverage
agreements, external provider completeness and production/hardware acceptance remain separate.
