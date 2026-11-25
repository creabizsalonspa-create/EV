<?php

namespace App\Services\Commercial;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A mutable active flag alone does not authorize paid-cycle entitlements. */
final class SubscriptionPaidCycle
{
    public static function current(object $s): bool
    {
        if ($s->status !== 'active' || ! $s->period_start || ! $s->period_end) {
            return false;
        }
        $i = DB::table('commercial_subscription_invoices')->where('subscription_id', $s->id)
            ->where('cycle_number', $s->cycle_number)->first();
        if (! $i) {
            return false;
        }
        $r = DB::table('commercial_subscription_receipts')->where('invoice_id', $i->id)->first();
        if (! $r) {
            return false;
        }
        abort_unless((int) $i->total_minor >= 0
            && (int) $r->amount_minor === (int) $i->total_minor && $r->currency === $i->currency
            && in_array($r->evidence, ['MOCK_PROVIDER', 'STRIPE_TEST_CONTRACT'], true),
            409, 'Subscription receipt requires reconciliation.');
        $refunded = 0;
        foreach (DB::table('subscription_credits')->where('invoice_id', $i->id)->get() as $credit) {
            $amount = (int) $credit->amount_minor;
            abort_unless((int) $credit->receipt_id === (int) $r->id
                && (int) $credit->subscription_id === (int) $s->id
                && $credit->currency === $i->currency && (int) $credit->minor_units === (int) $i->minor_units
                && $credit->evidence === $r->evidence && $amount > 0
                && $amount <= (int) $r->amount_minor - $refunded,
                409, 'Subscription credit requires reconciliation.');
            $refunded += $amount;
        }
        $clock = CarbonImmutable::now();

        return ((int) $i->total_minor === 0 || $refunded < (int) $r->amount_minor)
            && (int) $i->plan_id === (int) $s->plan_id
            && CarbonImmutable::parse($i->period_start)->equalTo(CarbonImmutable::parse($s->period_start))
            && CarbonImmutable::parse($i->period_end)->equalTo(CarbonImmutable::parse($s->period_end))
            && CarbonImmutable::parse($i->period_start)->lte($clock)
            && CarbonImmutable::parse($i->period_end)->gt($clock);
    }
}
