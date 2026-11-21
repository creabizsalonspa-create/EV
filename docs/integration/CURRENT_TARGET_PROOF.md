# Current-target integration proof

The read-only readiness audit now resolves the canonical Git reference before
checking candidates and again after collecting their PR/check/workflow evidence.
A cached `pull_request.base.sha` does not establish the latest integration head.

A candidate must contain the captured integration target in its source history.
The GitHub comparison must report `ahead` or `identical`, with both its base and
merge-base equal to the captured target. Diverged or behind branches, incomplete
comparison responses and malformed references fail closed. A target that changes
while the audit runs invalidates every candidate in that report.

This conservative requirement deliberately asks an outdated feature branch to
integrate the current target and rerun its exact-source CI. It never declares an
untested merge tree eligible. The integration controller must still recheck the
live PR/head/target immediately before a normal SHA-guarded merge, independently
verify the local predicted merge tree equals the tested source tree, and verify
the actual merge parents/tree afterward. A read-only report never merges or
replaces human code review or required workflow checks.

Validation on 20 November 2026: 384 Python tests passed, including current-ref
lookup with a stale PR base cache, source divergence/behind/malformed comparison,
target movement during the audit, and invalid target identities. A live read-only
PR192 audit at source `61f00e9a41b3a0da2e8d674c9c7742379f8d321a` proved it
contained target `151acd73e94e12b2e1cdfbe46ce859928d186b92` and correctly
blocked its then-pending platform/native checks and missing dependent recovery
job. This observation is not a final passing PR192 result or production approval.

No original requirement atom receives new completion credit from this guard.
