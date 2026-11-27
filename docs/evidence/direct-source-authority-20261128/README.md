# Registered direct-source authority (partial 3.3.4)

This isolated continuation is based on draft PR #448 head
`44ab69e66ab8ba700ee6e373a34298ac2acd15d7`. The original PR remains frozen while
its hosted gates run. No requirement atom, charging capability, or production
acceptance is granted.

## Operator contract

The monitoring adapter remains default-off. On an approved environment only,
an operator must configure the reviewed HTTPS GraphQL URL and secret and a
positive `CSMS_REGISTERED_SOURCE_AUTHORITY_EPOCH`. An explicitly authorized
shell operator may then run:

```sh
php artisan csms:provision-direct-source-authority --ticket=APPROVED-TICKET
```

This is an example command, not a command executed against production. It
records a local immutable authority history and a monotonically advancing
pointer. It never contacts the upstream service. Identical configuration at
the current epoch is idempotent; a changed configuration requires a strictly
higher explicitly provisioned epoch. Lower epochs and inconsistent history are
rejected. Ordinary source reads cannot provision or advance the epoch.
The review fingerprint includes the authority proof; older review evidence
is retained but no longer current after a provisioned rotation.

The ticket is correlation metadata, not verified human identity. Privileged
shell access, change approval, secret storage, operator logs and production
acceptance remain external prerequisites. This local authority ledger does
not independently detect an upstream database replacement with identical
configured authority, or a rollback of the entire local database and its
history together. It does not rotate or reconcile existing source
registrations automatically.

## Registration reconciliation

Following an authority change, a registration must independently be
reconciled with its exact original receipt by an authorized shell operator:

```sh
php artisan csms:reconcile-direct-registration REGISTRATION_ID --receipt=ORIGINAL_SHA256 --ticket=APPROVED-TICKET
```

The command locks the owned company, charger, site, station, owner,
registration, binding and profile; checks the original payload and
registration receipt against the current local lineage; and records an
immutable registration-specific epoch entry. A matching but corrupted
stored hash is not sufficient. The original source origin cannot be
reassigned by reconciliation. Neither command contacts a real charger.
This is local administrative evidence, not upstream attestation.

## Executed evidence (current checkpoint)

Both exact PHP service blobs were reconstructed in the local PHP 8.4.24
runtime, verified with `git hash-object`, and passed `php -l`:

- DirectSourceAuthority: `39d7e74b6ee905104e77b15a2c328e1cf3e6c57a`.
- DirectSourceRegistrationEpoch: `423ea3c4de43e4f8d8f76329300896f0b636d8ee`.

The combined standalone harness executed **40/40 assertions** (20 each for
OCPP 1.6 and 2.0.1). It exercised unprovisioned denial, first provision,
idempotent provision, unreconciled denial, incorrect receipt, initial
reconciliation, idempotent reconciliation, read-only current checks,
same-epoch secret substitution, rotation, rejection of prior reconciliation,
changed model, foreign station, changed profile, matching forged stored
receipt, explicit new reconciliation, preservation of both histories,
rollback denial, new origin provisioning and rejection of registration
migration to the new origin.

The harness uses in-memory model/query/config/transport stubs; it models
transaction rollback but not actual row locks or PostgreSQL constraints.
It does not exercise the real transport validator or vendor license check.
This supersedes the earlier limited standalone evidence claim; no hosted
Laravel test result is inferred. Dual-protocol feature cases for the new
lineage failures are authored but remain unexecuted. Full migration,
concurrency, browser/native and real-source acceptance are outstanding.

## Same-identity connector renewal checkpoint

An owner can explicitly renew a stale connector review after registration
reconciliation, but only for the original registration, connector database ID
and OCPP target, at a strictly higher authority epoch and with a strictly
newer source event ID and observation time. Each epoch has an immutable
receipt; the composite foreign key references the corresponding registration
epoch. Previous target claims are retained and cannot be assigned to another
connector. Ordinary receipt lookup returns only the latest receipt per
connector. Web and native renewal forms lock the original target, and an
uncertain response requires explicit read-back.

The earlier 40/40 PHP service harness was rerun in the local PHP runtime with
both exact hashes verified. The updated web module blob
`f0a31968b8eba42fd5ec04103e707f43d4714753` passed 36/36 assertions using a V8
stub DOM and HTTP harness across both protocols, including target tampering,
lost response, explicit read-back, stale form, non-owner and visibility
fences. The amended dual-protocol Laravel rotation tests and two new Flutter
widget cases are authored, not executed. No real browser or database tests
are claimed.

The review migration changes are candidate-only and assume that the
unaccepted draft #448 schema has not been deployed. This continuation
supersedes that schema; if #448 were deployed first, a separate additive
migration and historical data reconciliation would be needed. Agent 5 must
coordinate integration. No charging mapping, capacity or control is enabled.

## Direct connector review coverage presentation

The direct-source response now includes bounded review coverage for the
current owned inventory, reconciled registration and authority epoch. It
does not create a charging mapping. Web fleet and charger diagnostics,
and their native equivalents, display the current review count and a
separate non-authorization statement when all identities are reviewed.
Missing coverage on an earlier server is displayed as unknown. Malformed
coverage, contradictory counts, stale/offline complete claims, or any
assertion of physical availability or charging authority are rejected.

Executed evidence: the exact fetched JavaScript validation blocks in
`b81a4686d425f932a4364b056ce38a6dcf817484` (fleet) and
`3534c4321623dbb99ae8352d274e20433d2c8f8f` (diagnostics) each passed 16/16
assertions in the JavaScript runtime. Both complete modules also passed
syntax compilation. This is 32 executed validation assertions, not a
browser rendering or end-to-end test. The new Flutter helper and widget
tests are authored, not executed. Laravel and PostgreSQL integration
validation remains outstanding.
