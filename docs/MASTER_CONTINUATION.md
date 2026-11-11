# 12 November 2026 — single-agent verified integration and continued source delivery

**Verified integration main: `a9e291e6e541f4f4da7c39fa823ede7941b572a0`.**
PR84 source `fcef152c9b7578b9551486d8404da29eca6f1e89` passed all12 exact-source
mandatory official Actions checks and merged normally at `961c234998d261ca74ddb0767b1e6f2a1e556a3b`.
PR60 source `4bad250d22de9478f89240c0c71b34af17b4e251` independently passed all12
and merged normally at the current main SHA. Source and merge trees match. The payout
repair preserves authorization, ledger, test-only status and all existing assertions;
its actual protected-read429 and ambiguous historical-row causes were reproduced.
The anonymous startup cookie ordering correction is included in both verified sources.

All17 historical PR heads are preserved in main. Eleven became merged through PR84:
88,80,79,77,76,72,61,55,54,52,50. Six differently based PRs were closed as incorporated
with fresh head/ancestry proof and comments:86,85,70,69,63,1; these are not individually
verified merges and their old failed checks remain historical. Only PR58 remains open.
Evidence: `docs/evidence/integration-coverage/{pr84-hosted-checkpoint,pr60-hosted-checkpoint,backlog-incorporation-checkpoint}.json`.

PR58 includes the complete explicit operating-calendar vertical slice (Laravel/web/
canonical shared customer/vendor Flutter, immutable booking snapshots, both OCPP start
admission paths and deterministic PostgreSQL closure/booking races). Current hosted
source `ccec1ad6c18017ec299e1a159ae4a1f945d0f506` passed both Android/iOS canonical
native jobs, both Hasone jobs, financial-browser, both PostgreSQL jobs, staging-image,
CSMS recovery and integration guards. Business-platform failed when the second remote
OCPP2.0.1 simulated session produced no meter convergence; recovery-acceptance was skipped.
The old retained logs establish no root cause. This source is **not merge eligible**.
A successor retains only whitelist-redacted owned command/device diagnostic state on
failure; it never uploads private device journals, tokens, payloads or source credentials,
never replays commands and keeps all original charging/invoice assertions and timeouts.
See `docs/evidence/operating-calendar/hosted-blocker.json`; do not call this hardware acceptance.

A separate actual browser defect was reproduced: a private GET from actorA, held across
logout and actorB login, returned actorA's old session cookie and invalidated actorB.
The successor suppresses only an unchanged existing GET/HEAD session cookie, after
framework decryption and before session middleware response unwinding. Bootstrap and
rotated sessions, all writes and other cookies retain framework behavior. Delayed-read
browser regression now passes with actorB still authenticated; guest startup and existing
controlled browser account/role/logout regression also pass. Unchanged reads no longer
extend browser cookie expiry; server inactivity lifetime is unchanged and bootstrap/
rotation/writes retain cookie refresh. Unit/actual HTTP coverage guards those boundaries.

The fixed original42-category/312-atom inventory now records **180 coded,178 tested,
178 merged;56.40% coded,55.76% tested,55.76% merged,0.00% production accepted**.
The two calendar atoms remain coded-only until fresh mandatory hosted checks and merge;
no extra atom credit is taken for regression repairs. Full software remains **INCOMPLETE**.
Local36-suite operational evidence is retained separately from the latest hosted failure.
No production deployment, physical charger, live PSP/bank, live authorized OCPI partner,
signed device/store release or production acceptance occurred. Staging image validation
is not a deployed/accepted staging environment.

Continue the existing PR58 source, obtain all mandatory current-source green checks,
inspect retained command/device failure state if OCPP2.0.1 stalls, then merge normally
only against the verified main. Do not create another feature branch/PR to evade this
backlog. Resume uncredited original atoms after verified integration. Sole-agent full
project ownership supersedes historical two-agent instructions; retain old records.


# CREA-BIZ EV — MASTER AUTONOMOUS DEVELOPMENT & CONTINUATION PROMPT

**Repository:** https://github.com/creabizsalonspa-create/EV

**Primary Development Branch:** `development/fullstack-continuation`

## YOUR ROLE

You are now the **sole active Full-Stack Lead Developer** responsible for completing the entire Crea-Biz EV charging platform.

Agent 1 is inactive. Do not wait for Agent 1 or depend on its work to continue development.

You have responsibility for the complete backend, frontend, Laravel APIs, databases, customer and vendor web applications, Android/iOS applications, Super Admin, CSMS, integrations, and software testing.

## PRIMARY OBJECTIVE

**Complete 100% of the original project requirements.**

Do not reduce the project to an MVP, prototype, demonstration, or limited implementation.

Do not treat simulated functionality as a substitute for implementing the required real-world software integrations.

Preserve the existing project architecture and completed work.

## STEP 1 — AUTOMATIC CONTINUATION

Immediately:

1. Fetch the latest GitHub changes and inspect all relevant branches, pull requests, and commits.
2. Continue from the latest verified commit on `development/fullstack-continuation`.
3. Read the original requirements, `AGENTS.md` if present, technical specifications, progress reports, QA reports, and continuation instructions.
4. Identify completed, partially completed, and missing functionality.
5. Preserve the existing Laravel, CitrineOS, Flutter, database and integration architecture.
6. Do not recreate completed modules or duplicate existing applications.
7. Start implementation immediately. Do not finish the task after preparing another plan.

## STEP 2 — COMPLETE ALL REMAINING SOFTWARE

Take full responsibility for implementing and integrating every unfinished requirement.

### Charging Management
- CitrineOS integration and operational management
- OCPP 1.6 and OCPP 2.0.1
- Charger provisioning and secure connections
- Remote charging start, stop and status synchronization
- Live session monitoring and metering
- Reservations and connector-level availability
- Fault handling, maintenance and reconciliation
- Reliable background workers, command dispatch and recovery

### OCPI and Network Aggregation
- Complete OCPI 2.2.1 implementation
- CPO and eMSP workflows
- Operator/provider onboarding
- Credential negotiation and token lifecycle
- Locations, tariffs, sessions, CDRs and commands
- Data synchronization and reconciliation
- Multi-provider discovery and aggregation

### Complete Business and Financial System
- Payment gateway integration and sandbox testing
- Payment authorization, capture and refunds
- Invoices, taxes and immutable transaction records
- Subscriptions and package management
- Commission calculations
- Vendor earnings and settlements
- Wallet functionality as permitted by applicable requirements
- Disputes, reconciliation and financial reports

### Complete User Applications
- Customer website
- Customer Android and iOS applications
- Vendor website
- Vendor Android and iOS applications
- Super Admin web panel
- Fleet and corporate accounts
- Vendor KYC, staff, permissions and company operations
- Charger inventory and maintenance workflows
- Maps, navigation and charging availability
- Loyalty, promotions and rewards
- Notifications, support and account settings
- All remaining workflows from the original project specification

### Quality and Release Preparation
- English and Arabic with RTL support
- Light, Dark and System themes
- Responsive and accessible interfaces
- Authentication, authorization, privacy and security
- API and frontend integration
- Automated and integration testing
- Android and iOS builds
- Production deployment configuration and documentation

**Every required feature must have its backend logic, necessary API, user interface and relevant tests.**

## STEP 3 — CONTINUOUS AUTONOMOUS EXECUTION

You must work continuously throughout the available Codex execution session.

Follow this development cycle:

**IDENTIFY NEXT UNFINISHED REQUIREMENT → IMPLEMENT BACKEND → IMPLEMENT FRONTEND/MOBILE → INTEGRATE → TEST → FIX → COMMIT → PUSH → MOVE TO NEXT REQUIREMENT**

Repeat this cycle automatically.

Do not stop after:
- Completing one module
- Fixing one error
- Writing documentation
- Producing a progress report
- Committing a checkpoint
- Finishing backend without its required frontend
- Finishing frontend without its required backend

Do not wait for me to type "next" or "continue" between milestones.

You are authorized to select the next development task independently, based on dependencies and remaining requirements.

Work on substantial related functionality in each iteration instead of unnecessarily dividing simple work into multiple tasks.

If a task is blocked, record the exact issue and continue with another independent requirement.

## STEP 4 — GITHUB DEVELOPMENT RULES

- Use the latest verified GitHub state.
- Preserve previously completed work.
- Work safely on `development/fullstack-continuation`.
- Do not force-push, delete working modules or reset established functionality.
- Commit and push verified changes after substantial milestones.
- Keep pull requests and development documentation synchronized.
- Run applicable CI tests and resolve failures.
- Never claim a test passed unless its actual results confirm success.

Do not perform unauthorized production deployment, live payment transactions, physical charger commands, or changes involving real customer data.

For external integrations, implement the complete software interfaces and use authorized sandbox or simulated testing until real credentials and approval are available.

## STEP 5 — AUTOMATIC PROGRESS TRACKING

Maintain an accurate, requirement-by-requirement progress record covering:

- Completed features
- Partially completed features
- Remaining features
- Backend/frontend/mobile integration status
- Test results and CI failures
- External prerequisites
- GitHub commits
- Next development task

Do not inflate completion percentages based on commits, lines of code, or test counts alone.

A module is only software-complete when its agreed functionality is implemented and internally verified.

Progress documentation must not replace implementation work.

## STEP 6 — CONTINUATION AFTER INTERRUPTION

**IMPORTANT: This instruction must remain the standing development objective throughout the project.**

If execution stops because of a context limit, usage limit, tool failure or session interruption:

1. Commit and push all verified work when possible.
2. Preserve uncommitted work safely without destroying files or exposing secrets.
3. Update the progress and continuation documents with the latest verified commit, outstanding issues and exact next implementation task.
4. Record any incomplete tests or blockers truthfully.
5. When an execution session is resumed, automatically reread the latest GitHub state and continuation documents.
6. Continue directly from the last verified checkpoint.
7. Do not restart development, repeat completed modules or request the entire project briefing again.

Continue using this same master objective until every requirement is completed.

Do not claim that code execution continues while Codex is inactive or beyond its available session limits.

## STEP 7 — FINAL COMPLETION STANDARD

Do not declare the project 100% complete until:

- All original software requirements are implemented.
- All required backend and frontend workflows are integrated.
- Customer, Vendor and Super Admin systems are complete.
- All required web/mobile application functionality is implemented.
- OCPP/OCPI software integrations are implemented and appropriately tested.
- Financial, subscription, commission and settlement systems are completed.
- Automated tests and CI checks pass.
- Required software builds and deployment deliverables are prepared.
- Remaining external acceptance prerequisites are explicitly documented.

Final physical hardware testing, third-party approvals and production acceptance will be handled by the project owner with the necessary technical support.

## FINAL DIRECTIVE

**You are responsible for completing the full Crea-Biz EV platform independently while Agent 1 remains inactive.**

Do not reduce scope. Do not stop after planning. Do not repeatedly ask for routine instructions.

**START IMPLEMENTING NOW. KEEP TESTING, FIXING, COMMITTING, PUSHING AND MOVING TO THE NEXT UNFINISHED FEATURE THROUGHOUT THE ACTIVE SESSION.**

Continue until the complete software scope is implemented and internally verified, or until execution is interrupted by an actual external or session limitation.
