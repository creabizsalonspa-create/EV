<?php

use App\Models\OcpiProvider;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    throw new RuntimeException('Disposable PostgreSQL test database required.');
}
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET lock_timeout = '10s'");
[$script, $operation, $id, $gate, $ready] = $argv;
config(['ocpi.connections.boundary' => ['origin' => 'https://partner.example', 'authorization_token' => base64_encode('fake-contract-only')]]);
$provider = OcpiProvider::findOrFail((int) $id);
$barrier = static function () use ($gate, $ready): void {
    touch($ready);
    $deadline = microtime(true) + 15;
    while (! is_file($gate)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('OCPI worker barrier timed out');
        }
        usleep(5000);
    }
};
$envelope = static fn ($data) => ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $data];
Http::preventStrayRequests();
Http::fake(static function ($request) use ($barrier, $envelope) {
    if (str_ends_with($request->url(), '/ocpi/versions')) {
        return Http::response($envelope([['version' => '2.2.1', 'url' => 'https://partner.example/details']]));
    }
    $barrier();

    return Http::response($envelope(['version' => '2.2.1', 'endpoints' => [['identifier' => 'locations', 'role' => 'SENDER', 'url' => 'https://partner.example/locations']]]));
});
try {
    if ($operation === 'ingest') {
        $barrier();
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'old-pull', 'last_updated' => now()->subMinute()->toISOString(), 'publish' => true, 'address' => 'Fixture', 'city' => 'Fixture', 'country' => 'ARE', 'coordinates' => ['latitude' => '25', 'longitude' => '55']]]);
    } else {
        app(PartnerClient::class)->negotiate($provider);
    }
    echo '200';
} catch (OcpiException $error) {
    echo '409';
} catch (Throwable $error) {
    fwrite(STDERR, $error::class);
    exit(1);
}
