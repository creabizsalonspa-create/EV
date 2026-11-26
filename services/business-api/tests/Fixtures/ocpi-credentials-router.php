<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Selected exclusively by the disposable credentials test harness.
// Production routes and bootstrap never install a synthetic transport.
$public = realpath(__DIR__.'/../../public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = is_string($path) ? realpath($public.$path) : false;
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$run = getenv('EV_OCPI_CREDENTIAL_FIXTURE_RUN');
abort_unless($app->environment('local') && config('database.default') === 'sqlite'
    && is_string($run) && preg_match('/^[a-f0-9]{8}$/D', $run), 403);
$connections = config('ocpi.connections');
$alias = 'managed_'.$run;
abort_unless(is_array($connections) && array_keys($connections) === [$alias], 403);
$connection = $connections[$alias];
abort_unless(($connection['managed_credentials'] ?? false) === true
    && ($connection['origin'] ?? null) === 'https://partner.example'
    && ($connection['environment'] ?? null) === 'sandbox'
    && ($connection['party_role'] ?? null) === 'CPO'
    && ($connection['party_country'] ?? null) === 'NL'
    && ($connection['party_id'] ?? null) === 'ABC'
    && ! config('ocpi.test_commands_enabled')
    && ! config('ocpi.test_billing_enabled'), 403);

Http::preventStrayRequests();
Http::fake(function ($request) {
    $allowed = array_map(fn ($token) => 'Token '.base64_encode($token),
        ['SYNTHETIC-PEER-ROTATED', 'SYNTHETIC-NATIVE-C']);
    $authorization = $request->header('Authorization');
    $requestId = $request->header('X-Request-ID');
    $correlation = $request->header('X-Correlation-ID');
    if (count($authorization) !== 1 || ! in_array($authorization[0], $allowed, true)
        || $request->method() !== 'GET' || count($requestId) !== 1 || count($correlation) !== 1) {
        return Http::response(['status_code' => 2004, 'data' => null], 403);
    }

    $data = match ($request->url()) {
        'https://partner.example/ocpi/versions' => [
            ['version' => '2.2.1', 'url' => 'https://partner.example/ocpi/2.2.1'],
        ],
        'https://partner.example/ocpi/2.2.1' => [
            'version' => '2.2.1',
            'endpoints' => [
                ['identifier' => 'credentials', 'role' => 'RECEIVER', 'url' => 'https://partner.example/ocpi/2.2.1/credentials'],
            ],
        ],
        default => null,
    };
    if ($data === null) {
        return Http::response(['status_code' => 2004, 'data' => null], 403);
    }

    return Http::response(
        ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $data],
        200,
        ['X-Request-ID' => $requestId[0], 'X-Correlation-ID' => $correlation[0]],
    );
});
$app->handleRequest(Request::capture());
