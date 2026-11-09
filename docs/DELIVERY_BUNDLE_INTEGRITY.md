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

The output directory **must not exist** and its parent must already exist. Each incoming Android/iOS source file is also revalidated and copied through one non-following regular-file descriptor: ZIP CRC runs on the same source inode that supplies copied bytes, then the source path, size, inode, timestamps and single-link status must remain stable. Changing a source to a symlink, hardlink, or replacement during creation fails closed and removes the incomplete private output. This guards against local source-file races; independent GitHub CI artifact provenance is still required. The tool never overwrites an earlier bundle. Contents are `manifest.json`, `android-development.apk` and `ios-simulator.zip`; paths and digests are fixed in the manifest. New directory and files are created with private POSIX modes when supported. The Android/iOS private files and `manifest.json` now use exclusive, non-symlink-following descriptor opens with mode `0600` **at creation time**, before any data is written; the manifest is flushed and fsynced while its private descriptor is open. This avoids relying on later `chmod` calls and is safe even when the caller has a permissive umask. Failure to create either private file aborts and removes the unfinished bundle. No step signs or independently authenticates the development binaries. Real customer/production secrets must never be inserted into these bundles.

## Verify before handoff

```sh
python3 scripts/delivery_bundle.py verify \
  --bundle artifacts/delivery/customer-demo-NEW-RUN \
  --expected-source-sha EXACT_40_CHARACTER_VERIFIED_BUILD_COMMIT
```

On POSIX filesystems, verification additionally requires the bundle directory to remain owner-only (\`0700\`) and the manifest plus both binaries to remain owner-only (\`0600\` or more restrictive); loosening a mode to group/world-readable fails even when the checksums are unchanged. Generic imported source artifacts may use ordinary download permissions; the restriction applies to the packaged handoff.\n\nThe manifest's \`created_at_utc\` must be a valid, canonical UTC second-resolution timestamp that is not more than five minutes ahead of the verifier's clock. It records **local packaging time, not GitHub build time or independent timestamp attestation**. Machines with an incorrect clock will fail closed until corrected.\n\nVerification checks the exact manifest schema, listed roles and filenames, SHA-256 and byte length, that no unknown files have been added, and the complete CRC of each embedded APK/ZIP member. After each platform is audited, verification retains the artifact's inode, device, size and timestamps and rechecks **both** artifacts immediately before returning success. This catches an Android APK changed while the iOS ZIP is reviewed (or either platform replaced after its own checksum/CRC check), including identical-byte inode replacement, symlink substitution or new hardlinks. These checks reduce race windows but do not lock the directory or provide independently signed provenance. Each packaged Android and iOS artifact is now opened once with `O_NOFOLLOW|O_NONBLOCK` and validated from that **same private descriptor** for both SHA-256 and complete ZIP CRC. The original and final file path must resolve to the same regular, single-link inode and unchanged size/timestamps/mode, so an edit, same-byte atomic replacement, or symlink swap between hash and CRC cannot produce an accepted handoff. The guard applies to the packaged handoff; the source APK/ZIP acquisition still requires independently verified GitHub Actions provenance. The manifest parser now reads one bounded regular, owner-only, single-link, non-symlink file descriptor with `O_NOFOLLOW|O_NONBLOCK`; it rejects duplicate JSON members (including contradictory nested values) and nonstandard numeric constants such as `NaN` and `Infinity`. After checking Android/iOS digests and CRCs, verification reopens the same private manifest and requires the original digest, size, inode, and timestamps to remain unchanged. This detects manifest edits and same-byte atomic replacements during the verification window but does not constitute a filesystem lock or signed attestation. Unsafe absolute/traversal/duplicate archive members and escaping symlinks are rejected. Encrypted ZIP members and unsupported compression methods are rejected before content inspection, rather than causing uncaught ZIP-library exceptions. Runtime errors from the ZIP decoder are wrapped as controlled bundle-validation failures, so malformed supplied APK/ZIP files cannot disclose local paths in a CLI traceback. An archive's platform identity cannot be asserted by a link or an empty member: `AndroidManifest.xml` and iOS `.app/Info.plist` must be nonempty regular ZIP members. Relative iOS framework symlinks remain allowed, but each symlink target is limited to 4 KiB before its body is decompressed, preventing an unbounded in-memory read. These are structural checks only, not an assertion that a binary is a valid installable application. Only the package contents are read; no services are contacted.

**Important:** A supplied Git commit SHA is a required label, **not independent proof** that a binary was compiled from that commit. Source provenance must be established separately by matching actual GitHub Actions run ID, commit SHA, artifact digest and build logs. iOS simulator ZIP cannot be installed on a physical iPhone. The Android debug APK is not a store-signed production APK. A passing archive check does not mean the apps work or that money, hardware, OCPP/OCPI or customer acceptance has been verified.

## Review and integration evidence

```sh
python3 -m py_compile scripts/delivery_bundle.py tests/delivery_bundle_test.py
python3 -m unittest discover -s tests -p 'delivery_bundle_test.py' -v
```

This is an **independent Agent 10 packaging safeguard**. Agent 6 owns building Android/iOS clients; Agent 4 owns final verified integration; Agent 9 owns full cross-platform QA. Before customer handoff, separately confirm customer authorization, installation support, app source/artifact provenance, tester accounts, connectivity, acceptance checklist, platform license rights and privacy review. No production deployment or public distribution is authorized by this command.
