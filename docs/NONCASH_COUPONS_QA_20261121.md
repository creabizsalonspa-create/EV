# Noncash reward coupons — Agent3 implementation and QA

Application source `8f59a33ea360497d42e7558080e07edd2be4c912` normally integrates original partial coupon PR194 source `cd319a50a91acca53121e46664a33c6fc1806ecf` without rewriting its history. Admin/backend completion is isolated from retail PR195; Agent5 alone merges canonical development. Customer web/native completion is coordinated with Agent4 and is not credited by these backend/Admin results.

Actual implementation: governed Admin publishing/revocation at `/admin/reward-coupons`; canonical reward choice pagination; immutable coupon terms/claims and reason-bound revocation evidence; private actor-bound publication lookup after a lost response; frozen creation identity; source-limited, stock-safe zero-point manual reward claims; code/request-bound owner receipts; durable uncertain-intent cancellation that serializes with late claims; no plaintext code disclosure from history. EN/AR RTL, persistent existing themes, literal creative rendering, loading/errors, private lifecycle clearing and mobile-width Admin layout are implemented.

Coupons **do not discount charging invoices**, issue stored money, create points or prove reward delivery. The sole source of reward stock, per-member/daily limits, manual fulfillment, rejection and financial holds remains the canonical LoyaltyService. Zero-point claims append no cash or points ledger entries. Commercial production activation stays behind the existing loyalty review gate.

## Contracts

Existing194 customer endpoints are retained: POST`commercial/coupons/claim` with `{code,idempotency_key}` returns200`{claim}`; GET`commercial/coupons/claims` is an owner-only25-row paginator; POST`commercial/coupons/claim-receipt` with `{idempotency_key}` returns`{claim:null|receipt}`. No automatic mutation retry is permitted after an uncertain response. A null lookup is not durable cancellation.

Receipt fields include canonical claim/coupon/redemption IDs, bilingual reward names, UTC`claimed_at`, status`pending|fulfilled|rejected`, `points_cost=0`, `cash_value=false`, `fulfillment=MANUAL_NON_MONETARY`, `source=COUPON`, `evidence=TEST_PROMOTIONAL_ECONOMICS`, `delivery_verified=false` and `request_binding=sha256(sha256(code)+':'+sha256('coupon-claim:'+actorId+':'+idempotency_key))`.

POST`commercial/coupons/claim-intents/cancel` with the original immutable `{code,idempotency_key}` returns `{state:accepted|cancelled,request_binding,claim:null|receipt}`. Accepted commitments recover their receipt; cancelled identities are permanently fenced before any later claim. Both outcomes serialize on the same current customer row. Changed payload conflicts; module-disabled cancellation is allowed because it grants no economic benefit. The customer must retain unknown intent until a conclusive accepted/cancelled response or canonical receipt;404/422 alone do not fence a delayed request.

Admin publishing/listing retains the194 API. Revocation now requires `{reason}`5–500characters, is terminal/reason-bound, and stops **new** claims while preserving existing manual commitments. POST`commercial/coupons/publication-receipt` with `{idempotency_key}` privately resolves an Admin's original publication without the raw code or another POSTpublication. Codes are uppercase letters/digits/hyphens,12–40characters; the server stores only their hash.

The new **additive** hardening migration preserves already-applied194 coupon and claim rows byte-for-byte. Imported old revocations have a null actor and explicit`legacy_observation=true`; no reviewer or reason is fabricated. SQLite/PostgreSQL triggers protect terms, terminal revocation and append-only evidence. Populated non-testing downgrades refuse to discard commitments or cancellation tombstones. Isolated original development SQLite data was retained; no original repository journals or source receipts were reset.

## Actual local verification

| Check | Passed evidence |
| --- | --- |
| Full Laravel |1789passed,1PG-onlyskip,32233assertions|
| Focused coupon/loyalty |28passed,223assertions|
| Financial PostgreSQL features |464passed,1SQLite-onlyskip,11367assertions|
| Full PostgreSQL process/migration matrix |106passed,1550assertions|
| Real Admin Laravel/browser |12checks: publication committed/lost reply resolved without repost, canonical claim, cryptographic binding, revocation and old receipt replay, role denial, literal creative, EN/AR/RTL/theme/mobile|
| Paced orchestration |9passed; dedicated unchanged Admin-coupon auth window, every original suite retained|
| Pint |851files passed|

Process coverage includes simultaneous identical claims, last coupon-unit contention, coupon versus points redemption competing for the same last canonical stock, and claim/cancellation racing to exactly one durable result. Feature tests cover safe populated-source migration, honest legacy imports, source-role/replay boundaries, and production/module-off safety. The [evidence manifest](evidence/noncash-coupons-20261121/local.json) hashes raw logs and a synthetic-only screenshot.

Whole original2.9.4 credit remains pending tested customer web/native integration, applicable exact-head hosted gates and Agent5 requirement review. Monetary invoice discounts, cash-priced promotions, legally approved wallet/stored value, real merchant fulfillment, live PSP/retail operation and production acceptance are not supplied or credited by these noncash coupons. Referral2.9.5 code is a separate ongoing source branch and is not covered by this result set.
