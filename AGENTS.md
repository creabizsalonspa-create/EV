# Agent Instructions — Crea-Biz EV Charging Platform (Full Commercial Product)

## Objective and source of truth
Build the **complete commercial UAE EV Charging Aggregator and Direct Charger Management System**, with architecture suitable for GCC and wider international expansion. **This is not an MVP-only assignment.** Start with a working vertical slice but continue implementing the full requirements as independent, tested milestones. Never mark the project complete because a demo works.

Read, in this order: `PRODUCT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/ACCEPTANCE_TESTS.md`, `reference/CreaBiz_UAE_EV_Feasibility_Plan.pdf`. This AGENTS file and product requirements reflect the owner's newer decisions, which supersede any earlier MVP-only statements in prior material.

## Product surfaces (all required)
- **Customer**: responsive web and Flutter mobile (Android, iOS).
- **Vendor / charging operator**: responsive web and Flutter mobile (Android, iOS).
- **Super admin**: web interface. Do not create admin mobile without authorization.
- Consistent core workflows and records across customer, vendor and admin.

## Technical direction
- Evaluate and pin a compatible, license-compliant **CitrineOS** release as the primary CSMS for OCPP; do not reimplement a charger protocol server without demonstrated need. Evaluate OCPP 1.6 and 2.0.1 capability and charger-specific limitations.
- Keep CitrineOS as a separately deployable service with its own datastore and documented APIs/events; do not directly merge its internal database with business tables.
- **Laravel** owns identity, tenancy, vendors, bookings, normalized station catalog, pricing, loyalty, invoicing, payments, subscription billing, payouts, fleet accounts, auditing and business workflows.
- **OCPI eMSP/CPO interoperability** requires a separate assessment; a CPO-only OCPI component is not sufficient for unrestricted third-party aggregation. Create conformant adapters to authorized networks with contract tests. Never invent partners or integration access.
- Use **Flutter** for Android and iOS with shared packages, and evaluate Flutter Web versus a reusable conventional web interface for responsive, performant desktop workflows; document the decision before implementation.
- PostgreSQL for the chosen CSMS where required; use a separately managed Laravel SQL database. Redis/queues and event processing if appropriate; secure internal service authentication.
- Only reuse CodeCanyon software if a license and actual auditable source are available; validate commercial use, code quality, update posture, dependencies and compatibility. Mixing packages is done at API/component boundaries, not by blind code copy.
- Keep regions, currencies, languages, time zones, tax configurations and country-specific integrations configurable from day one.

## Execution protocol — take initiative
1. Inspect current repository, branch, code, tools, available API access and existing test results before modifying anything.
2. Create/update `docs/PROGRESS.md` (per-module status: Not started / In progress / Verified / Blocked; evidence, commit, next action). Create `docs/DECISIONS.md` for architecture and licensing decisions.
3. Prioritize foundational end-to-end functionality, then implement **every required module** in `PRODUCT_REQUIREMENTS.md` in dependency order. Do not replace full scope with an MVP scope.
4. Write production-quality code, migrations, API contracts, UI, authorization checks, integration adapters, automated tests and deployment scripts. Run every available test/build; fix failures. Report unrun tests clearly.
5. Commit related code to a safe feature branch. Push and open/update PR when the environment supports it. Never force-push or alter unrelated production branches.
6. After each milestone, update docs with actual tested results, remaining work and the precise next milestone; continue as supported within task/session limits. Do not claim indefinite unattended execution or completion without evidence.
7. For a Git push problem, inspect `git status`, branches, remote, upstream, authorization and exact stderr; fix ordinary workspace issues automatically. If GitHub app access or a required repository selection is missing, report the external permission limitation precisely. Do **not** ask for reconnecting blindly and do not attempt to bypass GitHub authorization.
8. Only ask the owner for truly external blockers (required access, operator agreements, payment merchant credentials, safety approval, physical charger, signing credentials, regulated service choices). Otherwise make reversible, documented engineering choices and proceed.

## Non-negotiable safety, legal and quality rules
- Never send commands to actual physical chargers or capture real payments without explicit authorization and an appropriate staging safety procedure.
- Simulated, sandbox, demo, unverified, discovery-only and real statuses must always be distinct in code and UI.
- Apply least privilege, tenant separation, CSMS command authorization, idempotency, rate limiting, input validation, secrets handling, auditability, reliable event processing and safe payment reconciliation.
- No hardcoded secrets. Commit only `.env.example`, never `.env` or credentials. Protect end-user data and plan for UAE privacy obligations and relevant regional requirements.
- Do not misrepresent OCPI access, government partnerships, network coverage, compliance certifications, payment production status, platform store approvals, or passed tests.
- Never overwrite, delete or deploy an existing production environment without explicit direction.
- A production release requires security review, real device testing, payment sign-off, integration/hardware acceptance, app signing/store steps and operational readiness; source code alone is not proof of all these.

## Completion evidence
For each feature provide an implementation pointer and tests. For each deployed environment provide setup and rollback. For each external dependency provide its exact readiness level. The final overall handover includes source, build artifacts where available, migrations, docs, test reports, API documentation, infrastructure manifests, a risk register and unresolved external requirements.

## Standing continuation objective

The owner’s master instructions are retained in [docs/MASTER_CONTINUATION.md](docs/MASTER_CONTINUATION.md). Continue the full backend/web/mobile/admin scope on `development/fullstack-continuation` independently; preserve existing work, verify and push substantial checkpoints, and move to the next unfinished requirement within the active session. Simulated acceptance does not replace real integration software, and no background execution or full completion may be claimed without evidence. Read the latest progress and QA checkpoint before resuming.
