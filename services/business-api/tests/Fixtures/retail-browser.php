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
    $user->save();
    if ($role === 'author') {
        $author = $user;
    }
}
config(['loyalty.enabled' => true]);
$reward = app(LoyaltyService::class)->publish('loyalty_rewards', $author, [
    'name' => 'Synthetic partner reward', 'name_ar' => 'مكافأة شريك تجريبية',
    'description' => 'Review only', 'description_ar' => 'للمراجعة فقط',
    'currency' => 'AED', 'points_cost' => 1, 'stock' => 2, 'per_user_limit' => 1,
    'starts_at' => now()->utc()->toDateTimeString(), 'ends_at' => now()->utc()->addDay()->toDateTimeString(),
], 'retail-browser-reward-'.$argv[1]);
echo json_encode(['fixture_only' => true, 'reward_id' => $reward->id], JSON_THROW_ON_ERROR);
