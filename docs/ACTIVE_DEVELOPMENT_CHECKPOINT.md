# Active development checkpoint

Owner: **Agent 2, complete financial and non-financial software**, until the owner explicitly reassigns it. Full original PRODUCT_REQUIREMENTS scope remains required. Do not restart or rebuild completed modules.

## Source and integration

- Latest locally tested implementation commit: `478a06e28d0706e8fcd6114b60a1e0b6bddda314`.
- Main integration branch: `development/fullstack-continuation`. Slice branch: `development/subscription-dunning`, [PR #10](https://github.com/creabizsalonspa-create/EV/pull/10). Fetch actual head and check PR merge state before editing; documentation-only commits may follow the tested implementation.
- Finance PR #7 head `6f2103ee43b3e9569063f8ac65cf0fb9017f9d25` is integrated in full through merged PR #8 at `00685cca87c5262715eba0d98855564d4b1dded5`. Core payment/subscription/credit/accounting services remain byte-identical to that finance head. Preserve original branches and both histories. Native PR #9 source60999f5 is included in PR #10; do not implement a competing copy.
- Full-platform draft [PR #3](https://github.com/creabizsalonspa-create/EV/pull/3) remains the continuation review, not a final release.

## Latest completed slices

Secure TLS charger admission/provisioning with one-time versioned credentials, revocation, optional mTLS pinning, production HTTPS issuance/callback guards, web/native controls and encrypted operational investigation/source-proof audit without blind resend or forced capacity release. Existing canonical topology, parallel EVSE reservations, arrival/expiry, source-backed command reconciliation and financial software are preserved. SQLite downgrade issue #6 has independent guarded rollback evidence and is closed.

Canonical Flutter now consumes tenant-scoped financial recovery/history/provider inspection and subscription notices. It never infers invoice payment from operation completion, stores charger/payment secrets or retries a lost financial mutation. Exact amount presentation uses validated server precision and the existing decimal formatter. EN/AR RTL, empty/error states, expiry and response-generation fencing are tested.

Durable in-app subscription dunning records immutable day1/3/7 notices, prevents duplicate workers and reminder bursts, avoids bounded-scan starvation, and suppresses paid/cancelled/zero-price/uncertain-provider invoices. Backend/web/Flutter read current invoice/recovery state. No automatic collection, renewal scheduler, grace entitlement, email, SMS or push delivery is claimed.

## Verification and live CI evidence

Local implementation478a06e: Laravel228 tests /7192 assertions; PostgreSQL connector39 /579 plus11 process races /152; financial126 /6164 plus31 process races /403. Flutter80 full unit/widget passes /seven explicit live-only skips, nine targeted recovery tests and clean analysis. All six fixture-driven real-Laravel native suites passed; the seventh generic native API smoke separately passed fleets/meters/bookings/pagination/privacy. Full commercial browser and new EN/AR390px dunning browser passed, as did preceding OCPP1.6/2.0.1 canonical restarted-source/meter/invoice simulations and actual loopback TLS/mTLS ingress tests. Composer strict and schedule registration passed.

[PR #10 current checks](https://github.com/creabizsalonspa-create/EV/pull/10/checks) are the authoritative live result; do not infer hosted acceptance from local counts. Recorded implementation runs: platform [40602428662](https://github.com/creabizsalonspa-create/EV/actions/runs/40602428662) / [40602434887](https://github.com/creabizsalonspa-create/EV/actions/runs/40602434887); native [40602428875](https://github.com/creabizsalonspa-create/EV/actions/runs/40602428875) / [40602435213](https://github.com/creabizsalonspa-create/EV/actions/runs/40602435213). Check source SHA and exact completed job conclusions before merge/release. Final documentation-only verification is distinguishable by Git diff from implementation478a06e.

No current local failures or uncommitted implementation changes at this checkpoint. Historical CI failures (HTTPS fixture, upstream reconnect cleanup, aggregate loopback HTTP429) are retained in prior records. Test pacing now respects unchanged production limits; no limiter cache clear or blind mutation replay. Older cancelled native runs were superseded, not counted as passes. Android/iOS artifacts are debug/unsigned simulator outputs; signing/store/device acceptance is not established. No local Android SDK/Xcode exists.

## Exact next implementation

1. Finish checking the current PR #10 source; merge normally only after combined verification, preserve its full ancestry and inspect updated remote main. Update/close superseded PR #9 as appropriate. If already merged, skip this step.
2. Implement **provider-hosted saved mandate enrollment, consent and revocation** with server-owned customer/SetupIntent identity binding. Then extend the canonical gateway/recovery/receipt architecture for off-session collection, SCA/decline handling, bounded dunning/retry and external notification delivery. Do not infer a mandate from a client-supplied PaymentMethod; do not create a second ledger/state machine.
3. Continue production CSMS upstream identity/JWT/rider-token authorization, certificate lifecycle, real command recovery/monitoring; authorized OCPI interoperability; interactive maps; remaining customer/vendor/Admin/KYC/staff/fleet/loyalty/security/deployment/release workflows in REQUIREMENT_STATUS.

## Commands and contracts

`./scripts/setup`, `./scripts/start`, `./scripts/csms-start`, `./scripts/charging-setup`, `./scripts/charging-bridge-setup` preserve existing environment/data. Use pinned `./scripts/php` and Flutter3.35.7 under `/workspace/.tools/flutter/bin`, with workspace PUB_CACHE/XDG_CONFIG_HOME/analyzer cache settings. Never reset HOME, force-push, delete old volumes or reseed existing business data.

Run `./scripts/php artisan test`, `./scripts/php vendor/bin/pint --test`, Composer strict validation, `services/business-api/tests/Postgres/run-finance`, `.../run-connectors`, repo npm browser/protocol suites, `node tests/charger-ingress.cjs`, `node tests/charger-operations.cjs`, `node tests/subscription-dunning.cjs`, `node tests/native-connected.cjs`, Flutter analysis/tests and the opt-in generic API smoke. Run authentication-bearing suites sequentially. The connected runner waits a full authentication window after the prior batch completes. Supervise source observation through `./scripts/csms-consume`; web inspection uses private Graph URL/network attachment established by charging-setup.

Migrations: admission/cases/events2026_10_30_000001/000002; finance recovery/credits2026_10_28_150001/160001; dunning2026_10_30_100001. See CHARGER_ADMISSION, PAYMENT_RECOVERY, SUBSCRIPTION_CREDITS, FINANCE_MOBILE_CONTRACT and SUBSCRIPTION_DUNNING. The hourly `commercial:subscription-dunning --limit=100` schedule requires a supervised scheduler; registration is not a running worker. Core finance/lifecycle schedules remain intact.

## Incomplete and external acceptance

Software remains **IN_PROGRESS**: mandates/automatic collection, full native commercial workflows, production CSMS/rider authority, OCPI interoperability, maps, complete vendor/Admin/fleet/engagement/security/notification/release scope. Missing credentials do not block independent software implementation. No completion percentage or final-release claim is made.

External gates: real chargers/firmware/meters; merchant PSP/3DS/mandate and payout acceptance; bilateral roaming contracts/credentials; production infrastructure/security/restore acceptance; mobile signing, physical devices and stores. Current hosted SDKs validate the recorded debug/simulator contract, not current app-store eligibility. Environment configuration is saved as a draft, not applied/published; fresh-task and Docker-volume restoration remain independently unverified.

<details><summary>Historical checkpoint records (superseded; retain for audit)</summary>

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

</details>
