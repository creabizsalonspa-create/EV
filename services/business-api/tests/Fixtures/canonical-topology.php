<?php

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/', $argv[1])) {
    throw new RuntimeException('Isolated local fixture required.');
}
$run = $argv[1];
$password = Hash::make($argv[2]);
$owner = User::create(['name' => 'Synthetic canonical owner', 'email' => 'canonical-owner-'.$run.'@example.test', 'password' => $password]);
$owner->role = 'vendor';
$owner->save();
$customer = User::create(['name' => 'Synthetic canonical customer', 'email' => 'canonical-customer-'.$run.'@example.test', 'password' => $password]);
$station = Station::create(['vendor_id' => $owner->id, 'name' => 'Canonical browser '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
$company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Canonical browser '.$run, 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-'.$run, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
$site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Synthetic', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
$charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CANONICAL-'.$run, 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator']);
$connectors = [];
for ($i = 1; $i <= 3; $i++) {
    $connectors[] = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => $i, 'connector_type' => 'CCS2', 'max_power_kw' => 50])->id;
}
echo json_encode(['station_id' => $station->id, 'company_id' => $company->id, 'charger_id' => $charger->id, 'connectors' => $connectors, 'name' => $company->legal_name]);
