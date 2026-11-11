<?php

use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless($app->environment('local') && config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), '/charger-certificates/test-') && str_ends_with(config('database.connections.sqlite.database'), '_review.sqlite'), 403);
$run = $argv[1] ?? '';
abort_unless(preg_match('/^[a-f0-9]{12}$/D', $run) && strlen(env('EV_CERT_FIXTURE_PASSWORD', '')) >= 32, 403);
if (($argv[2] ?? '') === 'counts') {
    echo json_encode(['commands' => DB::table('charger_commands')->count(), 'invoices' => DB::table('finance_invoices')->count()], JSON_THROW_ON_ERROR);

    return;
}
$owner = User::create(['name' => 'Synthetic certificate owner', 'email' => "cert-owner-{$run}@example.test", 'password' => Hash::make(env('EV_CERT_FIXTURE_PASSWORD'))]);
$owner->forceFill(['role' => 'vendor'])->save();
$other = User::create(['name' => 'Synthetic other customer', 'email' => "cert-other-{$run}@example.test", 'password' => Hash::make(env('EV_CERT_FIXTURE_PASSWORD'))]);
$company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic certificate company '.$run, 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
$site = VendorSite::create(['company_id' => $company->id, 'name' => 'Certificate test site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic local fixture', 'latitude' => 25, 'longitude' => 55]);
$chargers = [];
foreach (['1.6', '2.0.1'] as $protocol) {
    $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CERT-'.$protocol, 'manufacturer' => 'Synthetic', 'model' => 'TLS fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
    $chargers[] = ['id' => $charger->id, 'protocol' => $protocol, 'identity' => 'ev-test-cert-'.$run.'-'.str_replace('.', '', $protocol)];
}
echo json_encode(['owner' => $owner->email, 'other' => $other->email, 'company_id' => $company->id, 'chargers' => $chargers], JSON_THROW_ON_ERROR);
