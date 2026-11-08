# Agent 10 — shared EV customer/vendor Android+iOS developer artifact integrity

The native EV client in apps/mobile is a **different build product** from apps/hasone-customer. Both are development-only until Agent 6 provides signed, installed, compatible release packages and Agent 9 validates real device/customer/vendor workflows.

## Exact reviewed GitHub workflow

The native shared Flutter application is built by GitHub Actions:
- Workflow name: Native client validation
- Workflow numeric ID: 397853920
- Workflow file: .github/workflows/mobile.yml
- Android artifact: creabiz-android-debug
- iOS simulator artifact: creabiz-ios-simulator
- Android ZIP wrapper's sole member: app-debug.apk
- iOS ZIP wrapper's sole member: creabiz-ios-simulator.tar.gz

These identities are **different from** Hasone customer development builds, workflow ID 410027022, file .github/workflows/hasone-customer.yml. The verifier will not silently swap or combine the two workflows.

**Actual developer build origin:** The shared-native workflow compiles its Android debug and unsigned iOS simulator builds with EV_API_BASE_URL=http://127.0.0.1:8000 and EV_LOCAL_DEMO=true. These binaries therefore target the **authorized local demo** through loopback/forwarding, not an independently accepted private HTTPS staging domain on port 8443. They must not be represented as preconfigured staging builds. Agent 6 owns environment-specific connected native builds and signed releases.

## Strict offline, developer-only handoff

Download the two GitHub Actions ZIP artifacts and capture the workflow run JSON and workflow-run artifacts listing JSON through a trusted GitHub API session. Use one successful run from the reviewed EV repository and a single exact 40-hex commit. The two JSON metadata snapshots must be non-symlink **regular files, at most 4 MiB, owner-only 0600**, in an owner-only real directory. The parser rejects duplicate keys, non-standard NaN/Infinity values, FIFO/special files and file swaps before confirming workflow identity. Then run:

    umask 077
    mkdir -p -m 700 artifacts/delivery
    PYTHONDONTWRITEBYTECODE=1 python3 scripts/delivery_shared_native_gate.py \
      --output artifacts/delivery/shared-native-UNIQUE \
      --source-sha EXACT_VERIFIED_WORKFLOW_HEAD_SHA \
      --run-id EXACT_GITHUB_ACTIONS_RUN_ID \
      --run-json /private/github-workflow-run.json \
      --artifacts-json /private/github-artifact-list.json \
      --android-wrapper /private/creabiz-android-debug.zip \
      --ios-wrapper /private/creabiz-ios-simulator.zip

This command:
- Requires a completed/successful Native client validation run from creabizsalonspa-create/EV, workflow ID 397853920, path .github/workflows/mobile.yml, approved trigger and matching source SHA/repository identities.
- Requires both nonexpired, unique artifacts in that same run, matching their published GitHub artifact wrapper SHA-256 digests and byte lengths.
- Requires the exact Android APK and iOS simulator TAR members. The two downloaded wrappers are read from no-follow, nonblocking regular-file descriptors and copied into owner-only temporary ZIP snapshots **while their published SHA-256 digests are checked**. Extraction uses only the frozen verified snapshots, not the original downloader paths, preventing a checksum-to-extraction file-swap race. TAR paths and types are checked before conversion; the developer APK and resulting simulator ZIP receive full member/CRC verification.
- Creates a new **owner-only, non-overwriting** development bundle with a source SHA, artifact hashes and private workflow identity evidence. It does not run a Flutter build, deploy, connect to APIs, extract into app directories, issue device commands or access credentials.

The metadata snapshot is self-supplied to an offline script. It is **not cryptographically authenticated by the verifier**; acquire it through trusted operator tooling and retain official Actions logs. A successful bundle is **not proof of reproducible build, signed Android/iOS device release, installed vendor workflows, private staging connectivity or production acceptance**.

It can be compared to a recent PR #66 source-linked staging acceptance report using scripts/delivery_source_alignment.py. A matching Git revision permits only DEVELOPMENT_ONLY_SOURCE_ALIGNMENT_VERIFIED; the resulting JSON still reports backend connection, native authenticated workflows, real devices, signing, live charging and payments as unverified.

## Read-only historical source-check evidence — 1 November 2026 run

A previous completed Native client validation run, **41362370710**, used source commit **c3af6567afd2d3abcbe89ebcffebd38e55ea0318**. Both GitHub jobs were marked successful. The official Actions ZIP metadata/logs declared:

| Role | Artifact ID | GitHub ZIP SHA-256 |
| --- | --- | --- |
| Android debug | 13105979447 | 624ad0e2c09a7b8156f2b76b219b1bddcd4edda986d71c786441714b812dc9df |
| iOS simulator | 13106285302 | e00a5d4ac86cbe78d65d38b16ccdc60ddd98f4efbe6ac5b933322e111beb1233 |

Agent 10 downloaded these exact two wrappers from GitHub; both wrapper SHA-256 values matched those published in the official run/artifact metadata, and ZIP CRC checks passed. The APK contained AndroidManifest.xml and 15 DEX members; the simulator TAR contained 206 entries and an .app/Info.plist with no absolute/traversal paths. No emulator/device installation was performed.

**Unresolved reference mismatch — do not silently overwrite:** The extracted app-debug.apk was SHA-256 **04b479bc6d3eb6608bf786f947abf3a9b4f96a325affbe3f24aff2fcad50f0ee**; the extracted creabiz-ios-simulator.tar.gz was **dfb98560805410b2d48258aaa4af7f75f27ab181ffd61af7b67d66e53ff896ce**. The separate repository report docs/evidence/hosted-checkout/hosted-checkpoint.json records historical inner hashes **858aed3d48843b6160ddccb923284f75808c606edc986b122d15ec82da9b002f** (Android) and **35317718de21cfa93613a0b53faca2a4e40e86c2bf463eb1c5527f0c1c0d36de** (iOS). These two sources are not shown to identify the same artifact IDs; they may describe separate local/runner builds. The hash mismatch is **not evidence of GitHub wrapper corruption**, but the reference-record equivalence has NOT been established. Require Agent 4/Agent 6 to reconcile run and inner artifact identity before presenting these historical files as a customer-approved handoff.

## Test command

    PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p 'delivery_*test.py'

Negative tests intentionally print FAIL while checking that malformed/mixed/wrong-workflow artifacts are rejected. The unit test PASS count does not grant product or staging acceptance.
