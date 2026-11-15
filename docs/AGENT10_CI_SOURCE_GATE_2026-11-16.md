# Agent 10 — Exact-source Platform + Native CI metadata handoff

**Date:** 16 November 2026. **Scope:** offline, read-only cross-workflow evidence comparison. **Status:** NOT A CUSTOMER RELEASE APPROVAL.

This tool rejects stale, incomplete or mixed GitHub Actions metadata. It requires **both** reviewed GitHub workflows at the **same exact source SHA**:

- Platform validation — workflow ID `397762279`, path `.github/workflows/ci.yml`, and six successful jobs: `business-platform`, `finance-postgres`, `connector-postgres`, `staging-image`, `csms-recovery`, `recovery-acceptance`.
- Native client validation — workflow ID `397853920`, path `.github/workflows/mobile.yml`, and two successful jobs: `native (ubuntu-latest)` and `native (macos-15)`.

It additionally verifies success for the exact core browser/OCPP/OCPI/Laravel recovery steps, the Linux **debug** APK build step, and the macOS **unsigned simulator** build/UI integration steps. A skipped mandatory job, missing step, duplicate or unexpected job, wrong event, stale source SHA, mixed branch/repository, pending run, or failed status is rejected.

## Operator-only, read-only evidence collection

Use a previously authorized GitHub read-only CLI connection. Do **not** paste GitHub tokens into issue comments, repository files or the command line. Work in a **new private directory**, outside the public repo. Use complete, same-source GitHub API run/job responses; never synthesize successful statuses or drop failed jobs.

```sh
set -eu
umask 077
mkdir -p -m 700 /private/ev-agent10-ci
cd /private/ev-agent10-ci

REPO='creabizsalonspa-create/EV'
PLATFORM_RUN='REPLACE_WITH_EXACT_SOURCE_PLATFORM_RUN_ID'
NATIVE_RUN='REPLACE_WITH_EXACT_SOURCE_NATIVE_RUN_ID'
SOURCE_SHA='REPLACE_WITH_40_LOWERCASE_HEX_GIT_COMMIT'

gh api "repos/$REPO/actions/runs/$PLATFORM_RUN" > platform-run.json
gh api "repos/$REPO/actions/runs/$PLATFORM_RUN/jobs?per_page=100" > platform-jobs.json
gh api "repos/$REPO/actions/runs/$NATIVE_RUN" > native-run.json
gh api "repos/$REPO/actions/runs/$NATIVE_RUN/jobs?per_page=100" > native-jobs.json

jq -n \
  --slurpfile pr platform-run.json \
  --slurpfile pj platform-jobs.json \
  --slurpfile nr native-run.json \
  --slurpfile nj native-jobs.json \
  '{
    schema:"ev-agent10-cross-workflow-ci-snapshot-v1",
    platform:{run:$pr[0],jobs:$pj[0].jobs},
    native:{run:$nr[0],jobs:$nj[0].jobs}
  }' > snapshot.json
chmod 600 snapshot.json
node /path/to/verified/repo/scripts/agent10-ci-source-gate.cjs snapshot.json "$SOURCE_SHA"
```

The CLI reports `exact_source_workflow_metadata_consistent: true` **only if** all known source/workflow/job/required-step checks pass. That statement means the supplied metadata is internally consistent with the reviewed workflows; it **does not** authenticate the bytes from GitHub, prove CI reproducibility, inspect APK signing, or establish runtime acceptance. The operator must independently establish GitHub API authenticity and source identity; never treat a user-supplied JSON file as authoritative.

The tool imports the same bounded, no-symlink, single-descriptor JSON reader used by Agent 10's requirement coverage auditor. It emits no credentials, makes no API calls and does not modify files or services.

## Developer verification

```sh
node --check scripts/agent10-ci-source-gate.cjs
node --test tests/staging-agent10-ci-source-gate.cjs
```

The test file matches the existing `tests/staging-*.cjs` glob in the repository's staging-offline verification workflow and includes negative tests for mismatched workflows, incomplete jobs, skipped mandatory steps and premature production claims.

## Explicitly unverified gates

Even a successful result sets these values to `false`: `signed_android_release_verified`, `signed_ios_release_verified`, `authenticated_staging_verified`, `external_payment_hardware_partner_verified`, `customer_delivery_ready`, and `production_ready`.

Android debug and iOS simulator binaries are **development-only**, often bound to a local demo endpoint; they do not meet signed release or actual staging connectivity requirements. Legal/tax/PSP/bank, physical OCPP, contracted OCPI, private staging and backup restore, load/security/accessibility audits, real-device native acceptance and store approvals require **separate authorized evidence**.

## Known historical baseline (not a current release)

The previously verified integration commit `28701c08033b06c92515601f983f3b85bddb84ae` had successful Platform validation run `43740451479` and Native client validation run `43740451545`; the newer integration branch has advanced since. These historical runs must **not** be passed off as proving the newest source commit. At the 16 November QA checkpoint, production accepted software remains **0%**. Agent 4 alone controls integration and any release approvals remain with authorized owners.
