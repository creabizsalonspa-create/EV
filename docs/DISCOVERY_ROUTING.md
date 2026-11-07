# Consented discovery, foreground GPS and charging-stop routes

Current candidate extends PR16 maps/favorites and retains PR18 finance APIs/screens. No financial rule or schema-up change is introduced.

## Implemented boundaries

Flutter customer maps provide explicit app consent before one-shot OS foreground location permission. Disabled, denied, permanently denied, stale, invalid and timed-out fixes retain manual entry. No background location, tracking stream, persisted GPS history or implicit nearby request. Android uses coarse/fine only; iOS has localized English/Arabic When-In-Use text and the geolocator Always bypass. Build-time guards inspect merged Android manifests and actual iOS app privacy resources.

Web and Flutter support explicit-consent address autocomplete, manual route endpoints, up to three ordered canonical current-page charging stops, sourced route previews and separately consented external navigation. Directions use a fixed HTTPS Google Maps URL and preserve stop order. They do not reserve connectors or promise energy, tariff, traffic, charger availability or charging time. Account changes clear lookup/route state; stale responses cannot repopulate it. Current-session expiry clears the owning Flutter session; stale request-generation conflicts do not sign out a newer identity.

## API and activation

Both `/api` (existing cookie/CSRF web middleware) and `/api/v1` expose `GET discovery/capabilities`, `POST discovery/addresses`, `POST discovery/route`. See routes/discovery.php for exact paths. POST requires explicit consent. Addresses accept query (3..180), language en/ar and optional two-letter country. Routes accept two endpoints plus up to three stops with latitude/longitude only. All returned route estimates carry provider evidence, meters/seconds and false traffic/charging-time flags.

The only adapter is fixed-origin HTTPS Geoapify. It is disabled until `DISCOVERY_GEOCODING_ENABLED` / `DISCOVERY_ROUTING_ENABLED` and a server-only `DISCOVERY_GEOAPIFY_KEY` are configured. Never ship the key to clients. Obtain an authorized provider account, review licensing/privacy/quotas, configure shared production cache for rate budgets and verify real regional responses before operational acceptance. Redirects/retries are disabled; timeout, size, geometry and provider-error bounds apply. Bodies/coordinates are not persisted by this feature. Retain deployment log-redaction policy. Default budgets: 12 requests/IP/minute, 30 globally/minute and 5000 globally/day; authentication security remains unchanged. No automatic write/command replay.

## Validation and limitations

Local final combined Laravel run: 345 tests / 8438 assertions; focused final discovery/retention suite 11 / 93. PostgreSQL financial suite 220 / 7118 and independent-process races 58 / 758 passed. Final Flutter full suite:128 pass / nine deliberately gated live-only skips; focused GPS/routes suite12 passed with clean analysis. Eight native suites against actual Laravel passed. Discovery browser suite passed, including Arabic digits/RTL at390px, debounce/stale responses, consent, stop order, safe labels, default-disabled provider, session clear and delayed GPS cleanup.

Three additive migration DOWN guards now refuse non-testing rollback before any DDL when payout/loyalty history exists, including payout identity-index rollback. They were demonstrated red before correction (3 failed) and green afterward (3 / 36), including actual PostgreSQL coverage. No accounting computation or schema UP was changed.

PR19 passed all ten checks and merged atd846de0 from sourceb336f41. Hosted Android/iOS compiled permission checks and expanded iOS runtime GPS/route assertion passed. Device location/provider responses in UI tests are synthetic and clearly labeled. Native SDK/Xcode/Ruby are unavailable locally. Unified partner favorites/maps, reviews/moderation, energy-aware routing and full commercial/native parity remain incomplete; the original product scope is unchanged.
