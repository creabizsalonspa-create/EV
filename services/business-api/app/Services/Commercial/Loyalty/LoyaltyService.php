<?php

namespace App\Services\Commercial\Loyalty;

use App\Models\User;
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

    public function publish(string $table, int $actor, array $values, string $key): object
    {
        $this->enabled();
        abort_unless(in_array($table, ['loyalty_policies', 'loyalty_rewards'], true), 422);

        return DB::transaction(function () use ($table, $actor, $values, $key) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
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

        return ['tier_by_currency' => (object) $tiers, 'user_id' => $user, 'balance_points' => $balance, 'pending_reconciliation_points' => $pending, 'spendable_points' => $held ? 0 : max(0, $balance - $pending), 'qualified_lifetime_points' => $qualified, 'redemption_hold' => $held, 'unit' => 'NON_MONETARY_POINTS', 'cash_value' => false, 'transferable' => false, 'evidence' => 'TEST_PROMOTIONAL_ECONOMICS'];
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

    public function transition(int $id, int $actor, string $action, string $reason): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor, $action, $reason) {
            $hint = DB::table('loyalty_redemptions')->find($id);
            abort_unless($hint, 404);
            User::whereKey($hint->user_id)->lockForUpdate()->firstOrFail();
            DB::table('loyalty_rewards')->where('id', $hint->reward_id)->lockForUpdate()->first();
            $row = DB::table('loyalty_redemptions')->where('id', $id)->lockForUpdate()->first();
            $target = ['fulfill' => 'fulfilled', 'reject' => 'rejected'][$action] ?? null;
            abort_unless($target, 422);
            if ($row->status === $target) {
                abort_unless(DB::table('loyalty_redemption_events')->where('redemption_id', $id)->where('action', $target)->where('reason', $reason)->exists(), 409, 'Decision retry reason changed.');

                return $row;
            }
            abort_unless($row->status === 'pending', 409, 'Redemption already decided.');
            $this->reconcileLocked($row->user_id, $actor);
            if ($target === 'fulfilled') {
                $account = $this->account($row->user_id);
                abort_unless(! $account['redemption_hold'] && $account['balance_points'] >= 0, 409, 'Refund debt or unresolved financial evidence prevents fulfillment.');
            }
            DB::table('loyalty_redemptions')->where('id', $id)->update(['status' => $target, 'updated_at' => now()]);
            if ($target === 'rejected') {
                $this->entry($row->user_id, null, $id, 'release', (int) $row->points_cost, $actor);
                $this->reconcileLocked($row->user_id, $actor);
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
