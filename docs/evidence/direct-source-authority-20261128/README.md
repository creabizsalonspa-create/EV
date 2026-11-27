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

## Executed evidence

Exact PHP service blob `39d7e74b6ee905104e77b15a2c328e1cf3e6c57a` was
reconstructed locally and its Git blob SHA matched the fetched repository
blob. PHP 8.4 lint passed. A standalone PHP harness using in-memory
Laravel/configuration/database stubs executed 21/21 assertions, covering
unprovisioned denial, no read-side writes, invalid ticket, first provision,
idempotent replay, same-epoch credential substitution, explicit rotation,
history retention, no raw secret in the stored rows, lower-epoch rejection,
TLS-only configuration, noncanonical epoch, and inconsistent pointer denial.

This is not Laravel/PostgreSQL migration or concurrent transaction evidence.
Dual-protocol feature regressions for review invalidation, rollback and
in-flight rotation are authored, not executed. Exact resulting-head hosted
validation, database locking/rollback validation and approved real-source
acceptance remain necessary.
