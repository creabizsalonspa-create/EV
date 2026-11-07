<?php

use App\Services\Commercial\Payments\GatewayService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script, $checkout, $actor, $mode, $keys, $started, $clientKey] = $argv;
config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_recoveryprocess']);
Http::preventStrayRequests();
Http::fake(function ($request) use ($mode, $keys, $started) {
    file_put_contents($keys, $request->header('Idempotency-Key')[0]."\n", FILE_APPEND | LOCK_EX);
    touch($started);
    if ($mode === 'crash') {
        // Actual process death after durable preparation and during outbound transport.
        // The request is intercepted in-process; no external PSP call occurs.
        exit(72);
    }
    if ($mode === 'slow') {
        $deadline = microtime(true) + 15;
        while (! is_file($started.'.release') && microtime(true) < $deadline) {
            usleep(5000);
        }
        if (! is_file($started.'.release')) {
            throw new RuntimeException('Concurrent lease rejection barrier expired.');
        }
    }

    return Http::response(['id' => 'pi_recovery', 'livemode' => false, 'status' => 'succeeded', 'amount_received' => 100]);
});
try {
    app(GatewayService::class)->operate((int) $checkout, (int) $actor, 'capture', $clientKey);
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(2);
}
