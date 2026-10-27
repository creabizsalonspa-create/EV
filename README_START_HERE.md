# Crea-Biz EV Platform — FULL Commercial Codex Briefing Pack

This pack is **project instructions, not application source code**. It replaces the earlier MVP starter pack; do not mix earlier MVP-only instructions into the new repository.

## What to place in the PRIVATE GitHub project
Keep these paths unchanged:

- `/AGENTS.md` — rules automatically discoverable by coding agents working in this repository.
- `/PRODUCT_REQUIREMENTS.md` — full scope (customer web/Android/iOS, vendor web/Android/iOS, super admin web, direct OCPP and OCPI aggregation, and complete commercial features).
- `/CODEX_MASTER_PROMPT.md` — initial task message to paste into Codex.
- `/README_START_HERE.md` — this guide.
- `/docs/ARCHITECTURE.md` — service boundaries and deployment design.
- `/docs/ACCEPTANCE_TESTS.md` — quality/acceptance gates across the complete project.
- `/reference/CreaBiz_UAE_EV_Feasibility_Plan.pdf` — original 7-page feasibility document (private; owner's IP).

## Exact next steps
1. In GitHub, choose an **existing PRIVATE EV project repo** or create one. Do not put this proprietary material into a public repo. Initialize it with a README if empty.
2. Upload/unzip the pack contents **inside the repository**, not just as a ZIP blob, and commit. This enables the coding agent to read `AGENTS.md` and the full requirements.
3. Open `https://chatgpt.com/codex`, create/select the cloud environment and **select that repo**. If the screen says **"No repositories are available to this connection"**, this must be resolved in the Codex GitHub App *repository access* configuration or by creating/authorizing the repository before a cloud coding task can access it. Merely attaching MD files in chat will not repair repository discovery.
4. Paste the contents of `CODEX_MASTER_PROMPT.md` into the Codex coding task. The file need not be manually read by you.
5. Instruct Codex to create a development branch/PR and begin implementing source code, tests and dependency installation, not produce another plan-only response.
6. Ask for proof: commands actually executed, logs, file paths, commit SHA, PR link, and clear statuses for incomplete external integrations. Continue the next task from `docs/PROGRESS.md` until the complete feature matrix is implemented and verified.

## External limits
Codex cannot grant itself GitHub permissions, sign up for charging network contracts, safely control unapproved physical chargers, provide merchant approvals or guarantee app store approval. Real hardware, payments and partner integrations require appropriate access, test environments and human validation. A source ZIP alone is not evidence of production readiness.
