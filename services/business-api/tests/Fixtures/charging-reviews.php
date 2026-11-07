<?php

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Explicit APP simulator fixture, not invented device/source receipt evidence.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(PHP_SAPI === 'cli' && $app->environment('local') && config('database.default') === 'sqlite' && count($argv) === 3 && preg_match('/^[a-f0-9]{8}$/D', $argv[1]), 403);
$run = $argv[1];
$accounts = [];
foreach (['customer', 'vendor', 'admin', 'other'] as $role) {
    $u = User::create(['name' => 'Synthetic feedback '.$role, 'email' => "feedback-$role-$run@example.test", 'password' => Hash::make($argv[2])]);
    $u->forceFill(['role' => $role === 'other' ? 'customer' : $role])->save();
    $users[$role] = $u;
    $accounts[$role] = ['email' => $u->email, 'password' => $argv[2]];
}
$station = Station::create(['vendor_id' => $users['vendor']->id, 'name' => 'Synthetic feedback '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
$r = Reservation::create(['user_id' => $users['customer']->id, 'station_id' => $station->id, 'starts_at' => now()->subHour(), 'status' => 'completed', 'idempotency_key' => (string) Str::uuid()]);
$s = ChargingSession::create(['reservation_id' => $r->id, 'status' => 'stopped', 'energy_wh' => 100, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 10, 'stopped_at' => now()]);
echo json_encode(['fixture_only' => true, 'accounts' => $accounts, 'station_id' => $station->id, 'session_id' => $s->id], JSON_THROW_ON_ERROR);
