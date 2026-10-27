# Internal QA checkpoint — development continues

**Overall: NOT READY FOR FINAL ACCEPTANCE.** This report covers implemented development workflows; it does not certify the full platform. Full scope is retained in PRODUCT_REQUIREMENTS.md and PROGRESS.md.

## Executed checks

| Check | Outcome | Evidence |
|---|---|---|
| Laravel tests | 31 passed, 226 assertions | evidence/backend-expanded.txt; includes 29 platform tests and 2 upstream scaffold tests |
| Browser end-to-end | Passed | evidence/browser-expanded.txt; Chromium + Playwright, synthetic data |
| OCPP 1.6 simulator | Passed tested subset | evidence/ocpp-expanded.txt |
| OCPP 2.0.1 simulator | Passed tested subset | evidence/ocpp-expanded.txt |
| Composer schema/lock validation | Passed strict validation | `./scripts/php /usr/bin/composer validate --strict` |
| PHP style | Passed 58 files | `./scripts/php vendor/bin/pint --test` |
| npm dependency audit | No reported advisories | evidence/npm-audit.json; this is not application security certification |
| Composer advisory audit | BLOCKED, exit 100 | evidence/composer-audit-error.txt; packagist.org API egress denied |
| Dependency license inventory | Generated | evidence/composer-licenses.json, THIRD_PARTY_NOTICES.md; full image SBOM review remains |
| Compose validation | Passed | `docker compose -f services/csms/compose.yaml --env-file services/csms/.env config --quiet` |
| Fresh/repeated local setup | Passed current instance | initial frozen dependency install, migrations and repeated scripts/setup |
| Hosted CI | UNVERIFIED | workflow supplied; GitHub API access denied |

Browser coverage includes account registration, vendor station creation, customer booking/cancellation, vendor visibility, tenant privacy, admin overview, garage privacy, fleet invitation acceptance, support internal-note privacy, Arabic direction and 390px width. This is not a complete translation, WCAG audit, or device matrix.

OCPP coverage includes Accepted BootNotification, valid Heartbeat time, StatusNotification acknowledgment, unauthenticated remote-command rejection, authenticated remote-start receipt/acknowledgment by our simulator, and a second command delivered from Laravel through the outbox/CSMS. These tests use real running CitrineOS/PostGIS/RabbitMQ but simulated chargers. They do not verify physical charging, transaction lifecycle/meter reconciliation, remote stop behavior, outage/fault recovery, charging safety, or hardware certification.

Backend tests verify tenant access, role escalation rejection, input limits, fixed-slot uniqueness, cancellation and idempotency, cumulative simulated meters, frozen integer cost estimates, profile boundaries, reset token expiry/single use and session removal, fleet consent/revocation/cost-center ownership, ticket transitions/internal-note privacy, notification ownership, outbox dispatch/rejection/uncertain outcomes and scoped rate limits.

## Findings corrected during implementation

- Database defaults were initially absent from freshly created model responses. Explicit default attributes now expose customer role, confirmed reservation, pending command and charging session status immediately; assertions cover these responses.
- Numeric-string station IDs caused idempotency payload mismatches. Input is canonicalized before comparison.
- CSMS HTTP 200 can contain a negative dispatch confirmation. Negative confirmation now produces rejected; malformed/uncertain outcomes never become accepted or automatically retry.
- Generic numeric throttle middleware shared keys across unrelated endpoint groups. Named budgets now separate authentication/recovery/catalog/general/support/charger commands. Regression tests confirm catalog traffic does not consume support's allowance and excess support requests still return 429. Browser tests respect a real authentication Retry-After instead of disabling protection.
- Password reset now deletes prior database sessions and rotates remember tokens. Actual email delivery remains untested without a configured transport.
- Fleet revocation/acceptance/assignment re-read membership under transactional locks; PostgreSQL concurrency acceptance is still required.
- Inline frontend scripts/styles were extracted; CSP and framing/content-type/cache headers added. JavaScript displays API/user content through textContent, not injected HTML.

## Blocking or unfinished release gates

1. No independently reviewed staging/production deployment, DNS/TLS, restore drill, performance/fault suite, penetration test, image SBOM/security review or regulatory approval.
2. Missing modules remain listed explicitly in PROGRESS.md: complete multi-connector/staff/KYC workflows, verification/2FA/native auth, CSMS event reconciliation, outbound eMSP integration, finance/tariffs/invoices/payments/refunds/settlements, subscriptions, loyalty/promotions/ads, complete fleet billing and all native apps.
3. No PSP integration tested and no payment captured. Simulation cost estimates are not invoices. Wallet activation needs legal suitability review.
4. No operator contract/environment or physical charger/safety authorization supplied. Those integrations are not hardware/partner tested.
5. Flutter full container unpack exhausted available transient disk; direct official SDK metadata access was denied by egress. No Android/iOS build artifact exists. macOS/Xcode/signing is also required for iOS release work.
6. Git transport succeeds; GitHub REST access returns Forbidden. PR creation/CI inspection and Composer advisory queries await approved network/access changes.
7. Local development CSMS uses loopback-only localDev tokens/security profile 0. These settings must never be promoted as secure staging configuration.

## Reproduction and evidence scope

From /workspace/EV: scripts/setup; scripts/start; scripts/csms-setup; scripts/csms-start; scripts/php artisan test --compact; EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e; npm run test:ocpp. Browser and OCPP tests create synthetic development records and must not target production.

Evidence files belong to the expanded source checkpoint committed alongside this report. Git history identifies its exact revision. New commands/source changes require affected tests to run again. Saving cloud configuration is not publication or a fresh-task restore test. A source checkpoint archive is not a final commercial release.
