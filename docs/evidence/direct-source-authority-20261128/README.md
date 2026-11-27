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
