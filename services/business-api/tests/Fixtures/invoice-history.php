<?php

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

// Permanent synthetic local accounting evidence. Immutable finance is never deleted.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/', $argv[1])) {
    throw new RuntimeException('Synthetic invoice fixture requires local SQLite and an isolated identity.');
}
$run = $argv[1];
$users = [];
foreach (['customer', 'vendor', 'other', 'admin'] as $name) {
    $u = User::create(['name' => 'Synthetic '.$name, 'email' => "invoices-$name-$run@example.test", 'password' => Hash::make($argv[2])]);
    $u->role = in_array($name, ['vendor', 'admin']) ? $name : 'customer';
    $u->save();
    $users[$name] = $u;
}
$station = Station::create(['vendor_id' => $users['vendor']->id, 'name' => 'Synthetic invoice history '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
$tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $users['vendor']->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
$finance = app(FinanceService::class);
for ($i = 0; $i < 26; $i++) {
    $booking = Reservation::create(['station_id' => $station->id, 'user_id' => $users['customer']->id, 'starts_at' => now()->startOfHour()->subDays(2)->addMinutes($i * 30), 'status' => 'confirmed', 'idempotency_key' => "invoice-$run-$i"]);
    $session = ChargingSession::create(['reservation_id' => $booking->id, 'status' => 'stopped', 'energy_wh' => 12345, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1235, 'stopped_at' => now()]);
    $finance->snapshotSession($session, $tariff);
    $invoice = $finance->issue($session, $users['customer']->id);
}
$finance->pay($invoice, $users['customer']->id, 1297, "history-pay-$run");
$finance->refund($invoice, $users['vendor']->id, 100, "history-refund-$run", 'Literal <img src=x onerror=alert(1)>');
echo json_encode(['id' => $invoice->id, 'number' => $invoice->number]);
