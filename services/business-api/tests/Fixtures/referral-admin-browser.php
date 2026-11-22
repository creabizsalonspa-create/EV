<?php

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Finance\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || config('payments.provider') !== 'mock' || count($argv) !== 4 || ! in_array($argv[1], ['create', 'invoice'], true) || ! preg_match('/^[a-f0-9]{8}$/D', $argv[2])) {
    throw new RuntimeException('Requires local SQLite mock synthetic referral fixture.');
}
$run = $argv[2];
if ($argv[1] === 'invoice') {
    $member = User::where('email', 'referral-member-'.$run.'@example.test')->firstOrFail();
    $referrer = User::where('email', 'referral-referrer-'.$run.'@example.test')->firstOrFail();
    $original = DB::table('finance_invoices')->where('user_id', $referrer->id)->first();
    $session = DB::table('charging_sessions')->where('id', $original->charging_session_id)->first();
    $station = DB::table('reservations')->where('id', $session->reservation_id)->value('station_id');
    $reservation = Reservation::create(['user_id' => $member->id, 'station_id' => $station, 'starts_at' => now(), 'status' => 'cancelled', 'idempotency_key' => (string) Str::uuid()]);
    $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
    $finance = app(FinanceService::class);
    $invoice = $finance->issue($session, $member->id);
    $finance->pay($invoice, $member->id, (int) $invoice->total_minor, 'referral-browser-'.$run);
    echo json_encode(['fixture_only' => true, 'invoice_id' => $invoice->id], JSON_THROW_ON_ERROR);
    exit;
}
config(['loyalty.enabled' => true]);
$f = (require __DIR__.'/vendor-payout.php')();
$member = User::factory()->create();
$users = [];
foreach (['admin' => $f['maker'], 'referrer' => $f['customer'], 'member' => $member] as $role => $user) {
    $user->forceFill(['email' => 'referral-'.$role.'-'.$run.'@example.test', 'password' => Hash::make($argv[3])])->save();
    $users[$role] = $user->email;
}
$service = app(LoyaltyService::class);
$service->publish('loyalty_policies', $f['maker'], ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'browser-referral-policy-'.$run);
$rewards = [];
foreach (['referrer', 'member'] as $role) {
    $rewards[$role] = $service->publish('loyalty_rewards', $f['maker'], ['currency' => 'AED', 'name' => 'Synthetic '.$role.' '.$run, 'name_ar' => 'مكافأة تجريبية '.$role, 'description' => 'Manual noncash benefit', 'description_ar' => 'مكافأة يدوية تجريبية', 'points_cost' => 80, 'stock' => 10, 'per_user_limit' => 10, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()], 'browser-referral-reward-'.$run.'-'.$role)->id;
}
echo json_encode(['fixture_only' => true, 'users' => $users, 'rewards' => $rewards], JSON_THROW_ON_ERROR);
