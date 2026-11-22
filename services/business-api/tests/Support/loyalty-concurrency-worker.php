<?php

use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script, $operation, $user, $invoice, $reward, $key, $gate, $ready] = $argv;
config(['loyalty.enabled' => true]);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(2);
}
try {
    $service = app(LoyaltyService::class);
    match ($operation) {
        'earn' => $service->earn((int) $invoice, (int) $user),
        'redeem' => $service->redeem((int) $user, (int) $reward, (int) $user, $key),
        'claim-code' => $service->claimCoupon((int) $user, $invoice, $key),
        'claim-listed' => $service->claimCoupon((int) $user, null, $key, (int) $reward),
    };
    echo '201';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(2);
}
