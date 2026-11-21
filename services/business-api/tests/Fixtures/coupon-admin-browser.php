<?php

use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || config('payments.provider') !== 'mock' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Requires isolated local SQLite mock fixture.');
}
config(['loyalty.enabled' => true]);
$f = (require __DIR__.'/vendor-payout.php')();
$users = [];
foreach (['admin' => $f['maker'], 'customer' => $f['customer']] as $role => $user) {
    $user->forceFill(['email' => 'coupon-'.$role.'-'.$argv[1].'@example.test', 'password' => Hash::make($argv[2])])->save();
    $users[$role] = $user->email;
}
$s = app(LoyaltyService::class);
$s->publish('loyalty_policies', $f['maker'], ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'browser-policy-'.$argv[1]);
$reward = $s->publish('loyalty_rewards', $f['maker'], ['currency' => 'AED', 'name' => '<img src=x onerror=alert(1)> Synthetic coupon reward', 'name_ar' => 'مكافأة قسيمة تجريبية', 'description' => 'Synthetic manual benefit', 'description_ar' => 'مكافأة يدوية تجريبية', 'points_cost' => 80, 'stock' => 2, 'per_user_limit' => 1, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()], 'browser-reward-'.$argv[1]);
echo json_encode(['fixture_only' => true, 'users' => $users, 'reward_id' => $reward->id], JSON_THROW_ON_ERROR);
