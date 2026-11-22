# Consented referrals and canonical noncash benefits

This implements a referral programme within the canonical Laravel identity, invoice, reward inventory and manual fulfillment records. It creates **no second cash or points ledger**. It is not a charging discount, wallet, payment instruction, physical delivery proof or live partner activation. Whole original2.9.5 credit requires the complete customer web/native journey, applicable exact-source integration tests and honest financial-source readiness review.

## Programme and membership

The dedicated Admin page is `/admin/referral-programs`. A current administrator publishes immutable bilingual names/terms, configured currency/precision, minimum qualifying invoice total, inviter/member reward choices, qualification/referrer limits and a bounded validity period. Both rewards must cover that currency and the complete programme period. The UI uses the existing exact decimal parser; it never rounds an entered amount through floating-point pricing. Publication can be recovered privately by its original actor-bound request key after a lost acknowledgement, without another publication.

Revocation requires an immutable reason and stops future participation/qualification. It does not silently erase previously committed manual benefits. Customer-visible programme projections retain mandatory noncash/qualification flags, exact terms version and truthful activation evidence; Admin review metadata is private. Existing Admin MFA, active-role/credential revalidation and unchanged throttling apply.

A verified active customer explicitly creates a stable, cryptographically random public referral code for a programme. Its disclosure is not authentication or permission to charge. Another verified customer can accept exactly one referral during their account lifetime, before any canonical charging payment, after explicitly consenting to the exact current terms version. Self-referral, changed request identities, stale terms, inactive accounts and unavailable programmes fail closed. No friend name, email, user ID or source invoice ID is disclosed to the inviter.

An unqualified member may withdraw consent. Withdrawal is immutable and reason-bound; it serializes with qualification and permanently blocks a later/delayed qualification. Already-qualified commitments cannot be undone as an unaccepted referral. Existing privacy-rights workflows remain separate.

## Source qualification and atomic inventory

Only the member's first canonical charging-payment invoice, issued after consent, can qualify. It must be fully captured, match programme currency/precision/minimum, and have no refund, lost chargeback, unresolved payment/refund recovery or financial hold. Both participants must remain verified active customers. Qualification respects programme/referrer quotas and the existing reward-stock, per-member and daily limits. Both zero-point manual entitlements commit in one transaction or neither does.

Participant rows are locked in ascending ID order, then the referral/programme, bounded canonical invoice rows and reward rows in stable order. There is one immutable qualification per referral/invoice. Duplicate requests return the original receipt; a different invoice or identity conflicts. Coupons, points redemptions and referrals share the same canonical reward inventory instead of competing counters.

Both benefits remain `pending` manual fulfillment. The existing Admin fulfillment path rechecks the backing referral invoice before delivery attestation. Refunds or unresolved evidence hold fulfillment; rejection releases existing stock according to canonical reward rules. No actual shipment, retail pickup, real payment or physical charging acceptance was performed by these tests.

## Customer API

Mounts remain authenticated `/api` and `/api/v1`, including unchanged cookie CSRF and native token/Admin boundaries. All private responses are `no-store, private`. Lists use25-row pagination and bounded page arguments.

| Method | Relative route | Payload / result |
| --- | --- | --- |
| GET | `commercial/referral-programs?page=1` | Programme paginator: bilingual names/terms, exact `terms_version`, currency/precision, string `minimum_minor`, both reward labels, UTC validity, active/activation/source flags. |
| GET | `commercial/referrals/account?program_id=ID` | Own public code ornull, accepted referral ornull, own aggregate invited/qualified counts, eligibility reason, software activation and `money_movement=false`. No inviter/member PII. |
| POST | `commercial/referrals/codes` | `{program_id,idempotency_key}` →201`{code,program_id}`. Code is `REF-` plus20uppercase hexadecimal characters. |
| POST | `commercial/referrals/accept` | `{code,terms_version,consent:true,idempotency_key}` →201`{referral}`. Exact retries only. |
| POST | `commercial/referrals/qualify` | `{invoice_id,idempotency_key}` →`{referral}`. Exact canonical qualification recovery, no duplicate grants. |
| POST | `commercial/referrals/withdraw` | `{reason}`5–500characters →`{referral}`. Same-reason replay; changed reason conflicts. |
| GET | `commercial/referrals/rewards?page=1` | Own manual-entitlement paginator: IDs, programme, inviter/member role, bilingual reward labels, status/date, current source eligibility; no counterparty or invoice IDs. |

Referral state is `accepted`, `qualified`, `withdrawn` or `expired`. Temporary economics disablement does not rewrite accepted consent to expired. Qualified records retain `{qualification:{request_binding,own_redemption_id,qualified_at}}`. Every benefit exposes `source=REFERRAL`, `points_cost=0`, `cash_value=false`, `fulfillment=MANUAL_NON_MONETARY`, `delivery_verified=false`, and immutable activation/evidence metadata.

Acceptance binding is `sha256(sha256(code)+':'+terms_version+':'+sha256('referral-accept:'+actorId+':'+idempotency_key))`. Qualification binding is `sha256('referral-qualify:'+actorId+':'+referralId+':'+invoiceId+':'+idempotency_key)`. The account projection recovers these exact receipts after transport uncertainty. A null or not-yet-qualified projection is not permission for automatic mutation replay or an invented replacement identity. Canonical other-device state must be represented as such.

Administrative POST publication and `/{programme}/revoke` remain under `commercial/referral-programs`; private POST `commercial/referral-programs/publication-receipt` recovers the current Admin's original publication. The new routes follow the canonical18-digit path bound and reject unsafe numeric IDs in the controller.

## Production software boundary

[Manual noncash activation](MANUAL_NONCASH_ACTIVATION.md) describes the default-deny reviewed capability. Merely enabling the old test flag cannot activate production. Immutable publication evidence prevents imported Sandbox programmes or claims from being relabelled by configuration changes.

Production paid-referral admission additionally requires the separately reviewed live-source-readiness setting. Its predicate accepts only canonical invoice and payment evidence marked `VERIFIED_LIVE`; no client field sets that evidence, and SIM/mock/test receipts cannot qualify even with the flag enabled. The current simulated FinanceService remains production-blocked. Completing the approved live canonical invoice/capture issuer is a **remaining internal finance dependency**, followed by genuine provider/charging acceptance; this document does not reclassify that software gap as credentials-only. No fake live invoice was inserted to manufacture a passing test.
