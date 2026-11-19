# SQLite live-worker transaction and simulator safety

SQLite development/simulator databases use IMMEDIATE transactions. This reserves the writer before a read snapshot, so database-backed rate-limit increments and other read/write transactions cannot enter a deferred snapshot upgrade which fails immediately under concurrent source-worker commits. The existing bounded SQLite busy timeout remains unchanged. PostgreSQL connections, rate budgets, rate-key identity, expiry, signed inbox authorization and command idempotency are unchanged. There is no cache flush, per-process quota store or blind HTTP/charger retry.

SqliteAtomicCacheTest uses two independent real SQLite connections to an owned temporary database. With the previous DEFERRED mode its writer-exclusion assertion fails. With IMMEDIATE, a second writer is excluded before reading, subsequent increments preserve the original expiration, and the actual RateLimiter reaches and rejects the unchanged ten-attempt budget. Its zero busy timeout is confined to the test aliases, not application configuration.

## Independent upstream arithmetic limitation

A retained OCPP2.0.1 simulator journal reported1145Wh after a1000Wh baseline, while Citrine's derived totalKwh was the authenticated literal0.14500000000000002. That is not an exact integer-Wh total. SourceConsumer refuses reconciliation and does not manufacture a billable final reading. The stale SQLite diagnostic in that failure was explicitly unattributed; it does not establish that SQLite caused this source-stop failure. Original journals are retained and no unknown command was replayed.

The local synthetic charger keeps its existing18kW energy rate, now sampled in125Wh/25second quanta. These eighth-kWh values are exactly representable in Citrine's binary arithmetic; event timestamps remain actual observation times. This is a fixture correction, not proof that arbitrary production source totals work. Production adapters must provide exact derived-energy evidence; source totals with fractional-Wh arithmetic residue still require reconciliation. No production rounding/tolerance was added. Authenticated register parsing, terminal-total agreement and one-invoice invariants remain enforced.

Run filesystem-exclusive Laravel/worker tests sequentially with the live daemon lifecycle in the same checkout. Command/source tests intentionally use the same exclusive worker locks as real local daemons. Concurrent execution produces lock refusal, not an admission bypass; validation must not remove those locks. Separate physical worktrees have separate lock files.
