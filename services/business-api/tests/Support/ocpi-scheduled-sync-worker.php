<?php

use App\Services\Ocpi\ScheduledSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    exit(2);
}
[$script, $operation, $id, $gate, $ready] = $argv;
if ($operation !== 'poll' || dirname($gate) !== dirname($ready) || ! str_starts_with(realpath(dirname($gate)), sys_get_temp_dir().'/ocpi-sync-race-')) {
    exit(2);
}
config(['ocpi.scheduled_sync_enabled' => true, 'ocpi.connections.race' => ['origin' => 'https://partner.example', 'authorization_token' => base64_encode('synthetic-read-only'), 'party_country' => 'AE', 'party_id' => 'ABC', 'party_role' => 'CPO']]);
Http::preventStrayRequests();
Http::fake(function () use ($ready) {
    // Hold the first GET until the other worker has finished its competing
    // claim. This proves overlap without depending on scheduler timing.
    $deadline = microtime(true) + 15;
    while (glob(dirname($ready).'/ready-*.done') === [] && microtime(true) < $deadline) {
        usleep(5000);
    }

    return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => []]);
});
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(3);
}
$result = app(ScheduledSync::class)->run();
touch($ready.'.done');
echo json_encode(['result' => $result['state'], 'requests' => Http::recorded()->count()], JSON_THROW_ON_ERROR);
