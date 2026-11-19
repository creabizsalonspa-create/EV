<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Only this explicit local test server installs a synthetic outbound transport.
// Application production code never installs an HTTP fake or fallback authority.
$public = realpath(__DIR__.'/../../public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = is_string($path) ? realpath($public.$path) : false;
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless($app->environment('local') && config('database.default') === 'sqlite' && config('ocpi.test_commands_enabled'), 403);
Http::fake(function ($request) {
    abort_unless($request->header('Authorization') === ['Token '.base64_encode('SYNTHETIC-REMOTE-C')], 403);
    $url = parse_url($request->url());
    abort_unless(($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') === 'partner.example' && ! isset($url['query']), 403);
    $token = $request->method() === 'PUT' && preg_match('#^/tokens/AE/EVX/[A-F0-9]{20}$#D', $url['path'] ?? '') && $request['type'] === 'APP_USER' && $request['whitelist'] === 'NEVER';
    $command = $request->method() === 'POST' && in_array($url['path'] ?? '', ['/commands/START_SESSION', '/commands/STOP_SESSION'], true);
    $reservations = collect(config('ocpi.connections'))->contains(fn ($c) => ($c['test_reservations'] ?? false) === true && is_string($c['test_reservation_contract'] ?? null));
    $reserve = $reservations && $request->method() === 'POST' && ($url['path'] ?? '') === '/commands/RESERVE_NOW' && ($request['token']['valid'] ?? null) === true && ($request['token']['type'] ?? null) === 'APP_USER' && ($request['token']['whitelist'] ?? null) === 'NEVER' && is_string($request['reservation_id']) && is_string($request['expiry_date']) && ! isset($request['connector_id']);
    $cancel = $reservations && $request->method() === 'POST' && ($url['path'] ?? '') === '/commands/CANCEL_RESERVATION' && is_string($request['reservation_id']);
    abort_unless($token || $command || $reserve || $cancel, 403);

    return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $token ? null : ['result' => 'ACCEPTED', 'timeout' => 30]]);
});
$app->handleRequest(Request::capture());
