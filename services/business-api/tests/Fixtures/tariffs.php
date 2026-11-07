<?php

use App\Models\Station;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

// Local synthetic pricing only. Append-only tariff records remain as QA evidence.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/', $argv[1])) {
    throw new RuntimeException('Pricing fixtures require local SQLite and isolated identity.');
}
$run = $argv[1];
foreach (['vendor', 'other', 'admin', 'customer'] as $name) {
    $user = User::create(['name' => 'Synthetic '.$name, 'email' => "pricing-$name-$run@example.test", 'password' => Hash::make($argv[2])]);
    $user->role = $name === 'other' ? 'vendor' : $name;
    $user->save();
    if ($name !== 'vendor' && $name !== 'other') {
        continue;
    }
    for ($i = 0; $i < ($name === 'vendor' ? 26 : 1); $i++) {
        $s = Station::create(['vendor_id' => $user->id, 'name' => "Pricing $name $run $i <img src=x>", 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }
    if ($name === 'vendor') {
        $station = $s;
    }
}
echo json_encode(['id' => $station->id, 'name' => $station->name]);
