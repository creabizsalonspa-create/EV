<?php

use App\Models\Finance\Invoice;
use App\Services\Finance\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    fwrite(STDERR, "A disposable testing environment is required.\n");
    exit(2);
}
[$script, $operation, $invoiceId, $actorId, $gate, $ready] = $argv;
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    fwrite(STDERR, "Concurrency test start barrier expired.\n");
    exit(2);
}
try {
    $service = app(FinanceService::class);
    $invoice = Invoice::findOrFail((int) $invoiceId);
    if ($operation === 'payment') {
        $service->pay($invoice, (int) $actorId, 100, 'concurrent-shared-key');
    } else {
        $service->refund($invoice, (int) $actorId, 100, 'concurrent-shared-key', 'Concurrent simulation refund');
    }
    echo '201';
} catch (HttpException $exception) {
    echo $exception->getStatusCode();
} catch (Throwable $exception) {
    // Never serialize connection details or credentials in process diagnostics.
    fwrite(STDERR, get_class($exception)."\n");
    exit(2);
}
