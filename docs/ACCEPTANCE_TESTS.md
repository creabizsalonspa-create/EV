# Product Acceptance / Definition of Done

This is a **full-product roadmap**. Acceptance gates are additive; passing the first end-to-end demonstration is **not** project completion.

## Gate A — Engineering foundation
- Repeatable clean checkout, dependency installation, local staging bootstrap, migrations/seeds, CI checks and rollback documentation.
- Pinned/verified software versions and compatible licensing; documented architecture and security model.

## Gate B — End-to-end controlled charging
- Authorized vendor adds and configures a station, charger and connector.
- Customer finds compatible charger, sees true capability/freshness labels, reserves without double-booking and starts/stops simulated charging.
- Customer, vendor and admin see the same session; meter/energy/cost estimate and final invoice agree in test cases.
- Tested OCPP simulation must not be labeled hardware tested.
- Error/timeout/retry/duplicate-webhook/reconnect/reversed command states tested.

## Gate C — All user surfaces
- Customer Web + Android + iOS and Vendor Web + Android + iOS provide the full relevant business features in `PRODUCT_REQUIREMENTS.md`.
- Admin web covers operations, access control, finance and compliance workflows.
- Responsive desktop and mobile layouts, accessible interactions, Arabic RTL and English tested.

## Gate D — Payments, enterprise and engagement
- Test gateway checkout, refund/dispute paths, ledger and settlement reconciliation; proof of no double-charging on retries.
- Loyalty tiers and redemptions, subscriptions, vendor reporting, promotions/ads, fleet cost centers and invoices.
- External production credentials/contract/legal needs tracked; sandbox success cannot be marked production payment readiness.

## Gate E — Actual charger hardware
- Explicit owner authorization, safe approved staging site, verified device model/firmware/protocol, monitored start/stop and session meter readings, fault/reconnect testing.
- Document compatibility matrix, physical safety checks, operator approval, emergency recovery and incident handling.

## Gate F — Actual aggregator partnerships
- At least one contracted/authorized third-party network tested against actual partner environment for agreed operations; pass OCPI/operator API contract tests and station/price/session reconciliation.
- Catalog-only integrations stay labeled discovery-only. No implied access to DEWA/other UAE operators absent agreements.

## Gate G — Release readiness
- Independent security testing/remediation; performance, backups, data protection policies, observability, incident runbooks, CI/CD, penetration testing where appropriate.
- Verified deployment, domains/TLS, production configuration, merchant activation, operational processes and Android/iOS builds and signing.
- Store approval and country-specific regulatory approvals are external milestones and cannot be claimed by code generation alone.

## Evidence format
Every gate records `status`, `commit_sha`, `commands`, `test_logs`, `environment`, `external_dependencies`, and `risks`. If external access is blocked, complete all testable work and clearly document the unmet requirement rather than marking the feature finished.
