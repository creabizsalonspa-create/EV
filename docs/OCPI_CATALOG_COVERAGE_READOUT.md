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
  location records with finite longitude and Web-Mercator-displayable latitude
  (within ±85.05112878°). A geographically valid polar location is counted as
  published but excluded from the map-ready subset. Coordinates at (0,0) are
  valid geometry; they do **not** certify that an actual charger exists there.

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

The existing reported/receipt clock buckets also reject impossible historical timezone offsets (for example, `+14:30`, `+99:99`) instead of accepting PHP's normalization into another instant. These remain `unknown`, not evidence of source freshness. Valid `+14:00` timestamps are still classified by their absolute UTC instant.

The Open Charge Map discovery-only projection also validates the **real**
numeric UTC-offset bounds of each crowd-reported `DateLastStatusUpdate`.
Malformed offsets (including `+14:30`, `-14:01` and rollover candidates) are
treated as source clock **unknown**, not as verified or recently updated
charging locations; existing per-record license and attribution obligations
remain unchanged. The isolated source projection regression is in
`OpenChargeMapDiscoveryTest`. This does not enable live partner charging.

**Validation requirements:** `OcpiCatalogObservationsTest` on SQLite and the existing
PostgreSQL connector matrix, covering country/party separation, all three supported markets,
unpublished records, unknown countries, malformed coordinates, privacy, no external requests,
and no charger/financial writes. The hosted exact-head Platform/Native matrices are mandatory
before the sole integration controller (Agent 5) may merge this PR. Real OCPI partner coverage
agreements, external provider completeness and production/hardware acceptance remain separate.

## 19 November 2026 — native contract repair and connected parity

The previous PR189 source `f647de5` failed hosted job `133088673823`
(run `43803432671`) at Pint: `fully_qualified_strict_types` in
`OcpiCatalogObservationsTest`. The local failure was reproduced; importing
`CarbonImmutable` preserves the exact test and fixes the style failure.

A separate functional regression was reproduced against the original Flutter
client: its fixed locations-field count rejects the two new country facets.
The canonical native observations view now validates and renders both facets
in English and Arabic, including `other_or_unknown`. It requires the stated
location-country classification and non-exhaustive coverage flag, and rejects
missing/extra market keys, negative/noninteger counts, contradictory publication
or usable-coordinate totals, wrong provider/module and upgraded authority. The
10,000-record bound applies to the combined modules in both web and native.
No partial report is rendered after a failed contract. Existing session,
provider, foreground and stale-response fences are preserved. The phone
regression also exposed a dropdown overflow; the field now expands within its
available width.

`tests/native-partner-catalog-observations.cjs` reuses the existing disposable
local catalog fixture and executes `live_ocpi_catalog_observations_test.dart`
against real Laravel for all/locations/tariffs and customer403 denial. Its
private mode0600 input is removed after the run. The fixture creates only
synthetic local inventory/source records; the client sends no partner lookup,
charger command, booking or financial action. A dedicated connected-CI batch
keeps its two logins inside the unchanged authentication budget.

Local evidence and source-file fingerprints are recorded under
`docs/evidence/ocpi-country-coverage/`. Fresh hosted current-source Platform,
PostgreSQL, Android and iOS checks remain required. Agent5 retains sole merge
and release authority under the current five-agent mandate. This remains an
incremental source-quality implementation: original atom6.2.4 is not declared
complete, the 42/312 inventory and percentages are unchanged, and no live
OCPI partner, hardware, payment, deployment or production acceptance is claimed.
