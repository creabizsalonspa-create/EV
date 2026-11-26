# Licensed discovery coverage-quality history (6.2.4)

This complements the existing `discovery:licensed-quality` (cold source outcome and quality counts) and `discovery:licensed-coverage` (current per-area benchmark readout). All three consume the existing approved Open Charge Map discovery pipeline. No new provider requests, authorization, geographic collection, charging or financial capability is introduced.

Authorized operators can run:

- `php artisan discovery:licensed-coverage-history --capture` captures at most one validated aggregate observation per UTC hour across the currently configured public benchmark set. With no validated source sample, it records no observation and allows a subsequent attempt in the same hour. Existing hourly observations are not overwritten.
- `php artisan discovery:licensed-coverage-history` reads the preceding 168 UTC hourly slots. It distinguishes observed empty areas, areas with reports, unobserved areas and unverified cache areas, along with bounded source-age and operating-report counts. Missing observations are not zero coverage. Malformed stored observations are excluded and counted separately.

The existing provider enable, approved terms, API-key and explicit public benchmark configuration gates apply. Credential/configuration changes isolate historical cache entries. The report contains no coordinates, benchmark labels, station IDs, raw records, user identifiers or credentials. History uses the shared application cache and fixed seven-day TTL; cache evictions may remove observations. The history counts sampled records, not distinct stations across hours or geographic completeness. No automated provider polling is introduced. Operators can invoke the capture command from approved offline monitoring schedules; they must independently populate benchmarks through existing consented/rate-limited source queries.

Acceptance: `LicensedCoverageHistoryTest` exercises capture, duplicate hourly capture, cache expiry, empty versus missing observations, scope changes, revoked approval, malformed history and no additional outbound calls. Fresh exact-source hosted validation and Agent 5 controlled integration are required. Actual licensed access, representative benchmark selection and live coverage/SLA evidence remain external; no production or whole-atom credit is asserted by this candidate alone.
