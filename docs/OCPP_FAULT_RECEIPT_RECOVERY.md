# OCPP fault receipt recovery safety (3.3.4 / 5.3.7 / 5.3.8)

This workstream intentionally does not grant production authority or physical acceptance. The event monitor records authenticated simulator statuses, not verified contactor state or energy flow.

For OCPP 1.6 the station sends an explicit connector `errorCode`; an `Available` message lacking `NoError` cannot clear a GroundFailure, mark a fault recovered or advertise a healthy connector. The source remains unknown for that presentation. OCPP 2.0.1 does not have the same 1.6 `errorCode` requirement; a fresh Available receipt may clear a fault only after current canonical source identity and chronology checks.

Regression: `./scripts/php artisan test --filter=ChargerDiagnosticsTest`; `node --test integrations/hasone-express/tests/ocppConnectorObservation.test.mjs`. The tests must run against the exact PR head and all mandatory CI gates. This does not prove hardware fault clearance or production readiness.
