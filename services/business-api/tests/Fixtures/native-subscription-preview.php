<?php

use App\Models\User;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite') {
    throw new RuntimeException('Synthetic subscription preview requires owned local SQLite.');
}
$run = bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24)).'aA9!';
$users = [];
foreach (['owner', 'foreign', 'admin'] as $name) {
    $u = User::create(['name' => 'Synthetic preview '.$name, 'email' => 'native-preview-'.$name.'-'.$run.'@example.test', 'password' => Hash::make($password)]);
    $u->forceFill(['role' => $name === 'admin' ? 'admin' : 'vendor', 'email_verified_at' => now()])->save();
    $users[$name] = $u;
}
$service = app(SubscriptionService::class);
$plans = [];
foreach (['basic' => [999, 'month'], 'monthly' => [2999, 'month'], 'annual' => [5999, 'year']] as $name => [$price, $interval]) {
    $plans[$name] = $service->publish($users['admin'], ['code' => 'native_preview_'.$name.'_'.$run, 'name' => 'Synthetic preview '.$name, 'audience' => 'vendor', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => $price, 'tax_bps' => 500, 'interval' => $interval, 'entitlements' => json_encode(['staff_limit' => 2], JSON_THROW_ON_ERROR)]);
}
$enrolled = $service->subscribe($users['owner'], $plans['basic']->id, 'native-preview-enroll-'.$run);
$id = $enrolled['subscription']->id;
$service->mockPay($users['owner'], $id, $enrolled['invoices']->first()->id, 'succeeded', 'native-preview-pay-'.$run);
// Only isolated fixture preparation invokes canonical mock services. Native proof is GET-only.
echo json_encode(['owner' => $users['owner']->email, 'foreign' => $users['foreign']->email, 'admin' => $users['admin']->email,
    'password' => $password, 'admin_token' => $users['admin']->createToken('synthetic-preview-negative')->plainTextToken, 'subscription' => $id, 'monthly' => $plans['monthly']->id, 'annual' => $plans['annual']->id,
    'fixture_only' => true, 'real_payment_processed' => false], JSON_THROW_ON_ERROR);
