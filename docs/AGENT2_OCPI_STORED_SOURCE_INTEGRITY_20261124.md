# Agent 2: bounded stored OCPI source integrity inspection

Advances the local evidence/reconciliation portion of PRD 6.3.8. It is not complete OCPI protocol conformance or partner acceptance.

The privileged operator shell can run:

```sh
php artisan ocpi:inspect-source-integrity 123 locations --limit=50 --after=0
```

Use one explicit provider and one of locations, tariffs, sessions or cdrs. The command reads at most 100 rows plus one look-ahead row per invocation, ascending canonical row ID. Follow the returned next_after_id only while has_more is true. Each page observes the current database; multiple pages are not an immutable catalog snapshot. Revoked providers remain inspectable by the authorized operator for historical evidence, but are not reactivated.

The command decrypts each selected payload only in process memory, compares its stored canonical digest, runs the current supported normalization subset, compares canonical identity and UTC timestamp, and compares the normalized projection by canonical hash (independent of JSON object key order). It returns aggregate fixed finding counts, not payloads, tokens, exception text, source identifiers, private addresses or digests. A row can contribute multiple findings; review_required counts distinct affected rows. An empty page is explicitly no_evidence. Nonzero exit means invalid scope, unavailable inspection, no evidence or required review.

A mismatch is a review observation, not a diagnosis of malicious alteration: historical normalizer changes, legacy JSON number representation, encryption/key changes or an invalid source can require investigation. The command never repairs source data, changes accepted CDRs, contacts a peer, triggers a charger, creates finance activity, asserts physical availability, or claims the whole catalog is verified. No HTTP endpoint or recurring task is registered. Standard host-level operator access controls apply.

Regression coverage verifies scope, cursor bounds, revoked historical rows, separate module scope, corrupt ciphertext, inconsistent identity/digest/timestamp/projection, secret-free output, no data mutation, no network calls and invalid/empty results. Hosted verification is required at the exact source commit; no software atom is credited by this PR alone.
