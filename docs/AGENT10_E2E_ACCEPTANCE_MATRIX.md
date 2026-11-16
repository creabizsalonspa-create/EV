# Agent 10 — Full-platform reproducible acceptance matrix

**Owner:** independent QA / release-readiness. **Target:** development/fullstack-continuation at a recorded 40-hex SHA.
**Safety boundary:** all commands below are for an isolated synthetic checkout/CI or already-approved private staging. Do not run setup, migrations, reset, restore, payments, OCPP commands or simulator tests on production. Never reuse a live business database. Agent 4 decides integration; product defects return to owners. Passing one row never certifies another row.

## Capture for EVERY run
Record immutable source SHA, checkout branch and clean status; UTC date; Node/PHP/Flutter/Docker/Postgres versions; exact command and exit; test count and skipped cases; workflow run ID, attempt, job URL; artifact basename, SHA-256 and privacy classification; environment and whether the service is synthetic, sandbox, staging or live; reproducible redacted failure pointer; and external approval reference when applicable. A source mismatch, a skipped mandatory job or unknown provenance is NOT VERIFIED, not passed.

## Acceptance stages and exact commands

| Gate | Surface / scenario | Reproducible command or approved job | Release interpretation |
|---|---|---|---|
| 0 | Fixed original 42/312 inventory and impossible stage inflation | python3 scripts/requirement-coverage --check; node --test tests/staging-agent10-acceptance-audit.cjs; node scripts/agent10-acceptance-audit.cjs | Software evidence only; never live readiness |
| A | Clean bootstrap, migrations, source image, rollback guards | ./scripts/setup; ./scripts/staging-offline-verify; staging-image GitHub Actions job | Image build/disposable restore is not a deployed, observed staging runtime |
| B | Customer registration, roles, vehicles, discovery, map, reservations, cancellation and session | npm run test:e2e; npm run test:discovery-connected; npm run test:vehicle-models; npm run test:driver-preferences; npm run test:station-information; npm run test:manual-soc; node tests/charging-client.cjs | Browser simulator test only; capture EN/AR, 390px and privacy results separately |
| C | Vendor KYC, staff consent, station/charger/connector configuration, maintenance, settlement | npm run test:vendor; npm run test:commercial; node tests/operational-connected.cjs | Internally reviewed synthetic KYC is not third-party legal verification |
| D | Admin approval, RBAC, audit, tenant denial, privacy and operational health | npm run test:e2e; node --test tests/agent9-*.test.cjs; ./scripts/php artisan test --compact | Confirm actual authorization boundaries, not merely page rendering |
| E | OCPP 1.6 + 2.0.1 simulator provision, authorized start/stop, heartbeat, meters, fault and recovery | ./scripts/csms-setup; ./scripts/csms-start; npm run test:ocpp; ./scripts/charging-setup; ./scripts/charging-bridge-setup; node tests/charging-session.cjs; node tests/charging-business.cjs | Sandbox-only; do not equate simulator receipt, clock, transaction or charging invoice with physical charger |
| F | OCPI sandbox partner catalog, source state, command uncertainty, reservation/expiry and invoice | EV_ROAMING_VERIFY_RATE_LIMIT=1 npm run test:roaming; npm run test:ocpi-provisioning; tests/roaming-workspace.cjs in hosted browser job | Must show truthful freshness/capability and non-replay. Contracted live partner is separate |
| G | Financial checkout, test refund/dispute, ledger, concurrency, payouts and tenant scope | npm run test:commercial; services/business-api/tests/Postgres/run-finance; services/business-api/tests/Postgres/run-connectors; node --test tests/financial-connected.test.cjs | TEST funds and internal payouts are not live bank/PSP/legal merchant approval |
| H | Canonical Android and iOS Customer/Vendor application | Native client validation GitHub Actions on exact source, including Linux Android and macOS iOS jobs; inspect complete per-step logs, package hashes and explicit skips | Debug APK/unsigned simulator ZIP != signed store release. Agent 6 owns signing/build configuration |
| I | Isolated private runtime, roles, backup and rollback | ./scripts/staging-offline-verify; full hosted staging-image, csms-recovery, recovery-acceptance; after separate approval only, follow docs/STAGING_ACCEPTANCE_EVIDENCE.md and docs/STAGING_APPLICATION_RELEASE.md | Disposable CI recovery is not a real staging restore; owner approval required for deployment |
| J | Security, performance, accessibility and actual external authorization | Independent access-control/security/load tests plus physical OCPP, contracted OCPI, PSP/bank and legal/store authority | Production acceptance remains blocked until real verified records exist |

Commands that start services are for a newly prepared isolated test environment. The platform and mobile workflows, not a manually reduced script, are the canonical hosted acceptance matrices. Do not bypass their budgets, rate limiting, hard failure checks or source/fixture isolation.

## Minimum cross-module journeys requiring traceable acceptance

1. Customer finds a genuinely compatible connector from a truthful live/unknown source → fresh tariff and consent → booking concurrency and idempotency → authenticated start → source meter and final stop → exact invoice → visible customer/vendor/Admin history; negative wrong-tenant, no-source and stale-source cases.
2. Vendor onboarding/KYC/staff approval → Admin review with separate actor → site/charger/connector topology → operational schedule/maintenance → reservation rejection during blocks → source command history → earnings, settlement and internal dispute. Test revocation and account switching at every privilege transition.
3. OCPP 1.6/2.0.1 independently: bootstrap, heartbeat, connector states, remote and RFID authorization, concurrent sessions, offline/reconnect, duplicates, uncertain commands, monotonic meter history, compensation-free close and reconciliation.
4. OCPI discovery-only is visibly read-only until contract-approved; approved sandbox partner start/reserve/cancel/stop must preserve source authority, non-replay uncertain outcomes, tenant secrecy, and new source availability before capacity release.
5. Test-payment retry, 429, duplicate webhook, partial/refused refund, dispute hold, payout/reconciliation, ledger exactly-once and simultaneous booking/charge contention under PostgreSQL. No live card tests without merchant authorization.
6. Customer/Vendor mobile on BOTH Android and iOS with fresh install/login/logout/role switch/background and foreground privacy; EN/AR RTL, small screens, push and deep links, settings, billing and charging. Signed distribution and store submissions are independent stage G requirements.
7. Approved private staging from exact source/image and migrated disposable databases → live read-only health and protected route checks → backup/restore with measured RPO/RTO → rollback to verified immutable image and persisted source; never touch production.

## Failure classification
- SOFTWARE_FAIL: reproducible behavior at exact source; report path, command, expected/actual and owning agent.
- CI_OR_ENV_BLOCKED: missing services, credentials, toolchain, source, observed health or failed job; no inferred pass.
- EXTERNAL_APPROVAL_PENDING: real charger, live OCPI contract, PSP/bank, legal, device signing, store or operator approval.
- NOT_RUN: command not executed in this QA checkpoint.
- PASS_SYNTHETIC: measured passing simulation only at named source and test environment.
- ACCEPTED_LIVE: only with independently verified real-world approval and per-atom production acceptance evidence.

## 15 November 2026 checkpoint for this matrix
Integration 28701c08033b06c92515601f983f3b85bddb84ae has green Platform 43740451479 and Native 43740451545 Actions runs. QA branch does not re-execute those full integration suites locally. PR #130 OCPI sandbox browser run 43743099160 has a reproducible failure at tests/roaming-workspace.cjs:101 and no approval. No physical OCPP, live OCPI, merchant, private deployed staging, signed Android/iOS, store or full customer delivery acceptance is evidenced. See docs/AGENT10_INDEPENDENT_QA_2026-11-15.md.

**Release decision: INCOMPLETE — CONTINUE REQUIRED.**
