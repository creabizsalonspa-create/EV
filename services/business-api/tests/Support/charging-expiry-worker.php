<?php

use App\Services\Charging\UnsentStartExpiry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || DB::connection()->getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    throw new RuntimeException('Disposable PostgreSQL testing database required.');
}
config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true]);
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET lock_timeout = '10s'");
touch($argv[3]);
$deadline = microtime(true) + 15;
while (! is_file($argv[2])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Race barrier timed out.');
    }
    usleep(5000);
}
echo app(UnsentStartExpiry::class)->run(1, (int) $argv[1])['expired'];
