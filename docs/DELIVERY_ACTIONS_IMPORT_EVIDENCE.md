# Agent 10 — verified private HAS.ONE development demo artifacts (7 November 2026)

## Source and official GitHub Actions evidence

Repository: `creabizsalonspa-create/EV`  
Successful workflow: `Hasone customer development builds`, run [42348377299](https://github.com/creabizsalonspa-create/EV/actions/runs/42348377299)  
Exact build source: `7e1b225e5add58da8a87e64d52292b76c5e99af5`

| Official workflow artifact | ID | GitHub artifact SHA-256 | Container bytes |
|---|---:|---|---:|
| `hasone-customer-android-development` | 13569331617 | `c1deb65c2b7ade145f3f9915aaa8d2125613b180c97321c093ac2ad564ee6f6d` | 119914048 |
| `hasone-customer-ios-simulator` | 13565252105 | `07ca5325a765a540c3dbdd68c986310ed0a4e8a5c8497f66de953808344a7922` | 98283056 |

These official artifacts are *wrapper ZIPs*. The Android wrapper holds `app-debug.apk`; the iOS wrapper holds `hasone-ios-simulator.tar.gz`, whose `Runner.app/Info.plist` and 1,132 simulator TAR entries were inspected. This is why the generic Android/iOS bundle verifier alone cannot ingest the raw GitHub workflow downloads.

## Reproducible safe import

After separately downloading the **same two GitHub Actions artifacts** from the successful run, execute from repository root:

```sh
python3 scripts/delivery_artifact_import.py \
  --output artifacts/delivery/hasone-7e1b225-private \
  --source-sha 7e1b225e5add58da8a87e64d52292b76c5e99af5 \
  --android-wrapper /private/path/hasone-android-7e1b225.zip \
  --android-digest c1deb65c2b7ade145f3f9915aaa8d2125613b180c97321c093ac2ad564ee6f6d \
  --ios-wrapper /private/path/hasone-ios-simulator-7e1b225.zip \
  --ios-digest 07ca5325a765a540c3dbdd68c986310ed0a4e8a5c8497f66de953808344a7922

python3 scripts/delivery_bundle.py verify \
  --bundle artifacts/delivery/hasone-7e1b225-private \
  --expected-source-sha 7e1b225e5add58da8a87e64d52292b76c5e99af5
```

Both commands are local/offline. The importer checks the two published outer artifact digests, refuses unexpected wrapper structures or unsafe TAR entries, converts the iOS simulator TAR to a stand-alone ZIP without extracting it to disk, copies the actual Android APK, and delegates to the canonical bundle validator. No input or existing delivery folder is overwritten.

## Confirmed converted output (private local acceptance of archive integrity only)

- `android-development.apk`: **218561100** bytes; SHA-256 `080fe55b6d1226ad7cc51a3a9d7617419de62d06c262d172289c49c4b979c22c`.
- `ios-simulator.zip`: **99608350** bytes; SHA-256 `640ca7628083ff0240f38deae9273b209fd62dc11dd611360b40377a76d07ee7`.
- `manifest.json`: labels the exact source commit and deliberately records `development_demo_only`, `production_accepted: false` and `signed_for_store: false`.

The actual imported artifact bundle passed a second `verify` invocation and complete ZIP/APK CRC checks. Synthetic importer/bundler tests: **20 passed**, zero failures/skips; exact-source Git blob equality separately verified. No changes were made to a real server, charging gateway, customer database, payments, or store release.

**Never claim production-ready based on this bundle.** These are debug/simulator builds of the purchased HAS.ONE reference client, **not** the complete verified Laravel/CitrineOS customer/vendor/Admin platform. Authenticated account workflows, physical Teltonika charging, PSP financial settlement, real OCPI networks, certificate/signing/store review, ownership/licensing, deployment and customer acceptance are still unverified. The actual source/CI artifact association is established by the cited successful GitHub Actions run, not by the manifest text alone.

Agent 6 owns native build approvals; Agent 9 owns functional QA; Agent 4 owns final merge/release integration.
