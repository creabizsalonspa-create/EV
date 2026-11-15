# Agent 10 — Integration post-merge CI evidence gate

**Scope:** An optional read-only review gate that distinguishes clean, exact-source PR CI results from independent GitHub **push-event CI on the actual merged integration commit**. Neither result is live operational or signed-release approval.

The existing `agent10-ci-source-gate.cjs` verifies exact-source Platform + Native metadata, including PR checks. The stricter `agent10-postmerge-gate.cjs` additionally requires an observed GitHub branch `development/fullstack-continuation` at the exact requested 40-hex source SHA, with successful reviewed `push`-event Platform and Native runs on that same integration branch. It rejects passing PR/dispatch runs, stale or mismatched source SHAs, incomplete jobs, missing recovery acceptance, skipped mandatory native builds and nonboolean claims of release approval.

## Read-only operator evidence collection

Use an authorized GitHub read-only CLI connection and a **new private, owner-only directory**. First fetch the *push-event* Platform/Native run JSON and complete run-job listings for the exact merged SHA and compose `snapshot.json` as documented in [Agent 10 CI source gate](AGENT10_CI_SOURCE_GATE_2026-11-16.md). Do not reuse PR-head run IDs or paste GitHub tokens into documents or commands.

```sh
set -eu
umask 077
REPO='creabizsalonspa-create/EV'
SOURCE_SHA='REPLACE_WITH_EXACT_40_CHAR_MERGED_SHA'
gh api "repos/$REPO/branches/development/fullstack-continuation" > branch.json
jq -n --slurpfile branch branch.json --slurpfile ci snapshot.json \
  '{schema:"ev-agent10-integrated-evidence-v1",branch:$branch[0],ci:$ci[0]}' > integrated.json
chmod 600 integrated.json
node /path/to/reviewed/repo/scripts/agent10-postmerge-gate.cjs integrated.json "$SOURCE_SHA"
```

Output `integrated_ci_metadata_consistent=true` means only that the **supplied authenticated GitHub API metadata** is internally consistent with successful exact-source post-merge CI. Source authenticity, CI reproducibility, private staging, physical OCPP charging, signed artifacts, PSP/bank/legal approvals, independent security/accessibility/load testing and live customer acceptance remain entirely separate. The gate makes no network requests, changes no files and always reports `customer_delivery_ready=false` and `production_ready=false`. Recheck the live branch tip after collecting evidence to guard against a newer integration merge.

## Confirmed historical post-merge failure

At actual integrated SHA `48f136d750689b3fccb93727748006428a3912bb`, [Platform push run 43761118476](https://github.com/creabizsalonspa-create/EV/actions/runs/43761118476) initially failed the connector-fleet EN/AR browser test and skipped recovery acceptance. [Native push run 43761118480](https://github.com/creabizsalonspa-create/EV/actions/runs/43761118480) initially failed macOS while Ubuntu succeeded. Independent failed-job retries were requested, **not presumed successful**; check their live final outcomes. Successful pre-merge checks for PR #131 do not overrule red post-merge workflows.

Only Agent 4 may approve integration and merge. No original 312-atom stage or production acceptance credit is earned solely through passing this metadata gate.
