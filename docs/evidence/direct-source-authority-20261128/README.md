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

## Local connector inventory identity fence

The isolated continuation from draft #452 pins the local connector ID,
charger ID, connector number, type and rated power in each receipt. The
maintenance flag is deliberately excluded from the identity fingerprint.
The live coverage projection and private review read validate the current
local attributes; review creation rechecks the same attributes under lock
after source I/O. The internal inspection response also binds the exact
registration fingerprint to the source observation, without exposing that
fingerprint to the public API. Local inventory mismatch requires explicit
operator investigation; it cannot silently renew an existing identity.

Executed locally in PHP 8.4.24: exact service blob
`ff71167a58db9291c91dbc33d58621032a4b2157` matched `git hash-object`,
passed `php -l`, and passed **48/48 standalone assertions** (24 per
protocol). Cases include changed connector number/type/power/charger/ID,
maintenance independence, missing/forged fingerprint, stale/regressed
events, wrong epoch, partial and incomplete coverage, and all three
non-authorization flags. These are pure PHP service assertions, not
Laravel/PostgreSQL concurrency tests. Two new feature regression instances
were authored but not run. The updated candidate-only migration must not
be applied as a replacement to a previously deployed migration. This
comparison cannot detect an attribute change that is reverted to the same
values between observations; a durable inventory mutation generation
would be needed for that stronger guarantee.

## Durable local connector mutation generation

The new SQLite/PostgreSQL migration records connector inserts, identity
updates and deletes in a separate indexed history table. It records both
old and new IDs on reassignment, excludes maintenance-only updates, and
retains history after deletion. Connector identity fingerprints include
the latest history ID (zero for pre-existing unmodified inventory).
Review creation captures that fingerprint inside a locked transaction,
then rechecks it after source I/O. Review reads and complete-coverage
checks use the same generation. Committed changes that restore old
attribute values therefore do not restore the old review's validity.

Executed in this checkpoint:
- Exact coverage service blob `c5c140ed0ac4127135387510bee32831d5137791`:
  git hash-object matched, PHP 8.4.24 lint passed, **52/52** standalone
  assertions passed (26 per OCPP protocol).
- Exact migration blob `d0e6751bcf759eedec5a76f22d533561b8d1fb42`:
  git hash-object matched, PHP lint passed, and **36/36 actual SQLite
  assertions** passed using the three verbatim trigger definitions on a
  minimal synthetic schema. Covered pre-existing rows, no-op and
  maintenance updates, changed/restored power, connector type/number,
  charger/ID reassignment, independent connector history, deletion,
  ID reuse, and transaction rollback.
- Two existing dual-protocol Laravel regression instances extended to
  exercise committed changes reverted during and after source review;
  authored, not executed.

PostgreSQL trigger execution, actual Laravel migrations, database
concurrency and full end-to-end acceptance remain outstanding. The
migration intentionally supports the repository's SQLite development
and PostgreSQL deployment stores; other database drivers fail closed.
Privileged history tampering or whole-database restore is outside this
local guard. This candidate must supersede the earlier unaccepted
receipt schema; no deployment or charging activation is authorized.

## Explicit blocked and renewable review states

The private review API now differentiates `current`, `renewal_required`,
`inventory_changed`, and `operator_review_required`. Only the same
registration at a higher reconciled authority epoch, with unchanged local
connector identity, may offer the renewal form. An inventory mismatch
requires investigation and is not represented as routine authority
rotation. Both web and native clients reject unknown or contradictory
state values rather than offering an actionable form. Existing receipt
history remains immutable; this is not a new repair or charging authority.

Executed evidence: exact web module blob
`62639daf7dbc6c7dc182559b64e4acedd2ada46d` passed full JavaScript syntax
compilation. Its extracted directConnectorReview function passed 72/72
assertions in V8 with synthetic DOM and HTTP dependencies across both
OCPP protocols. These cover current, renewable, changed inventory,
operator-required, missing and invented state, contradictory state flags,
locked renewal targets, exact submission and no implicit write.
Three native widget cases and amended Laravel assertions are authored,
not executed. Real browser, Flutter and full Laravel validation remain
outstanding. Older server receipts without the state field are rejected
by these updated clients; deploy server/client changes together.

## Legacy registration digest compatibility

The actual registration implementation was inspected: `verify()` returns
`array_intersect_key($row, ...)`, preserving upstream key order, and hashes
that ordered JSON object. The previous direct monitor assumed the selected
GraphQL field order was always the serialized order. This could reject
valid registrations.

The new `DirectRegistrationReceipt` helper validates only the seven exact
typed offline fields used by the original registration adapter. It first
checks the expected order, then permits bounded legacy serialization
orders (at most 7! candidate hashes) during explicit operator
reconciliation. No source value or type is relaxed. Live inspection
instead requires the immutable reconciliation fingerprint, avoiding
repeated permutation work and preserving receipt integrity.

Actual local PHP 8.4.24 execution: the fetched helper was reconstructed,
`git hash-object` matched `7a0d9276ccd3b403686cd2f16d452a9e9d05c610`,
and PHP lint passed. A standalone harness ran **40/40 assertions**:
20 each for OCPP 1.6 and 2.0.1, including four serialization orders,
changed field values, wrong scalar types, omitted/extra fields, malformed
and forged digests, invalid station ID and unsupported protocol.
A matching PHPUnit unit test and two feature regression instances
were committed but not run through Laravel/PHPUnit. The bounded digest
check is local evidence verification, not authentication of the original
upstream hardware or protection from privileged rewriting of local history.
