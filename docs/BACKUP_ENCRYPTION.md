# Offline authenticated backup encryption

`scripts/backup-secretstream.php` uses the standard libsodium secretstream
XChaCha20-Poly1305 API already present in the pinned PHP runtime. It never boots
Laravel or opens a network connection. It supports large backups with one MiB
plaintext chunks, randomized stream headers and an authenticated final record.

Invoke with PHP CLI: `php scripts/backup-secretstream.php encrypt INPUT OUTPUT KEY`
or replace `encrypt` with `decrypt`. Paths must be absolute canonical paths.
Input/key files must be unlinked regular files owned by the executing account,
with no group/world access. The key must contain exactly32random binary bytes;
never put key bytes in command arguments, environment variables or Git. The
output parent must be a private real directory owned by that account. Existing
outputs are never replaced. Completed output is mode0600 and published atomically
without overwriting another process's destination. Ordinary failures remove the
private pending file and publish no partial plaintext; interrupted/crashed jobs
may leave private pending files that an operator must remove deliberately.

The format binds a versioned header as associated data, length-bounds every
record, and requires a separate authenticated empty final record followed by
EOF. Wrong keys, modified/truncated/reordered/duplicated records, oversized
frames and appended bytes are rejected. This is a framing wrapper around the
standard library primitive, not a replacement cipher or a legal/compliance
certification. Cryptographic authenticity alone does not prove freshness,
source identity, retention, snapshot consistency or an authorized restore.

The existing disposable recovery drill encrypts both its actual pg_dump archive
and private-file archive with a separate ephemeral key, decrypts/compares their
bytes, rejects the unrelated HMAC key, and then restores the decrypted files.
The original archive-integrity/source-manifest and isolation gates remain in
force. Runtime tests run the actual helper in a read-only, unprivileged,
capability-dropped, network-disabled pinned container with only a private
run-owned scratch mount. No production backup or actual owner key is used.

Production key custody/rotation/escrow, encrypted offsite storage, retention,
PITR/WAL and coordinated real-service restore remain independent unfinished
operational requirements. This tool and synthetic drill do not authorize a
production deployment or promote whole requirement9.1.10.
