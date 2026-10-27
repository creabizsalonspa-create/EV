# Active development checkpoint

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
