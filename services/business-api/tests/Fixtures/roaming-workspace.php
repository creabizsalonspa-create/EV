<?php

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Loopback browser QA only. Synthetic records, never a partner connection.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite') {
    throw new RuntimeException('Synthetic roaming fixture requires local SQLite.');
}
[$script, $action, $run] = $argv;
if (! preg_match('/^[a-f0-9]{8}$/', $run)) {
    throw new RuntimeException('Invalid fixture identity.');
}
foreach (['owner', 'other', 'admin'] as $name) {
    $email = "roaming-$name-$run@example.test";
    if ($action === 'clean') {
        $user = User::where('email', $email)->first();
        if (! $user) {
            continue;
        }
        $ids = OcpiProvider::where('owner_id', $user->id)->pluck('id');
        DB::table('ocpi_records')->whereIn('ocpi_provider_id', $ids)->delete();
        OcpiProvider::whereIn('id', $ids)->delete();
        $user->delete();

        continue;
    }
    if ($action !== 'seed' || count($argv) !== 4) {
        throw new RuntimeException('Invalid fixture action.');
    }
    $user = User::create(['name' => "Synthetic $name", 'email' => $email, 'password' => Hash::make($argv[3]), 'role' => $name === 'admin' ? 'admin' : 'vendor']);
    $user->role = $name === 'admin' ? 'admin' : 'vendor';
    $user->save();
    if ($name === 'admin') {
        continue;
    }
    $p = OcpiProvider::create(['owner_id' => $user->id, 'name' => "Synthetic $name $run", 'connection_key' => "fixture-$name-$run", 'authorized' => true, 'version' => '2.2.1']);
    $records = [];
    for ($i = 0; $i < ($name === 'owner' ? 26 : 1); $i++) {
        $records[] = ['country_code' => 'AE', 'party_id' => 'TST', 'id' => "$run-$i", 'publish' => $name === 'owner', 'name' => "Fixture $run $i <img src=x onerror=alert(1)>", 'address' => 'Synthetic address', 'city' => 'Test city', 'country' => 'ARE', 'coordinates' => ['latitude' => '25.2', 'longitude' => '55.3'], 'last_updated' => now()->subMinute()->toISOString(), 'evses' => [['uid' => "evse-$i", 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'IEC_62196_T2_COMBO']]]]];
    }
    app(RecordSynchronizer::class)->ingest($p, 'locations', $records);
}
