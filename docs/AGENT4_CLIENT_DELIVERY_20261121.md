# Customer web and native delivery — 21 November 2026

Runtime source: `a287dff52e9f52c094ca365a5aee05aed8989898`, published in [PR200](https://github.com/creabizsalonspa-create/EV/pull/200). Agent5 owns integration and original requirement credits. This is a tested software candidate, not a complete-platform or production release declaration.

## Implemented

- Native customer/vendor account recovery now completes the existing password-reset contract. An explicit pasted reset link must match the configured service origin and expected token/email shape. Secrets are memory-only and clear on background, failure, completion and disposal. Sign-in remains separate and enabled2FA is preserved.
- Hosted checkout now handles a real owned401/419 after the API invalidates credentials, including failed secure deletion. Lifecycle/request generations and later-actor guards prevent stale responses from expiring another account. Canonical payment rules and durable uncertain financial intents are unchanged.
- Owned direct OCPP1.6/2.0.1 inventory opens a typed read-only source-registration snapshot. Historical mapping mismatch, incomplete registration and inspection states remain explicit. Source-record readback never claims a live connection, hardware verification, a granted charging capability or registration authority in the native client.
- Customer web and Flutter now complete the canonical noncash coupon journey: claim, source-bound receipt, private paginated history, uncertain acknowledgement recovery, explicit same-request replay and durable cancellation. A cancellation race recovers an existing accepted claim instead of pretending to undo it. Pending manual fulfillment is not verified delivery.
- Native coupon identities/code drafts use the existing encrypted device intent store. Browser recovery retains only a namespaced opaque identity/binding, never the code, with per-account Web Locks. Re-entering a different code cannot replace an unknown request. Background, account transition and cross-tab changes clear private views and require explicit recovery.
- English/Arabic and narrow RTL layouts are covered. The original partial coupon panels are replaced by the tested customer consumer and a link to Agent3's complete Admin coupon page; existing tiers, points, rewards and fulfillment screens remain intact.

Normal merges preserve licensed discovery PR1973ce88b00, source-registration PR198a9ae2c50 and Agent3 growth8f59a33e, including original PR194 and retail history. The shared Blade resolution retains both Admin navigation links, the direct-unverified station option and the source-registration asset. The coordinated licensed-discovery app navigation hook is verified by its own17cases.

## Executed verification

- Exact runtime source: **823 Flutter cases passed**, **40 explicit opt-in skips**; clean full Flutter analysis.
- Combined Laravel: **1855 passed /32790 assertions**, **one PostgreSQL-only skip**.
- Current focused source/auth/inventory:30cases; coupon consumer:13cases; licensed discovery/navigation:17cases. These overlap the full suite and are not added to its total.
- Actual Laravel native contracts passed for customer+vendor reset (two cases), existing hosted checkout/refund/private-evidence contracts (three), both-protocol source-registration observations (one), and coupon binding/replay/lookup/cancellation (one).
- Customer coupon browser:18controlled EN/AR390checks and14actual Laravel EN/AR390checks. The real test deliberately commits a claim then loses its response, reloads and recovers its original receipt. Native and browser coupon runs assert unchanged canonical financial hashes.
- Backend contract checks: account6/39, hosted/refund/evidence boundaries30/263, commercial4/39. Fixture Pint, eight CI trigger guards and nine pacing controls passed.

Machine-readable results and log digests are in `docs/evidence/agent4-client-delivery-20261121/local.json`. Local raw logs remain under `/workspace/EV-agent4/artifacts/`; no private fixture JSON, credentials, coupon codes or database rows are published. The source-registration native contract uses Agent1's matched local authenticated-CSMS fixture; its metadata is synthetic and is not physical hardware acceptance.

The restored environment's HTTP proxy initially rejected the local Flutter VM websocket. The local test invocation excluded proxies for loopback; application TLS and authentication settings were not changed. The native connected job uses the existing guarded local/mock loyalty test setup before starting its disposable server. Production defaults remain disabled. Existing locked dependencies are installed before fixture drivers run with `--no-pub`.

## Remaining boundaries

Current-source hosted Android debug/iOS simulator and integration gates must pass before canonical approval. Signing/store publication, physical charger/device verification, live payment/roaming/provider activation and deployed-production acceptance remain separate and unclaimed. Agent3 owns commercial economics and coupon/Admin backend rules; Agent1 owns registration and charging authority. Referrals and the upcoming authenticated diagnostics consumer are not included in this frozen candidate. Original broader workflow-parity and hardware requirements receive no automatic credit from these components.

## Retained-source contract now exercised by native CI

The integration review found that the source-registration API test had only a private local fixture producer. Added `tests/native-source-registration.cjs` and a guarded, transactional local SQLite/mock fixture to the connected native sequence and trigger paths. Its deliberately synthetic historical receipts have disabled credentials and a source-generation mismatch; the test must display the current mapping as unverified. Fixture flags explicitly state that no upstream authentication or source write occurred. The driver passed its actual Laravel/native projection for both protocols and verified unchanged retained receipts, commands, sessions and canonical financial storage. The original actual authenticated local-Citrine proof remains separate; this new CI projection test does not replace it or claim hardware acceptance. Fixture credentials remain0600 and are deleted in `finally`.
