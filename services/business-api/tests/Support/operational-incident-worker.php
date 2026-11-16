<?php

use App\Services\Operations\OperationalHealth;
use App\Services\Operations\OperationalIncidents;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    exit(2);
}
[$script, $gate, $ready] = $argv;
if (dirname($gate) !== dirname($ready)
    || ! str_starts_with(realpath(dirname($gate)), sys_get_temp_dir().'/operational-incident-race-')) {
    exit(2);
}
config(['operational_health.enabled' => true, 'queue.default' => 'database',
    'queue.connections.database.connection' => null, 'queue.connections.database.queue' => 'default']);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(3);
}
$result = app(OperationalIncidents::class)->scan(app(OperationalHealth::class));
echo json_encode($result, JSON_THROW_ON_ERROR);
