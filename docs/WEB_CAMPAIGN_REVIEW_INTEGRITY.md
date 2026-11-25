# Web campaign review: canonical source integrity

The vendor/Admin campaign workspace verifies paid package source pages,
owned station choices, campaign identities, source flags, bounded money-free
delivery counters, immutable terms, audit revision and legal state transitions
before exposing controls. Every successful decision must return the same
immutable terms, its expected new state, a strictly newer control revision,
and nonregressing delivery counts. Detail reads and publisher pages reject
mismatched identity or invalid pagination. Detached controls cannot invoke
actions; bilingual field labels are separate from editable inputs.

The package picker now preserves pagination even if an early page contains
no eligible invoice, so a later paid package can be reached. No additional
cash charge, audience impression or production publisher activity is inferred.
The connected browser regression forges a detail revision and asserts that
Admin approval is unavailable until a genuine source is loaded. Existing
vendor-create, moderator and signed delivery workflows remain in that suite.

Partial requirements 3.7.5, 3.7.7, 4.4.4, 4.4.5 and 7.1.9; standalone cash
advertising billing and legally authorized live publishing remain outside
this batch. Agent 5 integration and fresh exact-head CI required. No
production acceptance.

## Uncertain campaign creation recovery

Before the first creation POST, the browser saves only its actor-scoped opaque UUID in sessionStorage and freezes the exact request in memory. On an uncertain transport result the original form is removed, replacement creation is blocked, and the operator may retry the exact payload or explicitly recover the created receipt/cancel the unused intent through the existing canonical endpoint. Reload retains only the UUID, allowing recovery without retaining campaign creative, pricing or station data in browser storage. A valid receipt or authoritative unused-intent cancellation clears the UUID; storage failure blocks new creation. The storage is tab-scoped, not cross-device or durable after tab closure; the canonical campaign list remains the source of truth. Connected browser regression drops two actual successful responses, checks identical payload and single campaign ID, reloads, and recovers the original receipt. No new billing path.
