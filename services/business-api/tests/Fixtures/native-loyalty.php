<?php

use App\Services\Commercial\Loyalty\LoyaltyService;

// Reuse the guarded local/mock browser fixture and canonical finance source.
if (($argv[1] ?? '') !== 'create') {
    throw new RuntimeException('Native loyalty evidence only supports synthetic creation.');
}
ob_start();
require __DIR__.'/loyalty-browser.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
config(['loyalty.enabled' => true]);
$loyalty = app(LoyaltyService::class);
$loyalty->publish('loyalty_policies', $users['admin']->id, ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 100, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 10000, 'platinum_multiplier_bps' => 10000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 365, 'daily_redemption_limit' => 10, 'effective_from' => now()->subMinute()->utc()->toDateTimeString()], 'native-policy-'.$run);
$reward = $loyalty->publish('loyalty_rewards', $users['admin']->id, ['currency' => 'AED', 'name' => 'Synthetic native reward '.$run, 'name_ar' => 'مكافأة تطبيق تجريبية '.$run, 'description' => 'Synthetic manual benefit only', 'description_ar' => 'منفعة يدوية تجريبية فقط', 'points_cost' => 30, 'stock' => 2, 'per_user_limit' => 2, 'starts_at' => now()->subHour()->utc()->toDateTimeString(), 'ends_at' => now()->addDay()->utc()->toDateTimeString()], 'native-reward-'.$run);
$loyalty->earn($invoice->id, $users['customer']->id);
$redemption = $loyalty->redeem($users['customer']->id, $reward->id, $users['customer']->id, 'native-redemption-'.$run);
echo json_encode($fixture + ['reward_id' => $reward->id, 'redemption_id' => $redemption->id]);
