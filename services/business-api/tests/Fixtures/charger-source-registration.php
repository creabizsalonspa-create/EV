<?php

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Security\AdminTotp;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
$host = parse_url((string) config('csms.graphql_url'), PHP_URL_HOST);
if (! $app->environment('local') || DB::connection()->getDriverName() !== 'sqlite' || ! config('csms.upstream_registration_enabled') || ! config('csms.upstream_registration_allow_local_http') || ! in_array($host, ['charging-graphql', '127.0.0.1', 'localhost'], true) || ! in_array($mode, ['seed', 'snapshot', 'mfa'], true) || ! preg_match('/^[a-f0-9]{8}$/D', $run)) {
    throw new RuntimeException('Owned local SQLite and loopback/container CSMS metadata fixture required.');
}
$adminEmail = 'source-admin-'.$run.'@example.test';
if ($mode === 'mfa') {
    $admin = User::where('email', $adminEmail)->firstOrFail();
    $factor = DB::table('admin_second_factors')->where('user_id', $admin->id)->firstOrFail();
    echo json_encode(['code' => AdminTotp::codeAtStep(Crypt::decryptString($factor->encrypted_secret), intdiv(now()->timestamp, 30))], JSON_THROW_ON_ERROR);

    return;
}
if ($mode === 'snapshot') {
    $hash = hash_init('sha256');
    foreach (['charger_commands', 'csms_inbox_events', 'charging_sessions', 'finance_invoices', 'finance_journals', 'finance_entries', 'finance_payments', 'finance_refunds'] as $table) {
        hash_update($hash, $table."\0".DB::table($table)->orderBy('id')->get()->toJson());
    }
    echo json_encode(['fixture_only' => true, 'charging_and_finance_hash' => hash_final($hash)], JSON_THROW_ON_ERROR);

    return;
}
$password = $argv[3] ?? '';
if (strlen($password) < 24) {
    throw new RuntimeException('Private synthetic password required.');
}
$owner = User::create(['name' => 'Synthetic source owner', 'email' => 'source-owner-'.$run.'@example.test', 'password' => Hash::make($password)]);
$owner->forceFill(['role' => 'vendor', 'email_verified_at' => now()])->save();
$admin = User::create(['name' => 'Synthetic source administrator', 'email' => $adminEmail, 'password' => Hash::make($password)]);
$admin->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
DB::table('admin_second_factors')->insert(['user_id' => $admin->id, 'encrypted_secret' => Crypt::encryptString(AdminTotp::generateSecret()), 'last_accepted_step' => intdiv(now()->timestamp, 30) - 1, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Source registration '.$run, 'country' => 'AE', 'trade_license_number' => 'SOURCE-FIXTURE-'.$run, 'license_expires_on' => now()->addYear(), 'status' => 'approved']);
$chargers = [];
foreach (['1.6' => '16', '2.0.1' => '201'] as $protocol => $suffix) {
    $chargers[] = ['protocol' => $protocol, 'asset' => 'SOURCE-'.$suffix.'-'.$run, 'identity' => (($argv[4] ?? '') === 'diagnostic' ? 'ev-test-diag-' : 'CB-REG-').$run.'-'.$suffix];
}
echo json_encode(['fixture_only' => true, 'admin_email' => $admin->email, 'owner_email' => $owner->email, 'company_id' => $company->id, 'company_name' => $company->legal_name, 'chargers' => $chargers], JSON_THROW_ON_ERROR);
