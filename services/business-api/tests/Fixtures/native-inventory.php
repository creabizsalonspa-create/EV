<?php

use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite') {
    throw new RuntimeException('Native inventory fixture requires local SQLite.');
}
$run = bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24)).'aA9!';
$owner = User::create(['name' => 'Synthetic native owner', 'email' => 'inventory-owner-'.$run.'@example.test', 'password' => Hash::make($password)]);
$owner->role = 'vendor';
$owner->save();
$staff = User::create(['name' => 'Synthetic native reader', 'email' => 'inventory-reader-'.$run.'@example.test', 'password' => Hash::make($password)]);
$company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Native inventory fixture '.$run, 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-'.$run, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'status' => 'active', 'permissions' => ['company.read', 'inventory.read']]);
$site = VendorSite::create(['company_id' => $company->id, 'name' => 'Native synthetic site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic address', 'latitude' => 25.2, 'longitude' => 55.3]);
$charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'NATIVE-'.$run, 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => '1.6']);
$connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
// Returned only to the isolated test process's temporary mode-0600 input file.
echo json_encode(['owner' => $owner->email, 'reader' => $staff->email, 'password' => $password, 'company' => $company->id, 'site' => $site->id, 'charger' => $charger->id, 'connector' => $connector->id, 'fixture_only' => true]);
