<?php

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Owned local lab assets only; never real KYC, hardware or payment acceptance.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$run = $argv[1] ?? '';
$qa = ($argv[2] ?? '') === '--qa-current-slot';
if (! $app->environment('local') || config('database.default') !== 'sqlite' || config('payments.provider') !== 'mock' || ! config('csms.session_bridge_enabled') || ! config('csms.remote_authorization_enabled') || ! preg_match('/^[a-f0-9]{8}$/D', $run) || count($argv) > 3 || (isset($argv[2]) && ! $qa)) {
    throw new RuntimeException('Existing isolated local mock/source configuration and a unique lab identity are required.');
}
$result = DB::transaction(function () use ($run, $qa) {
    $accounts = [];
    $users = [];
    foreach (['customer', 'vendor', 'admin'] as $role) {
        $password = bin2hex(random_bytes(24)).'aA9!';
        $email = "client-demo-$role-$run@example.test";
        $user = User::create(['name' => "Synthetic demo $role $run", 'email' => $email, 'password' => Hash::make($password)]);
        $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();
        $accounts[$role] = compact('email', 'password');
        $users[$role] = $user;
    }
    Vehicle::create(['user_id' => $users['customer']->id, 'name' => 'Synthetic demo EV', 'connector_type' => 'CCS2']);
    $company = VendorCompany::create(['owner_id' => $users['vendor']->id, 'legal_name' => "Synthetic charging lab $run", 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-DEMO-'.$run, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
    $stations = [];
    foreach (['1.6' => '16', '2.0.1' => '201'] as $protocol => $suffix) {
        $identity = "ev-test-demo-$run-$suffix";
        $station = Station::create(['vendor_id' => $users['vendor']->id, 'name' => "LOCAL SIMULATOR $protocol · $run", 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => "Synthetic $protocol", 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic demonstration; not a physical charging location', 'latitude' => 25.2, 'longitude' => 55.3]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CLIENT-DEMO-'.$run.'-'.$suffix, 'manufacturer' => 'CreaBiz Simulator', 'model' => 'Session QA', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => $identity, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $evse = Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding, 'source_evse_number' => $protocol === '1.6' ? 0 : 1]);
        $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        $mapping = ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id, 'source_connector_number' => 1, 'source_target_key' => hash('sha256', $binding.':'.$evse->source_evse_number.':1')]);
        $reservation = null;
        if ($qa) {
            $now = now()->utc();
            $slot = $now->copy()->startOfHour()->addMinutes(intdiv($now->minute, 30) * 30);
            $reservation = Reservation::create(['user_id' => $users['customer']->id, 'station_id' => $station->id, 'connector_mapping_id' => $mapping->id, 'charging_evse_id' => $evse->id, 'starts_at' => $slot, 'idempotency_key' => 'explicit-qa-demo-'.$run.'-'.$suffix])->id;
        }
        $stations[] = ['protocol' => $protocol, 'identity' => $identity, 'station_id' => $station->id, 'charger_id' => $charger->id, 'binding_id' => $binding, 'connector_mapping_id' => $mapping->id, 'reservation_id' => $reservation];
    }
    DB::table('audit_events')->insert(['actor_id' => $users['admin']->id, 'action' => 'client_demo.synthetic_assets_created', 'subject' => $run, 'created_at' => now()]);

    return ['fixture_only' => true, 'run' => $run, 'base' => 'http://127.0.0.1:8000', 'company_id' => $company->id, 'accounts' => $accounts, 'stations' => $stations, 'qa_current_bookings' => $qa, 'physical_charging_verified' => false, 'real_money' => false];
});
// Captured directly into an owner-only file by scripts/client-demo; never publish stdout.
echo json_encode($result, JSON_THROW_ON_ERROR);
