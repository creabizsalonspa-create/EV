# OCPI authorization generations and stale-response rejection

This software slice hardens the existing outbound OCPI eMSP read adapter. It does not implement or claim complete credentials registration/rotation, push, tokens, commands, billing reconciliation, live partner conformance or physical charging control. All verification uses Laravel HTTP fakes and synthetic provider identities; no actual network partner is contacted.

## Authority and concurrency

Migration `2026_11_04_230002_add_ocpi_authorization_revision.php` adds a nonnegative provider `authorization_revision` with a legacy default of0. It is hidden from the existing public/vendor/Admin serialization, so existing response contracts remain compatible. Original authorization/capability fields are retained.

Every authorized/revoked administrative transition increments the revision under the existing provider lock, including an explicit repeated authorization. It clears negotiated version/endpoints as before. Counter exhaustion prevents reauthorization rather than wrapping or reusing an old generation. Revocation remains available at the terminal generation; it clears capabilities and permanently prevents reauthorization. Provisioned credential/origin changes must be accompanied by an explicit revoke/reauthorize operation; this slice is not a credential rotation service.

Negotiation captures the provider's revision, owner and approved connection alias. Before each outbound versions/details/module page read, the client checks the current database authorization against that snapshot. An already dispatched HTTP read cannot be cancelled, but revocation prevents subsequent pages. Advertised endpoints still pass the existing fixed-origin, no-redirect, bounded-response guards, and credentials remain server-only. Ambiguous duplicate2.2.1 version advertisements now fail instead of selecting whichever appeared last.

Before saving negotiated capabilities, the client locks the provider and rechecks authorization/revision/owner/alias. A revocation, reauthorization, ownership transfer or concurrent successful negotiation invalidates the old operation. The successful publisher increments the revision, so two negotiations from one generation cannot both publish. No HTTP call is made while holding that database row lock.

Record ingestion also compares the captured revision, owner and connection alias under the existing provider lock. Old pull results cannot become valid merely because an operator was reauthorized and negotiated2.2.1 again. The complete stale batch is discarded; imported data remains unverified, read-only and ineligible for billing or remote control.

The downgrade refuses to discard used authorization generations outside testing. Preserve the generation metadata and source version with backups and use an audited migration/rollback procedure; revocation alone does not remove the historical generation.

## Verification

- Current SQLite/Laravel OCPI suites: **27 tests/123 assertions passed**, including10 new boundary tests. They cover administrative revision advancement/privacy, duplicate advertised versions, revocation between requests/pages, reauthorization at final response, overlapping negotiation, stale ingestion, ownership transfer, counter exhaustion and populated non-testing downgrade protection.
- Focused actual PostgreSQL feature run: **24 tests/115 assertions passed**. This phase began before the last three SQLite boundary tests were added; it is not reported as a27-test PostgreSQL run.
- Independent-process PostgreSQL races: **3 tests/38 assertions passed**, using paused synthetic HTTP responses and committed administrative transitions: stale final negotiation after revoke/reauthorize; two negotiations sharing a generation; old pull after a fresh authorization/negotiation. No timing-only sleeps are used as evidence of ordering: the parent waits for explicit worker barriers.
- Existing17 OCPI feature tests remain included. New model/controller/client/synchronizer/migration/test files pass Pint. Evidence is in `docs/evidence/ocpi-authorization-boundary`.

The integration owner must run the full combined regression and updated hosted checks before merging this continuation. Partner agreements, actual credentials/callback interoperability, deployment egress/DNS controls, complete OCPI schema conformance and production/hardware acceptance remain separate missing implementation or external gates documented in `OCPI.md`.

## Current restored-source verification — 31 October 2026

Latest source adds terminal-generation revocation: an exhausted counter cannot be reauthorized, but Admin can still revoke it and invalidate every old read. Current focused OCPI28/130, full combined Laravel396/8770, PostgreSQL connector/OCPI65/858 and17 independent-process races/235 pass. Connected roaming and provisioning browsers pass actual Laravel owner isolation, revocation, lost acknowledgements, safe registration, Arabic/RTL and responsive controls. All partner HTTP is synthetic; no live operator conformance or charging command is claimed. Exact-head hosted CI and integration remain required. Earlier figures above are historical, not the current combined suite.
