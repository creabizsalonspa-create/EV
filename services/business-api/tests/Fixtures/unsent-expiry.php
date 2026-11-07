<?php

use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\SessionBridge;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/', $argv[1]) || ! ctype_digit($argv[2])) {
    throw new RuntimeException('Expiry fixture requires an isolated local synthetic station.');
}
$station = Station::findOrFail((int) $argv[2]);
$owner = User::findOrFail($station->vendor_id);
$customer = User::where('email', 'gate-customer-'.$argv[1].'@example.test')->firstOrFail();
if ($owner->email !== 'gate-owner-'.$argv[1].'@example.test' || $station->source !== 'simulator') {
    throw new RuntimeException('Synthetic identity mismatch.');
}
$now = now()->utc();
$start = $now->copy()->startOfHour()->addMinutes(intdiv($now->minute, 30) * 30);
$r = Reservation::create(['station_id' => $station->id, 'user_id' => $customer->id, 'starts_at' => $start->format('Y-m-d H:i:s'), 'idempotency_key' => 'expiry-fixture-'.$argv[1]]);
$queued = app(SessionBridge::class)->start($r, $customer->id);
// Elapsed-window fixture only. No commands, source observations or finance mutation.
$r->update(['starts_at' => $start->copy()->subHour()->format('Y-m-d H:i:s')]);
echo json_encode(['reservation_id' => $r->id, 'session_id' => $queued['session']->id, 'fixture_only' => true]);
