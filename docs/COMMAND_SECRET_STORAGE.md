# Command secret storage and retained-row sealing

This implementation encrypts recognized credential fields inside charger-command payload and confirmation JSON. Queryable session/connector metadata, payload hashes, idempotency keys, command outcomes and existing public redaction remain unchanged. This is not whole-database encryption or proof that arbitrary free-text replies contain no secrets. No hardware or production acceptance is implied.

New canonical Eloquent writes seal secret fields with Laravel authenticated encryption and the configured APP_KEY. Envelope version1 binds actor, station, action, idempotency key, payload hash, field name and nested path. Dispatcher accessors recover the exact original JSON value, including structured OCPP2.0.1 idToken values. Ciphertext copied across commands or field paths, malformed envelopes, missing keys and failed MACs are rejected; ciphertext never becomes a fallback transport token. Ordinary status updates retain existing ciphertext and nonsecret JSON bytes.

Legacy plaintext remains readable to preserve existing pending/unknown command recovery. It is not automatically claimed secure. Inspect and seal it explicitly:

```sh
./scripts/php artisan csms:seal-command-secrets --limit=100 --after=0
./scripts/php artisan csms:seal-command-secrets --apply --limit=100 --after=0
./scripts/php artisan csms:seal-command-secrets --check --limit=100 --after=0
```

Follow next_after_id until it is0; a successful single page does not audit the entire table. Default mode is dry-run. Check mode is read-only and returns nonzero for an unsealed batch or unreadable ciphertext; it cannot combine with apply/rekey. Limits1–1000 and nonnegative bounded cursors are enforced. Each row is locked independently, validated and updated atomically. A failed row stops execution with its ID only; repair the row/key and resume without skipping an uncertain operational outcome. Previous completed rows may already be sealed. No network requests, charger commands, financial records, timestamps or operational outcomes are produced or rewritten.

For command-field key rotation, first configure the new current key and the required APP_PREVIOUS_KEYS through the deployment secret manager. With both keys available, use dry-run rekey, then explicit apply and finally a complete read-only check:

```sh
./scripts/php artisan csms:seal-command-secrets --rekey --limit=100 --after=0
./scripts/php artisan csms:seal-command-secrets --apply --rekey --limit=100 --after=0
```

Rekey changes ciphertext only; it does not replay transport intents. Retain previous keys until every affected platform encryption domain and retained backup has its own verified rotation/recovery plan. This command rotates command fields only. Existing plaintext in old dumps, WAL, replicas or exports is not erased by sealing current rows. Direct SQL/import writers bypass model hooks and must be included in the check procedure.

Deploy readers/writers with envelope support together. After sealing, do not roll back to software that interprets encrypted fields as ordinary JSON transport values. Recovery requires a matching software/database/key checkpoint and retained command journals; no automatic plaintext downgrade is provided. Production readiness remains dependent on the broader deployment/security review and genuine source/hardware acceptance.
