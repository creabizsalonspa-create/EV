<?php

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Fleet;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || config('payments.provider') !== 'mock' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Corporate browser fixtures require local SQLite, mock provider and isolated synthetic identity.');
}
$run = $argv[1];
$users = [];
foreach (['owner', 'driver', 'admin', 'vendor'] as $role) {
    $user = User::create(['name' => 'Synthetic corporate '.$role, 'email' => 'corporate-'.$role.'-'.$run.'@example.test', 'password' => Hash::make($argv[2])]);
    $user->role = in_array($role, ['vendor', 'admin']) ? $role : 'customer';
    $user->save();
    $users[$role] = $user;
}
$fleet = Fleet::create(['owner_id' => $users['owner']->id, 'name' => 'Synthetic company '.$run, 'country' => 'AE']);
DB::table('fleet_members')->insert(['fleet_id' => $fleet->id, 'user_id' => $users['driver']->id, 'status' => 'active', 'role' => 'driver', 'created_at' => now(), 'updated_at' => now()]);
$center = DB::table('cost_centers')->insertGetId(['fleet_id' => $fleet->id, 'code' => 'ENGINEERING', 'name' => 'Synthetic engineering', 'created_at' => now(), 'updated_at' => now()]);
$station = Station::create(['vendor_id' => $users['vendor']->id, 'name' => 'Corporate browser simulator '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
$tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $users['admin']->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
$reservation = Reservation::create(['user_id' => $users['driver']->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => 'corporate-browser-'.$run]);
$session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
$finance = app(FinanceService::class);
$finance->snapshotSession($session, $tariff);
$invoice = $finance->issue($session, $users['driver']->id);
echo json_encode(['fleet_id' => $fleet->id, 'cost_center_id' => $center, 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'owner_id' => $users['owner']->id, 'driver_id' => $users['driver']->id, 'fixture_only' => true]);
