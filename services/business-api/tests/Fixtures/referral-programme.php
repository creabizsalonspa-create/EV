<?php

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Commercial\Referrals\ReferralRegistry;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return static function (int $limit = 10): array {
    abort_unless(app()->environment('testing'), 403);
    config(['loyalty.enabled' => true]);
    $f = (require __DIR__.'/vendor-payout.php')();
    $member = User::factory()->create();
    $service = app(LoyaltyService::class);
    $service->publish('loyalty_policies', $f['maker'], ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'race-referral-policy');
    $rewardIds = [];
    foreach (['referrer', 'member'] as $role) {
        $reward = $service->publish('loyalty_rewards', $f['maker'], ['currency' => 'AED', 'name' => 'Synthetic '.$role.' benefit', 'name_ar' => 'مكافأة تجريبية', 'description' => 'Manual noncash benefit', 'description_ar' => 'مكافأة يدوية تجريبية', 'points_cost' => 80, 'stock' => 10, 'per_user_limit' => 10, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()], 'race-reward-'.$role);
        $rewardIds[$role] = $reward->id;
    }
    $registry = app(ReferralRegistry::class);
    $programme = $registry->publish($f['maker'], ['name' => 'Synthetic race programme', 'name_ar' => 'برنامج تجريبي',
        'terms' => 'Explicit test consent before the first payment; a fully paid post-consent invoice reserves manual noncash benefits. No cash or delivery proof.',
        'terms_ar' => 'موافقة اختبار صريحة قبل أول دفعة؛ تمنح الفاتورة المؤهلة مكافأتين يدويتين غير نقديتين ولا تثبت تسليم المكافآت.',
        'currency' => 'AED', 'minor_units' => 2, 'minimum_minor' => 100,
        'referrer_reward_id' => $rewardIds['referrer'], 'member_reward_id' => $rewardIds['member'], 'claim_limit' => $limit, 'per_referrer_limit' => $limit,
        'starts_at' => now()->subMinute()->toDateTimeString(), 'ends_at' => now()->addHour()->toDateTimeString(), 'idempotency_key' => 'race-programme']);
    $code = $registry->code($f['customer'], $programme['id'], 'race-code')['code'];
    $accept = static function (User $user) use ($registry, $code, $programme) {
        return $registry->accept($user, ['code' => $code, 'terms_version' => $programme['terms_version'], 'consent' => true, 'idempotency_key' => 'accept-'.$user->id]);
    };
    $session = DB::table('charging_sessions')->where('id', $f['invoice']->charging_session_id)->first();
    $station = DB::table('reservations')->where('id', $session->reservation_id)->value('station_id');
    $invoiceFor = static function (User $user) use ($station) {
        $reservation = Reservation::create(['user_id' => $user->id, 'station_id' => $station, 'starts_at' => now(), 'status' => 'cancelled', 'idempotency_key' => (string) Str::uuid()]);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $invoice = $finance->issue($session, $user->id);
        $finance->pay($invoice, $user->id, (int) $invoice->total_minor, (string) Str::uuid());

        return $invoice;
    };
    $referral = $accept($member);
    $invoice = $invoiceFor($member);

    return $f + compact('member', 'programme', 'code', 'referral', 'invoiceFor', 'accept') + ['member_invoice' => $invoice];
};
