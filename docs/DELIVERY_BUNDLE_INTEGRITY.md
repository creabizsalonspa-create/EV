# Agent 10 — offline Android/iOS development delivery bundle

This tool packages **already-built** Android debug APK and unsigned iOS simulator ZIP into a new private directory with a strict source-checkpoint label, SHA-256 checksums, archive CRC verification and an explicit **development-demo-only** acceptance statement. It does not build, sign, upload, deploy or publish anything, and does not verify authenticated user journeys or physical charging.

## Create (offline)

After an authorized build completes and the build artifacts are downloaded and verified against their official CI upload checksums, from the repository root:

```sh
python3 scripts/delivery_bundle.py create \
  --output artifacts/delivery/customer-demo-NEW-RUN \
  --source-sha EXACT_40_CHARACTER_VERIFIED_BUILD_COMMIT \
  --android /private/path/to/actual-development.apk \
  --ios /private/path/to/actual-ios-simulator.zip
```

The output directory **must not exist** and its parent must already exist. The tool never overwrites an earlier bundle. Contents are `manifest.json`, `android-development.apk` and `ios-simulator.zip`; paths and digests are fixed in the manifest. New directory and files are created with private POSIX modes when supported. Real customer/production secrets must never be inserted into these bundles.

## Verify before handoff

```sh
python3 scripts/delivery_bundle.py verify \
  --bundle artifacts/delivery/customer-demo-NEW-RUN \
  --expected-source-sha EXACT_40_CHARACTER_VERIFIED_BUILD_COMMIT
```

On POSIX filesystems, verification additionally requires the bundle directory to remain owner-only (\`0700\`) and the manifest plus both binaries to remain owner-only (\`0600\` or more restrictive); loosening a mode to group/world-readable fails even when the checksums are unchanged. Generic imported source artifacts may use ordinary download permissions; the restriction applies to the packaged handoff.\n\nThe manifest's \`created_at_utc\` must be a valid, canonical UTC second-resolution timestamp that is not more than five minutes ahead of the verifier's clock. It records **local packaging time, not GitHub build time or independent timestamp attestation**. Machines with an incorrect clock will fail closed until corrected.\n\nVerification checks the exact manifest schema, listed roles and filenames, SHA-256 and byte length, that no unknown files have been added, and the complete CRC of each embedded APK/ZIP member. Unsafe absolute/traversal/duplicate archive members and escaping symlinks are rejected. Only the package contents are read; no services are contacted.

**Important:** A supplied Git commit SHA is a required label, **not independent proof** that a binary was compiled from that commit. Source provenance must be established separately by matching actual GitHub Actions run ID, commit SHA, artifact digest and build logs. iOS simulator ZIP cannot be installed on a physical iPhone. The Android debug APK is not a store-signed production APK. A passing archive check does not mean the apps work or that money, hardware, OCPP/OCPI or customer acceptance has been verified.

## Review and integration evidence

```sh
python3 -m py_compile scripts/delivery_bundle.py tests/delivery_bundle_test.py
python3 -m unittest discover -s tests -p 'delivery_bundle_test.py' -v
```

This is an **independent Agent 10 packaging safeguard**. Agent 6 owns building Android/iOS clients; Agent 4 owns final verified integration; Agent 9 owns full cross-platform QA. Before customer handoff, separately confirm customer authorization, installation support, app source/artifact provenance, tester accounts, connectivity, acceptance checklist, platform license rights and privacy review. No production deployment or public distribution is authorized by this command.
