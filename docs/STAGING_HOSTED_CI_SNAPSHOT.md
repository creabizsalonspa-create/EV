# Agent 10 — exact-source Platform validation CI snapshot gate

The platform has four mandatory GitHub Actions jobs in `.github/workflows/ci.yml`: `business-platform`, `finance-postgres`, `connector-postgres`, and `staging-image`. A queued/failed/skipped check is **not** a completed deployment prerequisite. This tool exists to stop incomplete job listings or results from being presented as green.

An authorized operator must independently retrieve, through GitHub's authenticated API, the **current** workflow run JSON from `/repos/creabizsalonspa-create/EV/actions/runs/RUN_ID` and its latest-attempt jobs JSON from `/repos/creabizsalonspa-create/EV/actions/runs/RUN_ID/jobs?per_page=100`. Preserve them in owner-only `0600` regular files inside a `0700` real directory. Ensure the run is for the **exact reviewed source commit**. For example:

```
umask 077
mkdir -p -m 700 artifacts/staging-private-evidence
python3 scripts/staging_ci_evidence.py \
  --source-sha EXACT_REVIEWED_40_HEX_COMMIT \
  --run-json artifacts/staging-private-evidence/official-run.json \
  --jobs-json artifacts/staging-private-evidence/official-jobs.json \
  --output artifacts/staging-private-evidence/ci-review-UNIQUE.json
```

The validator checks workflow numeric ID `397762279`, name `Platform validation`, file `.github/workflows/ci.yml`, official repository and head-repository identity, source SHA, successful completion, latest attempt identity, an exactly complete four-job listing, distinct job IDs, successful job conclusion, and identical run ID/SHA/attempt. It rejects ambiguous pagination, unknown jobs, symlinks, public metadata, duplicate JSON keys and nonstandard values. It creates only a new owner-only JSON digest report and **never** edits a workflow, deploys, updates Docker, accesses secrets, sends requests, or moves money.

**Critical limit:** The local JSON is operator supplied and not cryptographically authenticated by this script. Passing means only *internal consistency of that snapshot*. `github_live_status_independently_confirmed=false`, `staging_deployed=false` and `production_accepted=false` are always recorded. Agent 4 must independently verify the exact run and checks **live at GitHub** immediately before integration. Only then can an authorized operator review backups, private TLS, connected staging and full acceptance; this offline tool must never be used to bypass a CI requirement.

Agent 10's offline staging suite can also be run with one stable command (this does **not** replace GitHub-hosted CI):

```sh
./scripts/staging-offline-verify
```

This runs syntax checks for the release, rollback and private-environment modules, every `tests/staging-*.cjs` synthetic Node test, and the Python CI snapshot regression. The runner does not contact Docker, GitHub, chargers or payment systems. Agent 4 owns `.github/workflows/ci.yml` and should review this entrypoint for the staging-image job after reconciling CI changes in PR #57; this PR does not alter Agent 4's workflow file. A successful **local** suite is not a hosted green check, staging acceptance or production certification.

Test without Docker or live secrets:

```
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p 'staging_ci_evidence_test.py'
```
