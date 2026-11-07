<?php

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Explicit local synthetic protocol authority; never a real partner handshake.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || ! in_array(count($argv), [3, 4], true) || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Isolated local credentials fixture required.');
}
[$script, $run, $password] = $argv;
$owner = User::create(['name' => 'Synthetic credentials owner', 'email' => "credentials-owner-$run@example.test", 'password' => Hash::make($password)]);
$owner->forceFill(['role' => 'vendor'])->save();
$admin = User::create(['name' => 'Synthetic credentials reviewer', 'email' => "credentials-admin-$run@example.test", 'password' => Hash::make($password)]);
$admin->forceFill(['role' => 'admin'])->save();
$p = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic credentials '.$run, 'connection_key' => 'managed_'.$run, 'authorized' => true]);
$incoming = ($argv[3] ?? 'active') === 'incoming';
if (in_array($argv[3] ?? '', ['receiver', 'roaming', 'roaming-billing'], true)) {
    $p->update(['version' => '2.2.1', 'endpoints' => ['sessions' => 'https://partner.example/sessions', 'cdrs' => 'https://partner.example/cdrs']]);
}
$token = $incoming ? base64_decode(config('ocpi.connections.'.$p->connection_key.'.inbound_authorization_token'), true) : bin2hex(random_bytes(32));
if (! $incoming) {
    DB::table('ocpi_credential_links')->insert(['provider_id' => $p->id, 'owner_id' => $owner->id, 'generation' => 1, 'state' => 'active', 'configuration_hash' => app(CredentialExchange::class)->configurationHash($p), 'bootstrap_hash' => hash('sha256', config('ocpi.connections.'.$p->connection_key.'.authorization_token')), 'local_token_digest' => hash('sha256', $token), 'local_token_ciphertext' => Crypt::encryptString($token), 'remote_token_ciphertext' => Crypt::encryptString('SYNTHETIC-REMOTE-C'), 'exchanged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}
$extra = [];
if (in_array($argv[3] ?? '', ['roaming', 'roaming-billing'], true)) {
    abort_unless(config('ocpi.test_commands_enabled'), 403);
    $p->update(['endpoints' => ['locations' => 'https://partner.example/locations', 'tariffs' => 'https://partner.example/tariffs', 'sessions' => 'https://partner.example/sessions', 'cdrs' => 'https://partner.example/cdrs', 'tokens_receiver' => 'https://partner.example/tokens', 'commands_receiver' => 'https://partner.example/commands']]);
    $driver = User::create(['name' => 'Synthetic roaming driver', 'email' => "roaming-driver-$run@example.test", 'password' => Hash::make($password)]);
    $driver->forceFill(['role' => 'customer'])->save();
    $other = User::create(['name' => 'Other roaming driver', 'email' => "roaming-other-$run@example.test", 'password' => Hash::make($password)]);
    $other->forceFill(['role' => 'customer'])->save();
    $identity = ['country_code' => 'NL', 'party_id' => 'ABC', 'last_updated' => now()->subSecond()->toISOString()];
    app(RecordSynchronizer::class)->ingest($p, 'locations', [$identity + ['id' => 'L1', 'publish' => true, 'name' => 'Synthetic roaming '.$run, 'address' => 'Synthetic address', 'city' => 'Test city', 'country' => 'NLD', 'coordinates' => ['latitude' => '52.1', 'longitude' => '4.3'], 'evses' => [['uid' => 'E1', 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'IEC_62196_T2_COMBO', 'tariff_ids' => ['T1']]]]]]]);
    app(RecordSynchronizer::class)->ingest($p, 'tariffs', [$identity + ['id' => 'T1', 'currency' => 'EUR', 'elements' => [['price_components' => [['type' => 'ENERGY', 'price' => '0.25', 'step_size' => 1, 'vat' => '0']]]]]]);
    if (($argv[3] ?? '') === 'roaming-billing') {
        abort_unless(config('ocpi.test_billing_enabled'), 403);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic TEST merchant '.$run, 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-ONLY', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $extra['company_id'] = $company->id;
    }
    $extra += ['driver' => $driver->email, 'other' => $other->email, 'location_id' => OcpiRecord::where('ocpi_provider_id', $p->id)->where('module', 'locations')->firstOrFail()->id];
}
echo json_encode(['provider_id' => $p->id, 'name' => $p->name, 'owner' => $owner->email, 'admin' => $admin->email, 'local_token' => $token, 'fixture_only' => true] + $extra, JSON_THROW_ON_ERROR);
