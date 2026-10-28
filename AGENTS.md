## Latest owner directive — temporary full-project Agent2 ownership, 28 October 2026

The newly uploaded FULL PROJECT OWNERSHIP command supersedes the preceding parallel split: Agent1 is unavailable; Agent2 temporarily owns all remaining financial/commercial and EV/non-financial software. Reuse canonical finance services and preserve verified work. Coordinate before simultaneous shared-file edits if Agent1 returns; ownership changes only on an owner directive. Earlier split/sole-owner records remain historical.

Verified integrated main is6388642827781d4572a8fb91a83f392b26d461c4. PR12 recurring mandates/collections and PR13 charger admission lifecycle are merged. PR14 source1b72354 passed backend/PostgreSQL/Android but failed a premature native PDF-ready assertion after successful company/upload flow. Corrected source5b258f1 awaits actual native load with a bounded30-second/fail-fast error check; exact-source iOS rerun is required before merge. PR15 source2909566 adds cross-platform nearby search; its local evidence is in docs/evidence/catalog-nearby/local-checkpoint.json and hosted checks are pending. Do not rebuild these modules, equate coordinate search with an interactive map, or claim physical/merchant/partner/store acceptance.

# Agent Instructions — Crea-Biz EV Charging Platform (Full Commercial Product)

## Latest owner directive — restored parallel ownership, 28 October 2026 (Dubai)

Agent1 exclusively owns financial/commercial business rules, subscriptions, payouts/settlements, refunds/disputes, enterprise billing, loyalty economics, advertising billing and their backend/web/Admin interfaces. Agent2 owns OCPP/OCPI, EV infrastructure, maps, reservations, customer/vendor Flutter, non-financial Admin, security/deployment integration and the main integration branch. Consume verified finance APIs in Flutter; do not recreate financial logic. Both accounts work on separate branches and integrate tested PRs without force-pushing. Earlier sole-owner instructions below are historical and superseded by this owner update.

Active Agent2 source: `development/catalog-nearby` layered on native-vendor PR14 (source1b72354) from verified main63886428. Catalog shared paths: PlatformController::stations, platform.js/blade, native api.dart/catalog.dart/strings.dart and CI/progress documents. Finance ownership is unchanged. No financial controller/service/migration/web/Admin rule is modified by this slice. Shared paths to coordinate before editing: `apps/mobile/lib/api.dart`, `apps/mobile/lib/main.dart`, mobile dependencies/CI, `scripts/start`, `infra/staging/Dockerfile`, shared progress/API documentation. Non-financial VendorOps controllers/routes/models and tests are Agent2's current work. No new open finance PR was visible on the ownership-update fetch; continue independent EV work while monitoring for verified finance delivery. PR12/13 are already integrated; preserve their history.


## Historical sole-owner directive — superseded by restored parallel ownership

Agent 2 is the sole active owner of all remaining Crea-Biz EV development, including financial and non-financial software, until the project owner explicitly changes the assignment. This supersedes previous exclusive finance ownership; the records below remain historical. Continue the canonical financial architecture, preserve both agents’ verified work and merge tested changes through pull requests. Full original scope remains required; simulator/contract tests do not establish hardware, merchant, roaming-partner or store acceptance.


## Historical restored parallel ownership — superseded by the final directive above

The owner restores two active workstreams. Agent1 exclusively owns financial/commercial business logic, controllers, accounting services, financial migrations and financial web/Admin workflows. Agent2 owns EV/OCPP/OCPI, all customer/vendor web and Flutter integration, non-financial Admin, operations/security, engagement and fleets, consuming verified finance APIs without modifying their source of truth. Agent2 owns development/fullstack-continuation and integration QA. Agent1 works on a separate finance branch; inspect its new PR and coordinate shared-file edits before integration. Merge only tested changes through PRs; preserve both branches and never force-push/reset another account's work. Earlier sole-owner instructions below are historical and superseded by this explicit reassignment. Shared EV route/client edits in this session are additive; finance routes/controllers/services/migrations are excluded. No new finance PR was visible on the initial fetch; do not wait for it to continue EV work.

Continue from6d0e33c without rebuilding canonical mapping, parallel reservations, arrival/expiry or applied-source command reconciliation. Immediate new work: secure charger admission/provisioning and no-source-evidence operations. No hardware/live PSP/partner/store acceptance is inferred. Full original requirements remain in force.


Resume from `docs/ACTIVE_DEVELOPMENT_CHECKPOINT.md` and the actual fetched GitHub head; verify its exact source/CI before continuing. Historical ownership records below do not override the active sole-owner directive.

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

## Current owner directive — sole active Agent 2

The newer [MASTER_AGENT2_CONTINUATION.md](docs/MASTER_AGENT2_CONTINUATION.md) supersedes the historical exclusive workstream split while Agent 1 is unavailable. Agent 2 owns the complete remaining software, including financial/commercial work, until the owner explicitly reassigns it. Preserve and extend merged finance rather than creating another implementation. First finish canonical charging and safe multi-resource reservations, then continue the complete PRD through tested, pushed milestones. Retain all truth/safety/release gates.
