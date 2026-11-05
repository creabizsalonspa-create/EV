<?php

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Explicit local synthetic protocol authority; never a real partner handshake.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Isolated local credentials fixture required.');
}
[$script, $run, $password] = $argv;
$owner = User::create(['name' => 'Synthetic credentials owner', 'email' => "credentials-owner-$run@example.test", 'password' => Hash::make($password)]);
$owner->forceFill(['role' => 'vendor'])->save();
$admin = User::create(['name' => 'Synthetic credentials reviewer', 'email' => "credentials-admin-$run@example.test", 'password' => Hash::make($password)]);
$admin->forceFill(['role' => 'admin'])->save();
$p = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic credentials '.$run, 'connection_key' => 'managed_'.$run, 'authorized' => true]);
$token = bin2hex(random_bytes(32));
DB::table('ocpi_credential_links')->insert(['provider_id' => $p->id, 'owner_id' => $owner->id, 'generation' => 1, 'state' => 'active', 'configuration_hash' => app(CredentialExchange::class)->configurationHash($p), 'bootstrap_hash' => hash('sha256', config('ocpi.connections.'.$p->connection_key.'.authorization_token')), 'local_token_digest' => hash('sha256', $token), 'local_token_ciphertext' => Crypt::encryptString($token), 'remote_token_ciphertext' => Crypt::encryptString('SYNTHETIC-REMOTE-C'), 'exchanged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
echo json_encode(['provider_id' => $p->id, 'name' => $p->name, 'owner' => $owner->email, 'admin' => $admin->email, 'local_token' => $token, 'fixture_only' => true], JSON_THROW_ON_ERROR);
