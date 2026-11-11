# Agent 2 — live EV integration and demo-readiness workstream

**Owner:** ChatGPT in the owner's main conversation, using authorized Desktop Commander and GitHub tools.
**Parallel developer:** Agent 1 / Codex, working on the Laravel/PostgreSQL/CitrineOS application and Flutter clients.

This is a **separate, non-deploying** workstream. Do not merge live Express/MongoDB data into the Laravel canonical finance ledger or overwrite any OCPP deployment to accelerate the demo.

## Nonoverlapping task ownership

| Agent 1 | Agent 2 |
| --- | --- |
| Laravel/CitrineOS core charging, financial integrity, OCPI partner and billing contracts | Existing deployed Express API and OCPP gateway inspection; compatibility evidence |
| Customer/vendor Flutter application source, Android APK/iOS simulator builds, native CI | Safe live API smoke tests, backend/client contract comparison and deployment safeguards |
| Merge/fix PRs #46, #47, #48, #49 after CI/e2e checks | Independent validation and proposed nonconflicting integration patches |
| Complete cross-role customer/vendor/Admin source workflows | Backup/restore evidence, deploy preparation, actual host connectivity |

**Integration principle:** choose a single authoritative session/ledger owner for each demo journey before enabling writes. Any cross-backend adapter must be explicit, tested and avoid duplicate charging sessions or payments.

## Verified host inspection — 2026-11-07 UTC

Authenticated Desktop Commander device: `host.crea-biz.com` (online).

- `https://hasone.io/` — landing site, HTTP 200
- `https://admin.hasone.io/dashboard` — Admin web route, HTTP 200
- `https://api.hasone.io/health` — HTTP 200, API health and MongoDB connected
- `https://api.hasone.io/api/v1/app/settings` — HTTP 200, valid JSON
- `/api/v1/NearestStations` — HTTP 401 without auth (expected)
- `/api/v1/VehicleBrands` — HTTP 401 without auth (expected)
- `/api/v1/payment-methods` — HTTP 401 without auth (expected)

A repeatable read-only smoke suite is at `tools/agent2/live-demo-readiness.mjs`.
The suite passed **7/7** checks when executed on the authorized server.
Pass does NOT mean authenticated bookings, real payments, physical charging, or complete demo success.

Run it with Node 20 or later:
```sh
node tools/agent2/live-demo-readiness.mjs
# Optionally, for an explicitly authorized short-lived customer test token:
EV_TEST_BEARER="$AUTHORIZED_TEST_TOKEN" node tools/agent2/live-demo-readiness.mjs
```
Do **not** commit, print or persist test credentials. All requests are GET-only.

## Live reference source and protected snapshot

Existing live deployment:

- `/home/hasio/ev-deployment/admin/UV Charging` — Express/Mongo API
- `/home/hasio/ev-deployment/ocpp-gateway-next` — OCPP gateway
- `/home/hasio/ev-deployment/admin-password-rotation` — Admin web
- `/home/hasio/ev-deployment/landing-uae-contact` — landing web

An independent live-code snapshot (excluding dependencies and logs, **not a verified database restore**) was created at:

`/home/hasio/ev-deployment/backups/ev-live-code-snapshot-20261107.tar.gz`

SHA-256: `fcee1117600a8777490c92809cb05446260a15c89d96eb1276c7e1974b1c52af`

216 entries; 5,190,343 bytes; mode 0600. No processes were restarted, no charger commands sent and no production settings changed. **This is a source snapshot, not a substitute for an encrypted database backup plus restore test.**

## Safety and current blockers

- Connected OCPP 1.6 charger was previously observed online. This does not establish start/stop, live metering or invoicing acceptance.
- Live OCPP remote-control feature was disabled during prior inspection; preserve safe configuration.
- PR #48 purchased Flutter customer app builds Android and iOS simulator, but authenticated booking/payment/charging flows require verification.
- Stripe card saving and booking payment contracts require comparison against the deployed Express routes before enabling any mutation.
- Test accounts, an approved charging simulator, sandbox payment credentials and authorized OCPI partner access are needed for the full end-to-end acceptance demo.
- The live Express/Mongo app and Laravel/CitrineOS platform are separate. Neither should generate an independent bill for the same real session.

## Next Agent 2 implementation tasks

1. Compare deployed API route definitions with the purchased Flutter client and document exact mismatches.
2. Add nonsecret read-only authenticated journey checks using an **authorized** test account/token.
3. Prepare isolated staging deployment and database-backup/restore checks before production changes.
4. Help validate customer → vendor → Admin records, charging evidence and sandbox invoice consistency against one canonical backend.
5. Coordinate precise cross-backend adapter changes with Agent 1 through separate PRs, never direct competing edits to its active source.

**Acceptance remains the complete original feasibility plan and later web/mobile scope.** This workstream supplies evidence and compatibility fixes; it does not claim the full system is delivered.
