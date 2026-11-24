# Licensed discovery quality monitoring (6.2.4, partial)

Optional `OCM_DISCOVERY_QUALITY_ENABLED=true` enables best-effort aggregate observations of cold Open Charge Map fetches. It does not enable the licensed provider itself or alter its terms, API-key, consent or quota gates.

Run `php artisan discovery:licensed-quality` from the authorized business API console. The fixed 24 UTC-hour rolling view contains observed hours only. Each bucket expires after 24 hours. No geographic area, coordinates, user, station ID, credential, raw record, request fingerprint or filter is retained by this monitor. The source's existing five-minute normalized cache is unchanged.

Successful fetches count the licensed, validated source subset before customer filtering (at most 25 records per fetch); observations may repeat the same station across independent fetches. Provider busy/unavailable/invalid responses are counted separately. Cache hits, local cooldown and consent/validation errors are not provider observations. Cache errors, lock contention and eviction may omit observations; absent hours are not measured zeros. This is neither a complete geographic coverage assessment nor a live availability/SLA/physical charger assertion. No automatic provider requests or charging, reservation or payment writes occur.

Synthetic acceptance is in OpenChargeMapDiscoveryTest. Exact-head hosted validation and Agent 5 integration remain necessary. Real license activation, independent geographic coverage audits and production SLA evidence remain separate prerequisites.
