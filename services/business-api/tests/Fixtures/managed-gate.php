<?php

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

// Synthetic local company/catalog identity. No external commands or legal KYC evidence.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/', $argv[1])) {
    throw new RuntimeException('Managed gate fixture requires local SQLite and an isolated identity.');
}
$run = $argv[1];
foreach (['owner', 'customer'] as $name) {
    $u = User::create(['name' => 'Synthetic '.$name, 'email' => "gate-$name-$run@example.test", 'password' => Hash::make($argv[2])]);
    if ($name === 'owner') {
        $u->role = 'vendor';
        $u->save();
        $owner = $u;
    }
}
$s = Station::create(['vendor_id' => $owner->id, 'name' => 'Create fleet', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
$c = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic gate company '.$run, 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-'.$run, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
echo json_encode(['station_id' => $s->id, 'company_id' => $c->id, 'name' => $c->legal_name]);
