<?php

use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\ChargerSourceRegistration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    throw new RuntimeException('Disposable PostgreSQL only.');
}
$value = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
config(['csms.upstream_registration_enabled' => true, 'csms.upstream_registration_allow_local_http' => true, 'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql', 'csms.graphql_secret' => str_repeat('s', 64)]);
$remote = [];
Http::preventStrayRequests();
Http::fake(['http://127.0.0.1:8095/v1/graphql' => function (HttpRequest $request) use (&$remote, $value) {
    if (str_contains($request['query'], 'insert_ChargingStations_one')) {
        $handle = fopen($value['counter'], 'c+');
        if (! $handle || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Owned counter unavailable.');
        }
        $count = (int) stream_get_contents($handle);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) ($count + 1));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        usleep(400000);
        $remote = $request['variables']['object'] + ['id' => 501];

        return Http::response(['data' => ['insert_ChargingStations_one' => $remote]]);
    }

    return Http::response(['data' => ['ChargingStations' => $remote ? [$remote] : []]]);
}]);
$request = Request::create('/owned/source-registration', 'POST');
$actor = User::findOrFail($value['actor']);
$request->setUserResolver(fn () => $actor);
$company = VendorCompany::findOrFail($value['company']);
$charger = VendorCharger::findOrFail($value['charger']);
file_put_contents($argv[3], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[2]) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($argv[2])) {
    throw new RuntimeException('Owned process barrier timed out.');
}
$service = app(ChargerSourceRegistration::class);
if ($value['mode'] === 'prepare') {
    $result = $service->prepare($request, $company, $charger, ['identity' => 'CB-CONCURRENT-001', 'idempotency_key' => 'same-source-request']);
    echo $result['created'] ? '201' : '200';
} else {
    echo $service->register($request, $company, $charger, $value['registration'])['state'];
}
