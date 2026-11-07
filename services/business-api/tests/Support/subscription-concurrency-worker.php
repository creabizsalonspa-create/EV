<?php

use App\Models\User;
use App\Services\Commercial\SubscriptionCreditService;
use App\Services\Commercial\SubscriptionDunning;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'pgsql' || ! preg_match('/_(review|test|testing)$/', DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "A disposable PostgreSQL testing database is required.\n");
    exit(2);
}
[$script, $operation, $entityId, $actorId, $invoiceId, $key, $gate, $ready] = $argv;
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    fwrite(STDERR, "Concurrency start barrier expired.\n");
    exit(2);
}
try {
    $service = app(SubscriptionService::class);
    $actor = User::findOrFail((int) $actorId);
    config(['payments.provider' => 'mock']);
    match ($operation) {
        'enroll' => $service->subscribe($actor, (int) $entityId, $key),
        'renew' => $service->renew((int) $entityId),
        'dunning' => app(SubscriptionDunning::class)->invoice((int) $entityId),
        'credit' => app(SubscriptionCreditService::class)->refund($actor, (int) $entityId, (int) $invoiceId, 1050, 'Concurrent approved subscription refund', $key),
        'gateway_capture' => app(SubscriptionGateway::class)->operate($actor, (int) $entityId, 'capture', $key, null),
        'pay' => $service->mockPay($actor, (int) $entityId, (int) $invoiceId, 'succeeded', $key),
        default => throw new LogicException('Unknown concurrency operation'),
    };
    echo '200';
} catch (HttpException $exception) {
    echo $exception->getStatusCode();
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception)."\n");
    exit(2);
}
