# Dependency inventory

Run `python3 scripts/dependency-inventory.py` from a checkout, or pass `--root` for an extracted source tree. The command emits deterministic JSON on stdout and performs no downloads, dependency installation or package-script execution.

It inventories Composer locked packages, npm v2/v3 package instances and generated Flutter lock entries. Each lock has a SHA-256 checksum. Git checkouts select tracked locks and compare their bytes with the recorded source commit; changed locks remain explicitly unmatched. Archives have no inferred Git provenance. Package instances and development-only declarations remain separate.

`release_inputs` records the SHA-256 checksum and source-commit match for each selected Dockerfile, Compose manifest, Android Gradle wrapper and build file, iOS Podfile or Podfile lock, and `THIRD_PARTY_NOTICES.md`. The inventory reports paths and hashes without copying file contents. These hashes identify the release definitions that were reviewed. Installed image layers and native dependency resolution require separate build evidence.

Platform validation runs the offline inventory tests and generates the `dependency-inventory` artifact before installing dependencies. The `--require-source-match` option requires a clean tracked Git checkout and rejects any lock whose bytes cannot be matched to its source commit. Use the recorded commit and lockfile checksums to identify the release candidate being reviewed.

`declared_licenses` contains lockfile declarations, not approval. Missing declarations, manifests without a selected adjacent lock and unsupported workspace/version formats require review. The command rejects malformed/duplicate data, unsafe symlinked locks and oversized inputs without returning a partial inventory or echoing source contents. Credential-bearing source URLs are not emitted.

License approval, vulnerability scanning, dependency-constraint validation, installed-runtime verification and production acceptance remain false. This inventory supplies release-review evidence and does not replace those gates.
