<?php

use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (! $app->environment('local', 'testing') || DB::connection()->getDriverName() !== 'sqlite' || config('payments.provider') !== 'mock' || ! preg_match('/^[a-f0-9]{8}$/D', $run) || ! in_array($mode, ['seed', 'snapshot'], true)) {
    throw new RuntimeException('Owned local SQLite/mock retained-receipt fixture required.');
}
if (! Schema::hasTable('charger_source_registrations')) {
    throw new RuntimeException('Apply current additive source-registration migrations before this fixture.');
}
$generation = hash('sha256', 'synthetic-retained-receipt-no-source-authentication:'.$run);
if ($mode === 'snapshot') {
    $receipts = DB::table('charger_source_registrations')->where('source_generation', $generation)->orderBy('id')->get();
    if ($receipts->count() !== 2) {
        throw new RuntimeException('Owned retained fixtures required.');
    }
    $finance = hash_init('sha256');
    foreach (['finance_invoices', 'finance_journals', 'finance_entries', 'finance_payments', 'finance_refunds'] as $table) {
        hash_update($finance, $table."\0".DB::table($table)->orderBy('id')->get()->toJson());
    }
    echo json_encode(['fixture_only' => true, 'retained_receipts_hash' => hash('sha256', $receipts->toJson()), 'commands' => DB::table('charger_commands')->count(), 'sessions' => DB::table('charging_sessions')->count(), 'canonical_finance_hash' => hash_final($finance)], JSON_THROW_ON_ERROR);

    return;
}
$password = $argv[3] ?? '';
if (strlen($password) < 24) {
    throw new RuntimeException('Private synthetic password required.');
}
[$owner, $company, $chargers] = DB::transaction(function () use ($password, $run, $generation) {
    $owner = User::factory()->create(['name' => 'Synthetic retained source owner', 'email' => 'native-retained-'.$run.'@example.test', 'password' => $password, 'role' => 'vendor']);
    $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic retained source fixture', 'country' => 'AE', 'trade_license_number' => 'RETAINED-'.$run, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
    $chargers = [];
    foreach (['1.6', '2.0.1'] as $index => $protocol) {
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Synthetic retained receipt '.$run.' '.$protocol, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'direct_unverified', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Synthetic retained site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic fixture only', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'RETAINED-'.$run.'-'.$index, 'manufacturer' => 'Synthetic', 'model' => 'RetainedFixture', 'source' => 'direct_unverified', 'ocpp_protocol' => $protocol]);
        $identity = 'CB-RETAINED-'.$run.'-'.$index;
        $source = $index + 1;
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => $identity, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $profile = AdmissionProfile::create(['charger_id' => $charger->id, 'identity' => $identity, 'protocol' => $protocol]);
        // Deliberately synthetic historical data, not authenticated source creation.
        // Its fixture-only generation never matches a configured source receipt.
        DB::table('charger_source_registrations')->insert(['charger_id' => $charger->id, 'binding_id' => $binding, 'profile_id' => $profile->id, 'actor_id' => $owner->id, 'idempotency_key' => 'retained-'.$run.'-'.$index, 'payload_hash' => hash('sha256', 'fixture-only-'.$index), 'source_generation' => $generation, 'state' => 'registered', 'source_station_id' => $source, 'source_receipt_hash' => hash('sha256', 'synthetic-not-authenticated-'.$index), 'verified_at' => now()->format('Y-m-d H:i:s.u'), 'created_at' => now(), 'updated_at' => now()]);
        $chargers[] = ['id' => $charger->id, 'protocol' => $protocol];
    }

    return [$owner, $company, $chargers];
});
echo json_encode(['fixture_only' => true, 'synthetic_retained_receipt' => true, 'source_authentication_performed' => false, 'source_writes_performed' => 0, 'expected_inventory_matches' => false, 'owner' => $owner->email, 'company_id' => $company->id, 'chargers' => $chargers], JSON_THROW_ON_ERROR);
