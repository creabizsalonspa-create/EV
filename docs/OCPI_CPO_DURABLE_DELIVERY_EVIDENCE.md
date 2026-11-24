# Durable CPO delivery evidence

New publications store a digest of the exact persisted bytes (ciphertext for private session/CDR exports). Each attempted delivery additionally binds the provider, owner, generation, configuration, authorization revision, module, source identity, timestamp, withdrawal, canonical source hash and stored bytes. A changed publication is not automatically re-sent or credited from an unrelated peer receipt.

Existing catalog and operation rows receive nullable digest columns without invented historical evidence. A legacy catalog without unresolved delivery may require explicit republication with its current publication timestamp; historic operations lacking a digest cannot be reconciled or replayed automatically. Operators must preserve evidence and review those records. A mismatched claim is retained as unknown; no automatic repair or retry is performed. The migration rollback protects stored evidence outside testing.

These are sandbox software safeguards, not live partner delivery or production conformance. Validate the exact source on SQLite and PostgreSQL before canonical integration.

The same stored-byte digest is required before serving individual or paginated CPO Sender objects and before referencing tariffs, location/connector mappings or final sessions from dependent publications. Historical undigested objects return a conflict until explicitly republished, with no silent exclusion from a successful page. A successful transport envelope is not acknowledged if the publication or delivery claim changed during the attempt. The attempt remains uncertain, requiring review.

The existing negotiated Sender route contract is unchanged: tariffs, sessions and CDRs use lists; Locations additionally supports object, EVSE and connector reads. All exposed read paths enforce stored publication integrity.
