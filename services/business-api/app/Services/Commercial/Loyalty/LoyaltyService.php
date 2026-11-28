<?php

namespace App\Services\Commercial\Loyalty;

use App\Models\User;
use App\Services\Commercial\Referrals\ReferralSource;
use App\Services\Retail\PartnerRewardGovernance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Non-transferable promotional units. Never a wallet, payment or competing cash ledger. */
class LoyaltyService
{
    private function enabled(): void
    {
        abort_unless(config('loyalty.enabled') && ! app()->environment('production'), 503, 'Loyalty test economics are disabled; commercial activation requires review.');
    }

    private function floor(int $value, int $numerator, int $denominator): int
    {
        abort_unless($value >= 0 && $numerator >= 0 && $denominator > 0 && ($numerator === 0 || $value <= intdiv(PHP_INT_MAX, $numerator)), 409, 'Points arithmetic overflow or invalid source.');

        return intdiv($value * $numerator, $denominator);
    }

    private function lockedAdmin(User|int $snapshot): int
    {
        $current = User::whereKey($snapshot instanceof User ? $snapshot->id : $snapshot)->lockForUpdate()->firstOrFail();
        abort_unless($current->role === 'admin' && $current->account_status === 'active', 403, 'Active Admin authorization is required.');
        if ($snapshot instanceof User) {
            abort_unless($snapshot->role === $current->role
                && (int) $snapshot->auth_epoch === (int) $current->auth_epoch
                && hash_equals((string) $snapshot->password, (string) $current->password), 409, 'Account credentials changed; authenticate again.');
        }

        return $current->id;
    }

    public function publish(string $table, User|int $actor, array $values, string $key): object
    {
        app(NoncashActivation::class)->requireEnabled();
        abort_unless(in_array($table, ['loyalty_policies', 'loyalty_rewards'], true), 422);

        return DB::transaction(function () use ($table, $actor, $values, $key) {
            $actor = $this->lockedAdmin($actor);
            $request = hash('sha256', $actor.':'.$key);
            $fingerprint = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            if ($old = DB::table($table)->where('request_key', $request)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Publication key payload changed.');

                return $old;
            }
            if ($table === 'loyalty_policies') {
                $precisions = DB::table('regions')->where('currency', $values['currency'])->pluck('minor_units')->map(fn ($v) => (int) $v)->unique();
                abort_unless($precisions->count() === 1 && $precisions->first() === $values['minor_units'], 409, 'Configured currency precision is ambiguous.');
            }
            $id = DB::table($table)->insertGetId($values + ['created_by' => $actor, 'request_key' => $request, 'fingerprint' => $fingerprint, 'created_at' => now()]);
            $this->audit($actor, 'loyalty.published', $table.':'.$id);

            return DB::table($table)->find($id);
        }, 3);
    }

    private function policy(string $currency, mixed $at): ?object
    {
        return DB::table('loyalty_policies')->where('currency', $currency)->where('effective_from', '<=', $at)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    private function source(object $invoice): array
    {
        $paid = (int) DB::table('finance_payments')->where('invoice_id', $invoice->id)->sum('amount_minor');
        $refund = (int) DB::table('finance_refunds')->where('invoice_id', $invoice->id)->selectRaw('COALESCE(SUM(vendor_minor + commission_minor),0) AS subtotal')->value('subtotal');
        $uncertain = DB::table('payment_recovery_intents as p')->join('gateway_checkouts as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', 'charging')->where('c.invoice_id', $invoice->id)->whereIn('p.status', ['executing', 'uncertain', 'needs_review'])->exists();
        $hold = $uncertain || DB::table('gateway_cases')->where('invoice_id', $invoice->id)->where('status', 'open')->exists() || DB::table('finance_refund_requests')->where('invoice_id', $invoice->id)->where('status', 'pending')->exists();
        // A confirmed lost chargeback disqualifies this entire promotional source;
        // it never manufactures a cash refund or alters the invoice's ledger.
        $lost = DB::table('external_disputes as d')->join('gateway_checkouts as c', 'c.id', '=', 'd.checkout_id')->where('c.invoice_id', $invoice->id)->where('d.status', 'lost')->exists();

        return ['qualified' => $paid === (int) $invoice->total_minor && $invoice->total_minor > 0 && $invoice->subtotal_minor > 0 && ! $hold, 'remaining_minor' => $lost ? 0 : max(0, (int) $invoice->subtotal_minor - $refund), 'hold' => $hold];
    }

    private function target(object $award): int
    {
        $invoice = DB::table('finance_invoices')->find($award->invoice_id);
        $source = $this->source($invoice);

        return min((int) $award->points, $this->floor((int) $award->points, $source['remaining_minor'], (int) $award->qualifying_minor));
    }

    private function consumed(int $award): int
    {
        return (int) DB::table('loyalty_allocations as a')->join('loyalty_redemptions as r', 'r.id', '=', 'a.redemption_id')->where('a.award_id', $award)->whereIn('r.status', ['pending', 'fulfilled'])->sum('a.points');
    }

    private function deducted(int $award, string $kind): int
    {
        return -(int) DB::table('loyalty_ledger')->where('award_id', $award)->where('kind', $kind)->sum('points');
    }

    private function remaining(object $award): int
    {
        return max(0, (int) $award->points - $this->deducted($award->id, 'reversal') - $this->deducted($award->id, 'expiry') - $this->consumed($award->id));
    }

    private function entry(int $user, ?int $award, ?int $redemption, string $kind, int $points, int $actor): void
    {
        if ($points !== 0) {
            DB::table('loyalty_ledger')->insert(['user_id' => $user, 'award_id' => $award, 'redemption_id' => $redemption, 'kind' => $kind, 'points' => $points, 'actor_id' => $actor, 'created_at' => now()]);
        }
    }

    /** Parent holds the user lock; canonical refund writers serialize on these invoice rows. */
    private function reconcileLocked(int $user, int $actor): void
    {
        foreach (DB::table('loyalty_awards')->where('user_id', $user)->orderBy('invoice_id')->get() as $award) {
            DB::table('finance_invoices')->where('id', $award->invoice_id)->lockForUpdate()->first();
            $expired = $this->deducted($award->id, 'expiry');
            $desired = min((int) $award->points - $expired, (int) $award->points - $this->target($award));
            $delta = max(0, $desired - $this->deducted($award->id, 'reversal'));
            $this->entry($user, $award->id, null, 'reversal', -$delta, $actor);
            if (Carbon::parse($award->expires_at)->lte(now())) {
                $this->entry($user, $award->id, null, 'expiry', -$this->remaining($award), $actor);
            }
        }
    }

    public function reconcile(int $user, int $actor): array
    {
        $this->enabled();

        return DB::transaction(function () use ($user, $actor) {
            User::whereKey($user)->lockForUpdate()->firstOrFail();
            $this->reconcileLocked($user, $actor);

            return $this->account($user);
        }, 3);
    }

    public function account(int $user): array
    {
        $awards = DB::table('loyalty_awards')->where('user_id', $user)->get();
        $qualified = 0;
        $pending = 0;
        $held = false;
        foreach ($awards as $award) {
            $qualified += $this->target($award);
            $expired = $this->deducted($award->id, 'expiry');
            $desired = min((int) $award->points - $expired, (int) $award->points - $this->target($award));
            $pending += max(0, $desired - $this->deducted($award->id, 'reversal'));
            $pending += Carbon::parse($award->expires_at)->lte(now()) ? max(0, $this->remaining($award) - max(0, $desired - $this->deducted($award->id, 'reversal'))) : 0;
            $held = $held || $this->source(DB::table('finance_invoices')->find($award->invoice_id))['hold'];
        }
        $balance = (int) DB::table('loyalty_ledger')->where('user_id', $user)->sum('points');

        $tiers = [];
        foreach (DB::table('loyalty_policies')->where('effective_from', '<=', now())->orderByDesc('effective_from')->orderByDesc('id')->get() as $policy) {
            if (! isset($tiers[$policy->currency])) {
                $tiers[$policy->currency] = ['tier' => $qualified >= (int) $policy->platinum_threshold ? 'Platinum' : ($qualified >= (int) $policy->gold_threshold ? 'Gold' : 'Silver'), 'gold_threshold' => (int) $policy->gold_threshold, 'platinum_threshold' => (int) $policy->platinum_threshold, 'policy_id' => $policy->id, 'enabled' => (bool) $policy->enabled];
            }
        }

        return ['tier_by_currency' => (object) $tiers, 'user_id' => $user, 'balance_points' => $balance, 'pending_reconciliation_points' => $pending, 'spendable_points' => $held || ! app(NoncashActivation::class)->legacyPointsEnabled() ? 0 : max(0, $balance - $pending), 'qualified_lifetime_points' => $qualified, 'redemption_hold' => $held, 'unit' => 'NON_MONETARY_POINTS', 'cash_value' => false, 'transferable' => false, 'evidence' => 'TEST_PROMOTIONAL_ECONOMICS', 'points_actions_enabled' => app(NoncashActivation::class)->legacyPointsEnabled()];
    }

    public function earn(int $invoiceId, int $actor): object
    {
        $this->enabled();
        $hint = DB::table('finance_invoices')->find($invoiceId);
        abort_unless($hint, 404);

        return DB::transaction(function () use ($hint, $actor) {
            User::whereKey($hint->user_id)->lockForUpdate()->firstOrFail();
            $invoice = DB::table('finance_invoices')->where('id', $hint->id)->lockForUpdate()->first();
            $this->reconcileLocked($invoice->user_id, $actor);
            if ($old = DB::table('loyalty_awards')->where('invoice_id', $invoice->id)->first()) {
                return $old;
            }
            $source = $this->source($invoice);
            abort_unless($source['qualified'] && $source['remaining_minor'] > 0, 409, 'Only fully captured canonical invoices without unresolved payment, refund or dispute evidence qualify.');
            $policy = $this->policy($invoice->currency, $invoice->created_at);
            abort_unless($policy && $policy->enabled && (int) $policy->minor_units === (int) $invoice->minor_units, 409, 'No enabled effective earning policy for this invoice currency/precision.');
            $qualified = $this->account($invoice->user_id)['qualified_lifetime_points'];
            $tier = $qualified >= (int) $policy->platinum_threshold ? 'Platinum' : ($qualified >= (int) $policy->gold_threshold ? 'Gold' : 'Silver');
            $multiplier = $tier === 'Platinum' ? (int) $policy->platinum_multiplier_bps : ($tier === 'Gold' ? (int) $policy->gold_multiplier_bps : 10000);
            $base = $this->floor((int) $invoice->subtotal_minor, (int) $policy->points_numerator, (int) $policy->minor_denominator);
            $daily = (int) DB::table('loyalty_awards')->where('user_id', $invoice->user_id)->where('created_at', '>=', now()->startOfDay())->sum('points');
            $points = min((int) $policy->invoice_cap, max(0, (int) $policy->daily_cap - $daily), $this->floor($base, $multiplier, 10000));
            abort_unless($points > 0, 409, 'No qualifying points remain within the published daily/invoice limits.');
            $id = DB::table('loyalty_awards')->insertGetId(['user_id' => $invoice->user_id, 'invoice_id' => $invoice->id, 'policy_id' => $policy->id, 'qualifying_minor' => $invoice->subtotal_minor, 'points' => $points, 'tier' => $tier, 'multiplier_bps' => $multiplier, 'expires_at' => now()->addDays((int) $policy->expiry_days), 'created_at' => now()]);
            $this->entry($invoice->user_id, $id, null, 'earn', $points, $actor);
            $award = DB::table('loyalty_awards')->find($id);
            // Sources partly refunded before enrollment receive proportional net points immediately.
            $this->reconcileLocked($invoice->user_id, $actor);
            $this->audit($actor, 'loyalty.earned', (string) $id);

            return $award;
        }, 3);
    }

    public function redeem(int $user, int $rewardId, int $actor, string $key): object
    {
        $this->enabled();

        return DB::transaction(function () use ($user, $rewardId, $actor, $key) {
            User::whereKey($user)->lockForUpdate()->firstOrFail();
            $request = hash('sha256', $user.':'.$key);
            if ($old = DB::table('loyalty_redemptions')->where('request_key', $request)->first()) {
                abort_unless((int) $old->reward_id === $rewardId, 409, 'Redemption request key payload changed.');

                return $old;
            }
            $this->reconcileLocked($user, $actor);
            $account = $this->account($user);
            $reward = DB::table('loyalty_rewards')->where('id', $rewardId)->lockForUpdate()->first();
            abort_unless($reward && $reward->starts_at <= now()->toDateTimeString() && $reward->ends_at > now()->toDateTimeString(), 409, 'Reward is unavailable.');
            $active = DB::table('loyalty_redemptions')->where('reward_id', $rewardId)->whereIn('status', ['pending', 'fulfilled']);
            abort_unless((clone $active)->count() < (int) $reward->stock && (clone $active)->where('user_id', $user)->count() < (int) $reward->per_user_limit, 409, 'Reward inventory or per-member limit reached.');
            $policy = $this->policy($reward->currency, now());
            abort_unless($policy && $policy->enabled, 409, 'Reward redemption is disabled by policy.');
            $today = DB::table('loyalty_redemptions')->where('user_id', $user)->where('created_at', '>=', now()->startOfDay())->count();
            abort_unless($today < (int) $policy->daily_redemption_limit && ! $account['redemption_hold'] && $account['spendable_points'] >= (int) $reward->points_cost, 409, 'Insufficient available points, unresolved financial hold or daily redemption limit.');
            $id = DB::table('loyalty_redemptions')->insertGetId(['user_id' => $user, 'reward_id' => $rewardId, 'points_cost' => $reward->points_cost, 'status' => 'pending', 'request_key' => $request, 'created_at' => now(), 'updated_at' => now()]);
            $left = (int) $reward->points_cost;
            foreach (DB::table('loyalty_awards')->where('user_id', $user)->where('expires_at', '>', now())->orderBy('expires_at')->orderBy('id')->get() as $award) {
                $allocated = min($left, $this->remaining($award));
                if ($allocated > 0) {
                    DB::table('loyalty_allocations')->insert(['redemption_id' => $id, 'award_id' => $award->id, 'points' => $allocated]);
                    $left -= $allocated;
                }
                if ($left === 0) {
                    break;
                }
            }
            abort_unless($left === 0, 409, 'Eligible points lots do not reconcile.');
            $this->entry($user, null, $id, 'redeem', -(int) $reward->points_cost, $actor);
            $this->redemptionEvent($id, $actor, 'requested', 'Pending manual non-monetary reward fulfillment.');

            return DB::table('loyalty_redemptions')->find($id);
        }, 3);
    }

    public function claimCoupon(int $user, ?string $code, string $key, ?int $listedCoupon = null): object
    {
        app(NoncashActivation::class)->requireEnabled();

        return DB::transaction(function () use ($user, $code, $key, $listedCoupon) {
            $customer = User::whereKey($user)->lockForUpdate()->firstOrFail();
            abort_unless($customer->role === 'customer' && $customer->account_status === 'active', 403);
            $request = hash('sha256', 'coupon-claim:'.$user.':'.$key);
            $hash = $code === null ? null : hash('sha256', $code);
            if ($old = DB::table('loyalty_coupon_claims')->where('request_key', $request)->first()) {
                $coupon = DB::table('loyalty_coupons')->find($old->coupon_id);
                abort_unless($listedCoupon !== null
                    ? $coupon->listed && (int) $coupon->id === $listedCoupon
                    : $hash !== null && hash_equals($coupon->code_hash, $hash), 409, 'Coupon retry content changed.');

                return $old;
            }

            abort_if(DB::table('loyalty_coupon_claim_intents')->where('request_key', $request)->exists(), 409, 'This coupon claim intent was explicitly cancelled.');
            $query = DB::table('loyalty_coupons');
            $coupon = ($listedCoupon === null
                ? $query->where('code_hash', $hash)
                : $query->where('id', $listedCoupon)->where('listed', true))->lockForUpdate()->first() ?? abort(404);
            abort_unless($coupon->revoked_at === null, 409, 'Coupon is unavailable.');
            app(NoncashActivation::class)->requireUsable('coupon', (int) $coupon->id);
            $claims = DB::table('loyalty_coupon_claims')->where('coupon_id', $coupon->id);
            abort_unless((clone $claims)->count() < $coupon->claim_limit
                && (clone $claims)->where('user_id', $user)->count() < $coupon->per_user_limit, 409, 'Coupon limit reached.');

            $redemption = $this->reserveNoncashRewardLocked($user, (int) $coupon->reward_id, $request,
                'Coupon entitlement pending manual reward fulfillment.', coupon: $coupon);
            $now = now()->utc();
            $id = DB::table('loyalty_coupon_claims')->insertGetId([
                'coupon_id' => $coupon->id, 'user_id' => $user, 'redemption_id' => $redemption,
                'request_key' => $request, 'created_at' => $now,
            ]);
            $this->audit($user, 'loyalty.coupon.claimed', (string) $id);

            return DB::table('loyalty_coupon_claims')->find($id);
        }, 3);
    }

    private function reserveNoncashRewardLocked(int $user, int $rewardId, string $request, string $reason, ?int $actor = null, ?object $coupon = null): int
    {
        $activation = app(NoncashActivation::class);
        $activation->requirePointIsolation($user);
        if ($activation->legacyPointsEnabled()) {
            $this->reconcileLocked($user, $actor ?? $user);
        }
        $account = $this->account($user);
        $reward = DB::table('loyalty_rewards')->where('id', $rewardId)->lockForUpdate()->first();
        $now = now()->utc();
        abort_if($coupon && ($coupon->starts_at > $now->toDateTimeString()
            || $coupon->ends_at <= $now->toDateTimeString()), 409, 'Coupon is unavailable.');
        abort_unless($reward && $reward->starts_at <= $now->toDateTimeString() && $reward->ends_at > $now->toDateTimeString(), 409, 'Reward is unavailable.');
        $active = DB::table('loyalty_redemptions')->where('reward_id', $reward->id)->whereIn('status', ['pending', 'fulfilled']);
        abort_unless((clone $active)->count() < $reward->stock
            && (clone $active)->where('user_id', $user)->count() < $reward->per_user_limit, 409, 'Reward inventory or per-member limit reached.');
        $policy = $this->policy($reward->currency, $now);
        $today = DB::table('loyalty_redemptions')->where('user_id', $user)->where('created_at', '>=', $now->copy()->startOfDay())->count();
        abort_unless($policy && $policy->enabled && $today < $policy->daily_redemption_limit
            && ! $account['redemption_hold'] && $account['balance_points'] >= 0, 409, 'Reward policy or account hold prevents this claim.');

        $redemption = DB::table('loyalty_redemptions')->insertGetId([
            'user_id' => $user, 'reward_id' => $reward->id, 'points_cost' => 0,
            'status' => 'pending', 'request_key' => $request, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->redemptionEvent($redemption, $actor ?? $user, 'requested', $reason);

        return $redemption;
    }

    public function claimPartnerReward(int $user, int $review, string $key): object
    {
        $activation = app(NoncashActivation::class);
        abort_unless($activation->legacyPointsEnabled(), 503, 'Synthetic partner rewards are disabled.');

        return DB::transaction(function () use ($user, $review, $key) {
            $customer = User::whereKey($user)->lockForUpdate()->firstOrFail();
            abort_unless($customer->role === 'customer' && $customer->account_status === 'active' && $customer->email_verified_at !== null, 403);
            $request = $this->partnerRewardClaimKey($user, $key);
            $claims = DB::table('partner_reward_claims')->where('user_id', $user);
            if ($prior = (clone $claims)->where('request_key', $request)->first()) {
                abort_unless((int) $prior->review_id === $review, 409, 'Partner reward retry content changed.');

                return $prior;
            }

            abort_if(DB::table('partner_reward_cancelled_intents')->where('request_key', $request)->exists(),
                409, 'Partner reward claim intent was cancelled or its content changed.');

            [$offer, $event] = app(PartnerRewardGovernance::class)->lockEligibleSandboxReview($review);
            $redemption = $this->reserveNoncashRewardLocked($user, (int) $offer->reward_id, $request,
                'Synthetic partner reward pending manual noncash fulfillment.');
            $id = DB::table('partner_reward_claims')->insertGetId([
                'review_id' => $offer->id, 'approval_event_id' => $event->id, 'user_id' => $user,
                'redemption_id' => $redemption, 'request_key' => $request, 'created_at' => now()->utc(),
            ]);
            $this->audit($user, 'loyalty.partner_reward.claimed', (string) $id);

            return $claims->where('id', $id)->firstOrFail();
        }, 3);
    }

    /**
     * Resolve an uncertain intent under the same member lock as claim().
     * A committed claim wins; otherwise cancellation fences delayed dispatch.
     * No inventory, points, fulfillment, provider or cash mutation is made.
     */
    public function cancelPartnerRewardIntent(int $user, int $review, string $key): array
    {
        return DB::transaction(function () use ($user, $review, $key) {
            $customer = User::whereKey($user)->lockForUpdate()->firstOrFail();
            abort_unless($customer->role === 'customer' && $customer->account_status === 'active', 403);
            $request = $this->partnerRewardClaimKey($user, $key);
            $claim = DB::table('partner_reward_claims')->where('user_id', $user)
                ->where('request_key', $request)->first();
            if ($claim) {
                abort_unless((int) $claim->review_id === $review, 409, 'Partner reward intent content changed.');

                return ['state' => 'accepted', 'claim' => $claim];
            }

            $prior = DB::table('partner_reward_cancelled_intents')->where('user_id', $user)
                ->where('request_key', $request)->first();
            if ($prior) {
                abort_unless((int) $prior->review_id === $review, 409, 'Partner reward intent content changed.');
            } else {
                $id = DB::table('partner_reward_cancelled_intents')->insertGetId([
                    'user_id' => $user, 'review_id' => $review, 'request_key' => $request,
                    'created_at' => now()->utc(),
                ]);
                $this->audit($user, 'loyalty.partner_reward.intent_cancelled', (string) $id);
            }

            return ['state' => 'cancelled', 'claim' => null];
        }, 3);
    }

    public function findPartnerRewardClaim(int $user, string $key): ?object
    {
        return DB::table('partner_reward_claims')->where('user_id', $user)
            ->where('request_key', $this->partnerRewardClaimKey($user, $key))->first();
    }

    private function partnerRewardClaimKey(int $user, string $key): string
    {
        return hash('sha256', 'partner-reward-claim:'.$user.':'.$key);
    }

    /** Read-only canonical receipt/refund/uncertainty evidence; never a new ledger. */
    public function promotionSource(object $invoice): array
    {
        return $this->source($invoice);
    }

    public function reserveReferralReward(int $qualificationId, string $role): object
    {
        app(NoncashActivation::class)->requireEnabled();
        abort_unless(in_array($role, ['referrer', 'member'], true), 422);
        $qualification = DB::table('loyalty_referral_qualifications')->find($qualificationId) ?? abort(404);
        $referral = DB::table('loyalty_referrals')->find($qualification->referral_id);
        $programme = DB::table('loyalty_referral_programmes')->find($referral->programme_id);
        app(NoncashActivation::class)->requireUsable('programme', (int) $programme->id);
        $user = (int) ($role === 'referrer' ? $referral->referrer_id : $referral->user_id);
        $reward = (int) ($role === 'referrer' ? $programme->referrer_reward_id : $programme->member_reward_id);
        $invoice = DB::table('finance_invoices')->find($qualification->invoice_id);
        abort_unless(app(ReferralSource::class)->eligible($invoice, $programme, $referral), 409, 'The referral invoice no longer qualifies.');
        $request = hash('sha256', 'referral-reward:'.$qualificationId.':'.$role);
        if ($old = DB::table('loyalty_redemptions')->where('request_key', $request)->first()) {
            return $old;
        }
        // ReferralService already holds both current customer rows, sorted
        // invoice rows and sorted reward rows until this transaction commits.
        abort_unless(DB::transactionLevel() > 0, 409);
        $beneficiary = User::whereKey($user)->lockForUpdate()->firstOrFail();
        abort_unless($beneficiary->role === 'customer' && $beneficiary->account_status === 'active' && $beneficiary->email_verified_at !== null, 403);
        $id = $this->reserveNoncashRewardLocked($user, $reward, $request, 'Verified test referral; pending manual noncash fulfillment.', (int) $referral->user_id);

        return DB::table('loyalty_redemptions')->find($id);
    }

    /** A durable no-economic-action fence for a member's uncertain request. */
    public function cancelCouponIntent(int $user, ?string $code, string $key, ?int $listedCoupon = null): array
    {
        abort_unless(($code === null) !== ($listedCoupon === null), 422);

        return DB::transaction(function () use ($user, $code, $key, $listedCoupon) {
            $current = User::whereKey($user)->lockForUpdate()->firstOrFail();
            abort_unless($current->role === 'customer' && $current->account_status === 'active', 403);
            $request = hash('sha256', 'coupon-claim:'.$user.':'.$key);
            // The existing immutable code digest is also the canonical
            // identity of a listed coupon. Never disclose it to the client.
            $listed = $listedCoupon === null ? null : DB::table('loyalty_coupons')
                ->where('id', $listedCoupon)->where('listed', true)->lockForUpdate()->first();
            abort_if($listedCoupon !== null && ! $listed, 404);
            $hash = $listed ? $listed->code_hash : hash('sha256', $code);
            if ($claim = DB::table('loyalty_coupon_claims')->where('user_id', $user)->where('request_key', $request)->first()) {
                $coupon = DB::table('loyalty_coupons')->find($claim->coupon_id);
                abort_unless($coupon && hash_equals($coupon->code_hash, $hash)
                    && ($listedCoupon === null || (int) $coupon->id === $listedCoupon), 409, 'Coupon intent content changed.');

                return ['state' => 'accepted', 'claim' => $claim];
            }
            $prior = DB::table('loyalty_coupon_claim_intents')->where('user_id', $user)->where('request_key', $request)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->code_hash, $hash), 409, 'Coupon intent content changed.');
            } else {
                DB::table('loyalty_coupon_claim_intents')->insert(['user_id' => $user, 'request_key' => $request, 'code_hash' => $hash, 'created_at' => now()]);
                $this->audit($user, 'loyalty.coupon.intent_cancelled', $request);
            }

            return ['state' => 'cancelled', 'claim' => null];
        }, 3);
    }

    public function transition(int $id, User|int $actor, string $action, string $reason): object
    {
        app(NoncashActivation::class)->requireEnabled();

        return DB::transaction(function () use ($id, $actor, $action, $reason) {
            $actor = $this->lockedAdmin($actor);
            $hint = DB::table('loyalty_redemptions')->find($id);
            abort_unless($hint, 404);
            $activation = app(NoncashActivation::class);
            $partnerClaim = DB::table('partner_reward_claims')->where('redemption_id', $id)->first();
            abort_if($partnerClaim && ! $activation->legacyPointsEnabled(), 503, 'Synthetic partner rewards are disabled.');
            if (! $activation->legacyPointsEnabled()) {
                abort_unless((int) $hint->points_cost === 0, 503, 'Positive-point reward fulfillment is not enabled by manual noncash configuration.');
                $coupon = DB::table('loyalty_coupon_claims')->where('redemption_id', $id)->first();
                $referral = DB::table('loyalty_referral_rewards as rr')->join('loyalty_referral_qualifications as q', 'q.id', '=', 'rr.qualification_id')
                    ->join('loyalty_referrals as r', 'r.id', '=', 'q.referral_id')->where('rr.redemption_id', $id)->value('r.programme_id');
                abort_unless($coupon || $referral, 409, 'A canonical noncash source commitment is required.');
                if ($coupon) {
                    $activation->requireUsable('coupon', (int) $coupon->coupon_id, false);
                } else {
                    $activation->requireUsable('programme', (int) $referral, false);
                }
                $activation->requirePointIsolation((int) $hint->user_id);
            }
            User::whereKey($hint->user_id)->lockForUpdate()->firstOrFail();
            if ($partnerClaim && $action === 'fulfill' && $hint->status !== 'fulfilled') {
                app(PartnerRewardGovernance::class)->lockEligibleSandboxReview((int) $partnerClaim->review_id, (int) $partnerClaim->approval_event_id);
            }
            $backing = DB::table('loyalty_referral_rewards as rr')->join('loyalty_referral_qualifications as q', 'q.id', '=', 'rr.qualification_id')
                ->where('rr.redemption_id', $id)->select('q.invoice_id', 'q.referral_id')->first();
            $invoiceIds = DB::table('loyalty_awards')->where('user_id', $hint->user_id)->pluck('invoice_id')->all();
            if ($backing) {
                $invoiceIds[] = $backing->invoice_id;
            }
            DB::table('finance_invoices')->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();
            DB::table('loyalty_rewards')->where('id', $hint->reward_id)->lockForUpdate()->first();
            $row = DB::table('loyalty_redemptions')->where('id', $id)->lockForUpdate()->first();
            if ($partnerClaim) {
                // Historical approval is immutable and remains evidence for a
                // valid release after revocation, not authority for new fulfillment.
                $review = DB::table('partner_reward_reviews')->find($partnerClaim->review_id);
                $approval = DB::table('partner_reward_review_events')->find($partnerClaim->approval_event_id);
                abort_unless($row && $review && $approval
                    && (int) $partnerClaim->user_id === (int) $row->user_id
                    && (int) $review->reward_id === (int) $row->reward_id
                    && $row->points_cost !== null && (string) $row->points_cost === '0'
                    && (int) $approval->review_id === (int) $review->id
                    && $approval->decision === 'approve', 409, 'Partner claim evidence is inconsistent.');
            }
            $target = ['fulfill' => 'fulfilled', 'reject' => 'rejected'][$action] ?? null;
            abort_unless($target, 422);
            if ($row->status === $target) {
                abort_unless(DB::table('loyalty_redemption_events')->where('redemption_id', $id)->where('action', $target)->where('reason', $reason)->exists(), 409, 'Decision retry reason changed.');

                return $row;
            }
            abort_unless($row->status === 'pending', 409, 'Redemption already decided.');
            if ($activation->legacyPointsEnabled()) {
                $this->reconcileLocked($row->user_id, $actor);
            }
            if ($target === 'fulfilled') {
                if ($backing) {
                    $referral = DB::table('loyalty_referrals')->find($backing->referral_id);
                    $programme = DB::table('loyalty_referral_programmes')->find($referral->programme_id);
                    $invoice = DB::table('finance_invoices')->find($backing->invoice_id);
                    abort_unless(app(ReferralSource::class)->eligible($invoice, $programme, $referral), 409, 'The referral invoice is held, refunded or no longer qualifies.');
                }
                $account = $this->account($row->user_id);
                abort_unless(! $account['redemption_hold'] && $account['balance_points'] >= 0, 409, 'Refund debt or unresolved financial evidence prevents fulfillment.');
            }
            DB::table('loyalty_redemptions')->where('id', $id)->update(['status' => $target, 'updated_at' => now()]);
            if ($target === 'rejected') {
                $this->entry($row->user_id, null, $id, 'release', (int) $row->points_cost, $actor);
                if ($activation->legacyPointsEnabled()) {
                    $this->reconcileLocked($row->user_id, $actor);
                }
            }
            $this->redemptionEvent($id, $actor, $target, $reason);

            return DB::table('loyalty_redemptions')->find($id);
        }, 3);
    }

    private function redemptionEvent(int $id, int $actor, string $action, string $reason): void
    {
        DB::table('loyalty_redemption_events')->insert(['redemption_id' => $id, 'actor_id' => $actor, 'action' => $action, 'reason' => $reason, 'created_at' => now()]);
        $this->audit($actor, 'loyalty.redemption.'.$action, (string) $id);
    }

    private function audit(int $actor, string $action, string $subject): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => $subject, 'created_at' => now()]);
    }
}
