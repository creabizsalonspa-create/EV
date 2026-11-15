# Dependency inventory

Run `python3 scripts/dependency-inventory.py` from a checkout, or pass `--root` for an extracted source tree. The command emits deterministic JSON on stdout and performs no downloads, dependency installation or package-script execution.

It inventories Composer locked packages, npm v2/v3 package instances and generated Flutter lock entries. Each lock has a SHA-256 checksum. Git checkouts select tracked locks and compare their bytes with the recorded source commit; changed locks remain explicitly unmatched. Archives have no inferred Git provenance. Package instances and development-only declarations remain separate.

`declared_licenses` contains lockfile declarations, not approval. Missing declarations, manifests without a selected adjacent lock and unsupported workspace/version formats require review. The command rejects malformed/duplicate data, unsafe symlinked locks and oversized inputs without returning a partial inventory or echoing source contents. Credential-bearing source URLs are not emitted.

License approval, vulnerability scanning, dependency-constraint validation, installed-runtime verification and production acceptance remain false. This inventory supplies release-review evidence and does not replace those gates.
