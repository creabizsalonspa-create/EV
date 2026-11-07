<?php

use App\Models\Charging\ConnectorMapping;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 2 || ! preg_match('/^(16|20)[a-f0-9]{6}$/D', $argv[1])) {
    throw new RuntimeException('Isolated local rider fixture required.');
}
$customer = User::where('email', 'canonical-customer-'.$argv[1].'@example.test')->sole();
$station = Station::where('name', 'Canonical browser '.$argv[1])->where('source', 'simulator')->sole();
$map = ConnectorMapping::where('station_id', $station->id)->sole();
$now = now()->utc();
$slot = $now->copy()->startOfHour()->addMinutes(intdiv($now->minute, 30) * 30);
$r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'connector_mapping_id' => $map->id, 'charging_evse_id' => $map->evse_id, 'starts_at' => $slot, 'idempotency_key' => 'rider-protocol-'.$station->id]);
echo json_encode(['reservation_id' => $r->id, 'fixture_only' => true]);
