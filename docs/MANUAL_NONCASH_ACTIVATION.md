# Reviewed manual noncash capability

This is a default-deny production-software capability for canonical **zero-point manual reward inventory**, not an activation performed during development. It does not enable payments, invoice discounts, stored value, positive-point spending, automated delivery, charging commands or external partners. All actual local/browser fixtures remain Sandbox. Production-mode contracts were exercised only in isolated test processes and disposable databases.

## Configuration and authority

The existing guarded `scripts/loyalty-test-setup` is unchanged. `LOYALTY_TEST_ENABLED=true` retains existing Sandbox behavior outside production. That flag alone is ignored for production activation. Explicit reviewed manual mode requires all of:

- `LOYALTY_NONCASH_MODE=reviewed_manual`.
- A nonempty, bounded `LOYALTY_NONCASH_APPROVAL_REFERENCE` identifying the deployment owner's actual reviewed record.
- A lowercase64-character `LOYALTY_NONCASH_TERMS_SHA256` for the approved terms version.
- The literal boolean `LOYALTY_NONCASH_FULFILLMENT_REVIEWED=true`.

All new defaults in `.env.example` are disabled/empty. These inputs are a trusted operator declaration; the application cannot independently certify legal endorsement, stock availability or real fulfillment. Before any real deployment activation, the owner must approve the actual programme terms, applicable consumer/privacy/cancellation obligations, responsible fulfillment operators and inventory process. No such real approval or activation was supplied or inferred from a synthetically formatted test reference.

Existing current-user/role/credential guards, Admin MFA, CSRF, throttling, immutable audit records, stock and daily limits still apply. Configuration cannot grant an Admin identity or bypass those controls. `GET commercial/noncash/status` exposes a bounded private capability projection; approval references stay on private Admin record views, not customer receipts.

## What reviewed mode supports

An authorized Admin may publish noncash catalog/policy records and coupons. Customers may claim a zero-point manual entitlement. An authorized Admin may attest manual fulfillment/rejection through the existing canonical redemption workflow. Production requires a canonical coupon/referral source link for that zero-point commitment; an arbitrary free redemption row cannot acquire authority.

Positive-point earning/redemption and positive-point fulfillment remain blocked by the original production restrictions. Simulated FinanceService payment/refund/invoice guards are unchanged. Historical point balances remain readable, but `points_actions_enabled=false` and `spendable_points=0` prevent representing Sandbox points as production purchasing power. A member with legacy point sources requires reviewed reconciliation before manual-mode entitlement creation; no points or money are silently rewritten or discarded.

Manual fulfillment status is an internal reviewed attestation, not independent physical delivery proof. `delivery_verified=false`, `cash_value=false` and `money_movement=false` remain truthful. Actual delivery/provider/hardware acceptance is separate.

## Immutable provenance and recovery

Each coupon/programme publication atomically appends its activation mode, approval reference and terms digest into a protected FK-linked evidence record. Claims and entitlements remain anchored to their immutable original publication. Old records from the earlier hard-blocked implementation remain Sandbox when evidence is absent; no runtime flag relabels them.

Customer projections use `activation_mode=sandbox|reviewed_manual`, `activation_recorded`, and `evidence=TEST_PROMOTIONAL_ECONOMICS|REVIEWED_MANUAL_NONCASH`. Reviewed evidence means the captured operator configuration, not actual legal/production acceptance. Sandbox records cannot authorize reviewed production claims or fulfillment. Review-reference/terms rotation blocks new commitments on the old publication; existing approved commitments retain their original receipt and manual terms rather than being rewritten. Cancellation/receipt recovery remains possible without granting a new benefit.

## Referral financial-source prerequisite

Production referrals separately default to disabled through `LOYALTY_REFERRAL_VERIFIED_LIVE_SOURCE_READY=false`. Enabling manual coupons does not imply that charging receipts are live-qualified. The separate setting is a declaration of a genuinely reviewed canonical live invoice/capture integration, never evidence by itself.

Even with that setting, referral qualification rejects any invoice or payment marked SIMULATED/mock/test. It requires trusted canonical `VERIFIED_LIVE` records and all existing capture/refund/uncertainty checks. No incoming customer field can set that evidence. The current simulated canonical issuer remains production-blocked; the approved live issuer/capture software is still an internal implementation dependency and real partner/provider acceptance remains external. This capability neither implements nor claims that missing issuer.

## Verification boundary

Production-domain tests cover default denial, incomplete review configuration, real zero-point source-linked fulfillment under complete synthetic review configuration, legacy/Sandbox isolation, immutable evidence, original-receipt preservation, point/test-money rejection, Admin MFA denial, separate referral readiness and SIM-source rejection. They do not perform production deployment, call a PSP, dispatch a charger or deliver goods.

Broader monetary coupons/discount pricing, real fulfillment networks, approved live finance adapters, wallet/stored-value design and legal acceptance remain outside this manual-noncash slice. Whole original2.9.4/2.9.5 credit requires the named product scope, complete web/native consumers, exact-source integrated testing and Agent5 review; no percentage or atom is credited by this document alone.
