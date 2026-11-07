<?php

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 3) {
    throw new RuntimeException('Local protocol fixture only.');
}
$station = Station::findOrFail((int) $argv[1]);
$customer = User::findOrFail((int) $argv[2]);
$binding = DB::table('csms_bindings')->where('station_id', $station->id)->sole();
if ($station->source !== 'simulator' || ! str_starts_with($station->name, 'Business protocol ev-test-') || $customer->role !== 'customer' || ! preg_match('/^ev-test-business-/', $binding->identifier)) {
    throw new RuntimeException('Synthetic source required.');
}
$company = VendorCompany::create(['owner_id' => $station->vendor_id, 'legal_name' => 'Synthetic canonical protocol', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-'.$station->id, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
$site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Synthetic', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
$charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'PROTOCOL-'.$station->id, 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $binding->protocol]);
DB::table('csms_bindings')->where('id', $binding->id)->update(['vendor_charger_id' => $charger->id]);
$evse = Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding->id, 'source_evse_number' => $binding->protocol === '2.0.1' ? 5 : 0]);
$connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
$map = ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id, 'source_connector_number' => 7, 'source_target_key' => hash('sha256', $binding->id.':'.$evse->source_evse_number.':7')]);
$now = now()->utc();
$slot = $now->copy()->startOfHour()->addMinutes(intdiv($now->minute, 30) * 30);
$r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'connector_mapping_id' => $map->id, 'charging_evse_id' => $evse->id, 'starts_at' => $slot, 'idempotency_key' => 'canonical-protocol-'.$station->id]);
echo json_encode(['reservation_id' => $r->id, 'connector_mapping_id' => $map->id, 'fixture_only' => true]);
