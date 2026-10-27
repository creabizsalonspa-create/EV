# Active development checkpoint

Production credential-issuance boundary now rejects plaintext requests before rotating/disclosing any secret (owner authorization still runs first). HTTPS issuance remains supported; local simulator behavior is preserved. Targeted admission8/171, combined Laravel228/7192, PostgreSQL connector core39/579 and11 independent-process races/152 passed. Financial core126/6164 +31 races/403 and native80/default7 skips are separately recorded; hosted verification must cover the final pushed source. Production TLS termination must be represented by a correctly configured trusted proxy; untrusted forwarded headers are not a bypass.


## Current verified integration and active slice

Latest merged integration: `00685cca87c5262715eba0d98855564d4b1dded5` on development/fullstack-continuation, PR #8. Contains the entire Finance PR #7 head 6f2103e; original branches remain preserved. Platform/finance-PostgreSQL/connector-PostgreSQL checks passed on final source 3f7e8af (runs 40598832424 /40598840859); unchanged native source passed Android debug, unsigned iOS simulator and iOS UI (40597931320). Native payment-recovery source 60999f5 is pushed in PR #9 and included in the new dunning continuation branch.

Latest locally tested application source: `cb46bbcfde0dff1185fe162012996e328288b666` on development/subscription-dunning, PR #10. Platform/PostgreSQL and Android hosted checks for this source passed; iOS UI is still pending. A subsequent test-only pacing correction waits a full window after the last batch completes (all six connected suites rerun successfully). Active branch includes merged integration plus native recovery and actual backend/web/Flutter dunning. Local Laravel 227/7180, PostgreSQL financial126/6164 and races31/403, Flutter78 passed/seven explicit live-only skips; all six connected native suites and new browser notice UI passed. Commercial regression passed; hosted validation must pass before merging this slice. The previous PR #9 native failure was genuine HTTP429 from aggregate loopback logins; new connected runner respects the existing 10/minute budget, without cache clears, limiter edits or automatic login/payment replay. Exact next implementation: saved provider mandates/consent/revocation, existing-gateway off-session collection and dunning delivery. See SUBSCRIPTION_DUNNING.md for boundaries. Remaining EV/full product/external gates below remain active. Older candidate sections are retained as history.


Owner: Agent 2, complete financial and non-financial software (final owner directive, 28 October 2026 Dubai). Full original scope remains in force.

## Integration candidate

Latest pushed EV milestone: `4f87b24e6485cd56c94cf53c8afdb027c1a4c049` on `development/fullstack-continuation`, PR #3. Candidate branch: `integration/finance-pr7-ev-admission`, merging Finance PR #7 head `6f2103ee43b3e9569063f8ac65cf0fb9017f9d25`. Preserve this complete ancestry. All 41 incoming finance files outside shared registrations/reports remain identical to that head. Shared API/provider registrations retain both workstreams.

## Implemented and tested

Secure TLS admission, rotating/revocable one-time charger credentials, optional mTLS pinning, vendor web/native provisioning, source-backed operational investigation with encrypted audit notes, and SQLite canonical downgrade guards. No blind start replay or forced capacity release. Finance merge adds durable provider-bound recovery and immutable subscription credit/refund accounting, without a competing ledger.

Combined local evidence: Laravel 221 tests / 7136 assertions; PostgreSQL finance 120 / 6120 plus 30 process races / 390; PostgreSQL connectors 38 / 567 plus 11 races / 152. Connected commercial and charging-operations browsers, general browser suite, native real-API operations test, TLS 1.6/2.0.1 and mTLS gateway tests, and canonical OCPP daemon-restart transaction/meter/billing simulations passed. Earlier EV native suite: 71 passed with five explicit live-test skips; analysis clean. All live payments remain mocks/contracts.

## CI correction and pending merge

EV milestone platform CI failed because the admission fixture set HTTPS server metadata but still built an HTTP URL. Candidate corrects the fixture to use explicit HTTPS with the local exception disabled, preserves production guards and adds an actual dedicated-limiter 21st-request HTTP 429 regression. Candidate hosted CI and native Android/iOS builds must pass before merging through a new integration PR. PR #7 standalone checks passed; do not substitute those for combined checks. No local Android SDK/Xcode exists.

## Next actions

1. Push the candidate merge, open its PR to fullstack and verify combined hosted platform, PostgreSQL, Android and iOS checks. Merge normally, preserve original branches; update PR #7/#3.
2. Implement saved provider mandates, recurring collection and dunning in the existing subscription/payment architecture, with tenant isolation, durable uncertainty and PostgreSQL races; add customer/vendor web and canonical Flutter workflows.
3. Continue native finance recovery/credit visibility, CSMS upstream secure registration/token authorization, full OCPI interoperability, maps, vendor/KYC/staff, non-financial Admin and remaining requirement-matrix items.

## Contracts and commands

New migrations: charger admission and operational cases/events `2026_10_30_000001/000002`; finance payment recovery and subscription credits `2026_10_28_150001/160001`. See CHARGER_ADMISSION.md, PAYMENT_RECOVERY.md, SUBSCRIPTION_CREDITS.md and FINANCE_MOBILE_CONTRACT.md.

Use `./scripts/php artisan test`, `./scripts/php vendor/bin/pint --test`, `services/business-api/tests/Postgres/run-finance`, `services/business-api/tests/Postgres/run-connectors`, repository npm suites, `node tests/charger-ingress.cjs`, `node tests/charger-operations.cjs`, `node tests/native-charger-operations.cjs`, and pinned Flutter under `/workspace/.tools/flutter/bin`. Source observer runs through `./scripts/csms-consume`.

External gates: physical chargers and firmware interoperability; real PSP/mandate/3DS acceptance; bilateral OCPI credentials/contracts; production infrastructure/security/restore exercises; mobile signing, devices and store acceptance. Entire software is not complete or production accepted. Remaining requirement evidence is in REQUIREMENT_STATUS.md and the module continuation reports.

## Subsequent native slice

Branch `development/native-finance-recovery` adds the tested canonical native payment-recovery queue/history/inspection workflow. Local Flutter 77 passed / six live-only skips, analysis clean, plus separate real-Laravel client test passed. Integration candidate and native slice still require hosted validation and PR merges. Next implementation remains saved mandates, recurring collection and dunning in the existing architecture.
Integration CI additionally exposed a post-rotation upstream station-identity cleanup race (HTTP 502 on the new TLS upgrade). The test now uses bounded transport-only backoff after proven revocation, refuses to retry 401/429 and sends no OCPP frame until an upgrade succeeds; it then verifies the new BootNotification. Local TLS/mTLS checks passed. CI must rerun; this is not permission to resend charging commands.
