<?php

// Synthetic local pagination data only; never a public endpoint or production seed.
use App\Models\Station;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/', $argv[1]) || ! ctype_digit($argv[2])) {
    throw new RuntimeException('Isolated local favorites fixture required.');
}
$user = User::findOrFail((int) $argv[2]);
if ($user->role !== 'customer' || ! str_starts_with($user->email, 'protocol-customer-') || ! str_ends_with($user->email, '@example.test')) {
    throw new RuntimeException('Synthetic local customer required.');
}
$vendor = User::factory()->create(['role' => 'vendor']);
$ids = [];
for ($i = 0; $i < 26; $i++) {
    $s = Station::create(['vendor_id' => $vendor->id, 'name' => 'Favorite QA '.$argv[1].' <img src=x onerror=alert(1)> '.$i, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'discovery', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    $ids[] = $s->id;
    if ($i < 25) {
        DB::table('station_favorites')->insert(['user_id' => $user->id, 'station_id' => $s->id, 'created_at' => now()]);
    }
}
echo json_encode(['fixture_only' => true, 'station_ids' => $ids, 'search' => 'Favorite QA '.$argv[1]]);
