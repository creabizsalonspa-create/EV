<?php

use App\Services\Commercial\SettlementService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script, $vendor, $actor, $key, $gate, $ready] = $argv;
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(2);
}
try {
    app(SettlementService::class)->draft((int) $vendor, 'AED', (int) $actor, $key);
    echo '201';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(2);
}
