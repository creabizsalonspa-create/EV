<?php

use App\Http\Controllers\LoyaltyController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

if (($argv[2] ?? '') === 'snapshot') {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || DB::connection()->getDriverName() !== 'sqlite' || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1] ?? '')) {
        throw new RuntimeException('Owned local SQLite fixture required.');
    }
    $hash = hash_init('sha256');
    foreach (['finance_invoices', 'finance_journals', 'finance_entries', 'finance_payments', 'finance_refunds'] as $table) {
        hash_update($hash, $table."\0".DB::table($table)->orderBy('id')->get()->toJson());
    }
    echo json_encode(['fixture_only' => true, 'canonical_finance_hash' => hash_final($hash)], JSON_THROW_ON_ERROR);

    return;
}
// Existing guarded local/mock fixture publishes its policy/reward canonically.
ob_start();
require __DIR__.'/coupon-admin-browser.php';
$seed = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$code = 'EV-NATIVE-'.strtoupper($argv[1]);
$request = Request::create('/api/commercial/coupons', 'POST', [
    'code' => $code, 'reward_id' => $seed['reward_id'], 'claim_limit' => 2, 'per_user_limit' => 1,
    'starts_at' => now()->subMinute()->toISOString(), 'ends_at' => now()->addHour()->toISOString(),
    'idempotency_key' => 'native-coupon-'.$argv[1],
]);
$request->setUserResolver(fn () => $f['maker']);
app(LoyaltyController::class)->publishCoupon($request);
echo json_encode(['fixture_only' => true, 'customer' => $seed['users']['customer'], 'code' => $code], JSON_THROW_ON_ERROR);
