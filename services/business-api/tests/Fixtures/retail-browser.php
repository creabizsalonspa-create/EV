<?php

use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Requires isolated local SQLite and synthetic identity.');
}
$author = null;
foreach (['author', 'reviewer', 'customer'] as $role) {
    $user = User::create(['name' => 'Synthetic retail '.$role, 'email' => 'retail-'.$role.'-'.$argv[1].'@example.test', 'password' => Hash::make($argv[2])]);
    $user->role = $role === 'customer' ? 'customer' : 'admin';
    if ($role === 'customer') {
        $user->email_verified_at = now();
    }
    $user->save();
    if ($role === 'author') {
        $author = $user;
    }
}
config(['loyalty.enabled' => true]);
$loyalty = app(LoyaltyService::class);
$loyalty->publish('loyalty_policies', $author, [
    'currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1,
    'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500,
    'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000,
    'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5,
    'effective_from' => now()->subDay()->toDateTimeString(),
], 'retail-browser-policy-'.$argv[1]);
$reward = $loyalty->publish('loyalty_rewards', $author, [
    'name' => 'Synthetic partner reward', 'name_ar' => 'مكافأة شريك تجريبية',
    'description' => 'Review only', 'description_ar' => 'للمراجعة فقط',
    'currency' => 'AED', 'points_cost' => 1, 'stock' => 2, 'per_user_limit' => 1,
    'starts_at' => now()->utc()->toDateTimeString(), 'ends_at' => now()->utc()->addDay()->toDateTimeString(),
], 'retail-browser-reward-'.$argv[1]);
echo json_encode(['fixture_only' => true, 'reward_id' => $reward->id], JSON_THROW_ON_ERROR);
