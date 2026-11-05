# Charging evidence dashboard

`GET /api/charging-operations/dashboard` and bearer-authenticated `/api/v1/charging-operations/dashboard` provide a read-only operational overview for station-owning vendors and administrators. Other roles receive403; guests401. Existing platform throttles apply. No company staff privilege is inferred from station ownership.

The response contains station count, command counts by recorded transport status, open source-linked session count, unresolved recovery case count, and a25-row paginated attention list (`page`1..100000). An open link has neither a source end nor a closed-without-dispatch timestamp. Missing evidence means no source event has been recorded, or its last timestamp is strictly older than300seconds. Exactly300seconds remains within the window. Attention rows contain only session ID, protocol, start/last-event timestamps and `not_observed`/`stale` evidence labels. Aggregates cover the complete authorized scope, independently of pagination.

Responses are private/no-store. The endpoint does not read a remote service, dispatch a command, reconcile a session, release capacity, mutate finance or expose source identifiers, tokens, command payloads, customer identities or recovery notes. Counts describe recorded business evidence, not charger connectivity, heartbeat health, availability or physical charging. `physical_charging_verified` remains false. The threshold is a triage aid, not a protocol timeout policy.

The command-history web panel and native recovery screen show the same summary in English/Arabic. Existing source inspection, recovery authorization and evidence-required resolution remain unchanged. This is an additive operations slice; full diagnostics/firmware control, hardware authentication and operational analytics remain unfinished.

Focused backend validation: `./scripts/php artisan test --compact --filter='ChargingDashboardTest|ChargingOperationsTest'`. Native validation: from `apps/mobile`, `flutter test test/charging_operations_test.dart` and `flutter analyze`. Never describe native checks as passed unless executed with the pinned Flutter3.35.7 toolchain.
