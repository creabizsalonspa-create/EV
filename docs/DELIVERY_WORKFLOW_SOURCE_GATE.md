# Agent 10 — workflow-bound mobile demo delivery gate

The generic delivery bundler checks checksums and ZIP structure. The new **workflow-bound gate** additionally requires that the Android and iOS simulator artifacts both belong to the *same successful GitHub Actions workflow*, from the same exact source revision in the authorized EV repository. It is a read-only/offline verifier until it copies files into a new private bundle. It never creates accounts, contacts a real charger, bills a customer, changes CI, signs an application or deploys software.

## 1. Obtain trusted GitHub metadata

Review the successful `Hasone customer development builds` workflow and its two artifact IDs via GitHub, then save the run and artifact listing to a private directory. For example, with an authorized GitHub CLI session:

```sh
umask 077
mkdir -p -m 700 artifacts/delivery/workflow-evidence
gh api repos/creabizsalonspa-create/EV/actions/runs/42348377299 \
  > artifacts/delivery/workflow-evidence/run.json
gh api 'repos/creabizsalonspa-create/EV/actions/runs/42348377299/artifacts?per_page=100' \
  > artifacts/delivery/workflow-evidence/artifacts.json
```

The run must report `status=completed`, `conclusion=success`, the exact expected workflow name, an authorized first-party repository and head repository, and `head_sha=7e1b225e5add58da8a87e64d52292b76c5e99af5`. Each artifact must report that same run ID, branch, repository IDs and source SHA, must be unexpired, and must contain the actual GitHub-published SHA-256 digest. Archived/downloaded bytes must match their exact published size and digest. A manual source label alone cannot override any of these conditions.

## 2. Offline verified import — real successful development reference build

After downloading the *same* two wrapper ZIPs (run 42348377299, artifact IDs 13569331617 and 13565252105), use:

```sh
python3 scripts/delivery_workflow_gate.py \
  --output artifacts/delivery/hasone-7e1b225-FRESH-RUN \
  --source-sha 7e1b225e5add58da8a87e64d52292b76c5e99af5 \
  --run-id 42348377299 \
  --run-json artifacts/delivery/workflow-evidence/run.json \
  --artifacts-json artifacts/delivery/workflow-evidence/artifacts.json \
  --android-wrapper /private/path/hasone-android-7e1b225.zip \
  --ios-wrapper /private/path/hasone-ios-simulator-7e1b225.zip
```

The gate checks offline metadata consistency and both outer download digests/byte lengths **before** calling the existing safe Android/iOS importer. The importer verifies wrapper structure, nested APK/ZIP CRC and TAR path safety, then creates a **new**, owner-only delivery folder. A pre-existing folder is never overwritten.

Once the bundle is produced, independently verify it with:

```sh
python3 scripts/delivery_bundle.py verify \
  --bundle artifacts/delivery/hasone-7e1b225-FRESH-RUN \
  --expected-source-sha 7e1b225e5add58da8a87e64d52292b76c5e99af5
```

If either artifact comes from another GitHub run, has expired, comes from a fork, has a changed head source, no successful CI conclusion, an unsupported trigger, or bytes differing from its own published artifact metadata, **the gate fails** and creates no customer bundle.

## Tests and remaining acceptance

```sh
python3 -m py_compile scripts/delivery_bundle.py scripts/delivery_artifact_import.py scripts/delivery_workflow_gate.py
python3 -m unittest discover -s tests -p 'delivery_*_test.py' -v
```

Exact GitHub source tested on an isolated authorized host with Python 3.9.25: **34/34 tests passed**, zero failures/skips. The result includes full in-memory/temporary Android APK and iOS tar.gz wrapper-to-bundle integration, wrong source ID, mixed runs, expired artifacts, untrusted fork, altered download bytes, wrong artifact sizes and missing workflow conclusions. Tested files' Git object hashes matched GitHub blobs. This remains development artifact verification, **not** store signing or a production-delivery claim.

**Important limitations:** local JSON metadata could be tampered with before inspection; the gate does not cryptographically verify GitHub's API transport or build attestations. Operators must obtain metadata from the authenticated GitHub API and separately verify that artifact download IDs came from that run. A successful developer-build workflow does not prove authenticated HAS.ONE journeys, that an Android APK is store-signed, that an iOS simulator app runs on physical devices, or that Laravel/CitrineOS, Teltonika hardware, real PSP settlement and third-party OCPI networks are production-accepted. Agent 6 and Agent 9 own these acceptance domains; Agent 4 must verify exact-head CI before integrating this PR.
