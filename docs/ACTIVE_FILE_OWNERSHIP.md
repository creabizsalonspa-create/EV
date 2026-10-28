# Active parallel file ownership

Owner restored parallel work on28 October2026. Agent1 owns finance/commercial backend/business migrations and financial web/Admin. Agent2 owns EV, native applications, nonfinancial web/Admin, security/deployment and integration. Existing finance code is preserved; Flutter consumes completed APIs. Inspect fetched remote branches/PRs before every merge.

Current Agent2 batch: native one-shot GPS, server-only address/routing adapters and connected discovery/charging-stop UI. Agent2 owns apps/mobile/**, nonfinancial discovery web assets, new Discovery controllers/services/config/routes/tests, and its associated documentation. No financial controllers/services/migrations/screens are modified.

Shared-file additions reserved for this batch: bootstrap/providers.php adds only DiscoveryServiceProvider; routes/web.php and routes/api.php add only an isolated discovery routes mount; platform.blade.php adds only nonfinancial discovery assets; existing progress/API documents receive additive checkpoints. Preserve Agent1 additions during a three-way integration; never replace entire shared files from another branch. No new finance PR was visible when this record was written; review the latest Agent1 PR before integrating it.
