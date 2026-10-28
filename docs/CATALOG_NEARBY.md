# Nearby managed/discovery catalog search — 28 October 2026

Agent2 EV/non-financial scope. Extends the existing canonical `/api/stations` catalog; no second inventory, financial pricing rule or booking authority.

## Implemented

Optional `latitude`, `longitude`, `radius_km` must be supplied together. Accepted limits: latitude −90..90, longitude −180..180, radius 1..200 km. Existing name, country, connector and minimum-power filters compose with the radius query. Exact great-circle haversine distance uses mean Earth radius 6371.0088 km, bounded parameters and numerical clamping. Spherical latitude/longitude prefilter handles wrapped date-line intervals and polar caps. Results sort by distance ascending then ID descending; unchanged requests retain existing ID ordering and omit distance. Pagination links retain only validated filters.

`CatalogGeo` uses the same SQL expressions on SQLite and PostgreSQL. Distance is a discovery estimate, never a route, driving time, meter reading, live occupancy or reservation capability. Existing source/provenance and canonical connector/EVSE authorization remain authoritative.

Web and canonical Flutter customer/vendor catalogs expose an optional coordinate/radius form, validation, search and filtered pagination, localized distance, clearing and existing loading/error handling. Flutter accepts Arabic/Persian digits and RTL layouts. Web uses browser numeric ranges and requires all three fields as a group. No device location permission is requested. Search coordinates are transmitted as query parameters; they are not stored as user preferences. Deployment access-log/privacy policies must account for search query parameters.

## Verification

- Laravel catalog tests: radius inclusion/exclusion and stable nearest order; no false live/reservable claims; date line from both sides; polar cap; composable filters and full-link pagination; invalid/partial/nonfinite/unbounded inputs. Full suite is recorded in QA_REPORT.
- Same four catalog tests included in the disposable PostgreSQL connector job, alongside existing topology and independent-process reservation/admission races.
- Flutter HTTP contract verifies the configured origin and complete filter preservation; widget tests verify Arabic digit normalization, grouped validation, clearing and RTL. Expansion storage is isolated from text-field scroll storage.
- Playwright `npm run test:nearby` checks web request parameters, grouped validation, rendered EN/AR distance, RTL and no discovery reservation control. Existing frontend/localization suites retained.

## Remaining software and acceptance

This is coordinate-based nearby search, **not an interactive map**. Map tiles, marker/clustering workflows, optional device-location consent/permissions, route-provider integration and partner-location map parity remain implementation work. Authorized OCPI partner projection remains its existing separate read-only workflow. Live freshness/availability must come from verified telemetry/partner evidence; geometry never grants charging capability. No physical charger, live partner, real-device GPS/navigation or store acceptance is claimed.
