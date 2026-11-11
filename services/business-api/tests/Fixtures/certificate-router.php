<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../public'.$path)) {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless($app->environment('local') && config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), '/charger-certificates/test-') && config('csms.certificate_management_enabled'), 403);
Http::preventStrayRequests();
$app->handleRequest(Request::capture());
