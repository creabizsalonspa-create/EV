<?php

namespace App\Services\Commercial\Referrals;

use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** One source eligibility rule, shared by grant, private projection and fulfillment. */
final class ReferralSource
{
    public function eligible(object $invoice, object $programme, object $referral): bool
    {
        $source = app(LoyaltyService::class)->promotionSource($invoice);

        $activeMembers = DB::table('users')->whereIn('id', [$referral->user_id, $referral->referrer_id])
            ->where('role', 'customer')->where('account_status', 'active')->whereNotNull('email_verified_at')->count();

        return $activeMembers === 2 && (int) $invoice->user_id === (int) $referral->user_id
            && $invoice->currency === $programme->currency
            && (int) $invoice->minor_units === (int) $programme->minor_units
            && (int) $invoice->total_minor >= (int) $programme->minimum_minor
            && Carbon::parse($invoice->created_at)->gte(Carbon::parse($referral->created_at))
            && $source['qualified'] && ! $source['hold']
            && ! DB::table('finance_refunds')->where('invoice_id', $invoice->id)->exists()
            && (int) $source['remaining_minor'] === (int) $invoice->subtotal_minor;
    }
}
