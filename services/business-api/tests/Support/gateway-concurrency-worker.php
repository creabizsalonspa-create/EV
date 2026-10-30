<?php

use App\Services\Commercial\Payments\GatewayService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script, $checkout, $actor, $gate, $ready, $operation] = $argv;
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(2);
}
try {
    $gateway = app(GatewayService::class);
    if ($operation === 'webhook') {
        config(['payments.webhook_secret' => str_repeat('g', 48)]);
        $raw = json_encode(['event_id' => 'race-event', 'checkout_id' => (int) $checkout, 'type' => 'captured', 'amount_minor' => 100, 'currency' => 'AED']);
        $timestamp = (string) time();
        $gateway->webhook($raw, $timestamp, hash_hmac('sha256', $timestamp.'.'.$raw, str_repeat('g', 48)));
    } else {
        $gateway->operate((int) $checkout, (int) $actor, 'capture', 'concurrent-capture');
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(2);
}
