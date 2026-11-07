# Loyalty economics and non-monetary rewards

This module implements promotional points on the canonical financial source. Points are **not money**, transferable balances, a wallet, stored value, a payment method or a tariff discount. No cash movement, partner delivery or commercial activation is implied. `LOYALTY_TEST_ENABLED=false` by default and production rejects mutations. Activation needs reviewed campaign economics, consumer terms, privacy/abuse controls and actual reward supply/fulfillment agreements. Contract/software tests cannot establish those external approvals.

## Earning policies and tiers

Administrators publish immutable currency/precision-specific policy versions. Fields are `currency`, `minor_units`, `enabled`, `points_numerator`, `minor_denominator`, `gold_threshold`, `platinum_threshold`, `gold_multiplier_bps`, `platinum_multiplier_bps`, `invoice_cap`, `daily_cap`, `expiry_days`, `daily_redemption_limit`, `effective_from`, and a payload-bound `idempotency_key`. Publish an effective disabled successor to stop future earning/redemption for that currency. Existing grants retain their original immutable version and economic terms. Historical effective dates are an explicit administrator configuration choice; publishing does not rewrite or duplicate historical grants. Currency precision must match one unambiguous configured region precision.

Only a fully paid canonical **charging invoice** with a positive pre-tax subtotal qualifies. Unpaid/part-paid invoices, pending refund requests, open disputes and unresolved PSP recovery are ineligible. Neither a browser success response nor a checkout authorization can create points. Owners or administrators explicitly claim the invoice once; invoice uniqueness and a locked member row serialize duplicate requests. This module does not edit charger, fleet, pricing, invoice, payment or cash-journal source.

Points use integer floor arithmetic: floor(subtotal × numerator / denominator), followed by floor(base × snapshotted tier multiplier /10000), subject to invoice and member daily caps. Silver has10000bps; Gold and Platinum have published bounded multipliers. Before each grant, the server derives the tier from net qualified lifetime points and the invoice-effective policy's thresholds. Policy threshold/multiplier limits prevent negative or fractional economics; arithmetic overflow fails closed. Caps count original granted units, including subsequently refunded or expired grants, so refunds cannot reopen that day's earning allowance. Daily limits use the application's configured timezone (UTC by default).

Points form one non-monetary promotional unit across configured earning currencies. `reward.currency` chooses the applicable current redemption policy; it is not a cash price or exchange rate. The account exposes `tier_by_currency` with the current published thresholds, while each grant records its original earning tier/multiplier.

## Refunds, expiration and abuse controls

An append-only points ledger records earning, reversal, expiry, redemption and rejection release. Grant economics, ledger entries, lot allocations, policies, rewards and redemption audit events have PostgreSQL/SQLite mutation guards. Redemption identity/economic terms are also protected at database level.

Refunds proportionally reduce the original grant using the canonical refunded pre-tax components. A confirmed lost external chargeback disqualifies the entire promotional invoice; this intentionally conservative anti-abuse policy never fabricates a cash refund or cash journal. Open refund/dispute/uncertain PSP evidence freezes redemption for the member until authoritative resolution. Chargeback and refund changes are derived from canonical tables, not client flags. A source reversal is never used to silently reactivate previously clawed-back points.

`GET account` does not mutate records: it exposes posted balance, pending reconciliation, and conservative spendable points. Explicit reconciliation posts deterministic append-only reversal/expiry entries under member/invoice locks. Every new redemption and administrator fulfillment also rechecks the authoritative source, so a stale displayed balance cannot authorize spending. Refunds after spending may create **negative points debt**, which blocks further redemption and pending fulfillment. This debt is not a customer monetary debt.

Lots expire after their snapshotted term. Redemptions allocate earliest-expiring available lots. Already expired units are not clawed back twice; consumed units can still be reversed after a source refund. Rejected pending rewards restore their original points through a release entry, then immediately apply applicable refunds/expiration. Rejection cannot revive expired grants. Explicit reconciliation is currently request-driven; this module does not claim an autonomous deployed loyalty worker.

## Reward fulfillment

Administrators publish immutable bilingual non-monetary catalog records with `name`, `name_ar`, `description`, `description_ar`, `currency`, `points_cost`, `stock`, `per_user_limit`, `starts_at`, `ends_at`, and `idempotency_key`. Inventory, member limits and daily attempt limits are enforced under member/catalog locks. Pending and fulfilled requests consume stock; rejected requests release it. Every attempt counts toward the daily redemption cap, including rejected attempts.

A redemption starts `pending`; it is **not a delivered benefit**. Administrators may `fulfill` only after actual reviewed manual delivery/collection evidence, using an audit reason, or `reject` and release the reservation. The status cannot be silently rewritten after a decision, and a retry with a changed decision reason is rejected. No retail partner, entitlement delivery, third-party fulfillment adapter or physical product receipt has been verified by this code. Platform terms and actual reward availability must be approved before production activation.

## Authenticated API contract

Mount `routes/loyalty.php` under existing authenticated `/api` and `/api/v1` groups. No separate identity or cash ledger is introduced. Cross-member resources return404; publication and fulfillment require the current administrator role. List responses use standard25-item Laravel pagination.

- `GET /commercial/loyalty/account`: own `{user_id,balance_points,pending_reconciliation_points,spendable_points,qualified_lifetime_points,redemption_hold,tier_by_currency,unit:"NON_MONETARY_POINTS",cash_value:false,transferable:false,evidence:"TEST_PROMOTIONAL_ECONOMICS"}`. `tier_by_currency` is an object, e.g. `{AED:{tier:"Gold",gold_threshold:100,platinum_threshold:500,policy_id:1,enabled:true}}`.
- `GET /commercial/loyalty/ledger`: own immutable points entries, paginated.
- `GET /commercial/loyalty/policies`: published economics, paginated; fingerprints and request keys omitted. `POST` administrator publication returns201 `{policy}`.
- `GET /commercial/loyalty/rewards`: current catalog (administrators can also inspect past/future entries), paginated, with `available_stock`, `fulfillment:"MANUAL_NON_MONETARY"`, `cash_value:false`. `POST` administrator publication returns201 `{reward}`.
- `POST /commercial/loyalty/invoices/{invoice}/earn`: empty body; owner/admin, returns201 `{award,account}`. Canonical invoice identity makes retries idempotent; `award.points` is original grant and `account` reflects subsequent reversals.
- `POST /commercial/loyalty/reconcile`: empty body, own account; `{account}`.
- `POST /commercial/loyalty/rewards/{reward}/redeem`: `{idempotency_key}`, returns201 `{redemption,account}`. Changed reward/key payload returns409.
- `GET /commercial/loyalty/redemptions`: owner records, or all financial administration records, paginated.
- `GET /commercial/loyalty/redemptions/{id}`: `{redemption,reward,events}` scoped to owner/admin.
- `POST /commercial/loyalty/redemptions/{id}/{fulfill|reject}`: administrator `{reason}`5–255characters; `{redemption}`. States `pending`, `fulfilled`, `rejected`.

## Verification

`LoyaltyEconomicsTest` exercises authenticated earning, net refunds, repeat reconciliation, inventory/lot reservation, idempotency, tier multipliers, caps, expiry, post-redemption debt, fulfillment/rejection, tenant privacy, validation, immutable ledger and production rejection. `LoyaltyConcurrencyTest` uses independent PHP processes on a guarded disposable PostgreSQL database for duplicate earning, duplicate redemption and two-member last-stock contention. Actual PostgreSQL/browser run evidence must be recorded by the integration runner before claiming those checks passed. No live merchant, reward partner or fulfillment acceptance is claimed.
