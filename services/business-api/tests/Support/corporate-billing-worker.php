<?php

use App\Services\Commercial\CorporateBilling;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script,$operation,$agreement,$actor,$claim,$statement,$key,$gate,$ready] = $argv;
config(['payments.provider' => 'mock']);
DB::select("SELECT set_config('application_name', ?, false)", ['corporate-race-'.$key]);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(2);
}
try {
    $service = app(CorporateBilling::class);
    if ($operation === 'issue') {
        $service->issue((int) $agreement, (int) $actor, ['period' => now()->format('Y-m'), 'claim_ids' => [(int) $claim], 'idempotency_key' => $key]);
    } elseif ($operation === 'review') {
        $service->decide((int) $claim, (int) $actor, 'approved', 'Authorized synthetic test');
    } else {
        $service->simulatePayment((int) $statement, (int) $actor, $key);
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).':'.$e->getMessage());
    exit(2);
}
