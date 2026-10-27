<?php

use App\Http\Controllers\FinanceController;
use App\Models\Finance\Invoice;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
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
    if ($operation === 'tariff') {
        $actor = User::findOrFail((int) $actorId);
        $request = Request::create('/', 'POST', ['rate_minor_per_kwh' => 250, 'tax_bps' => 500, 'commission_bps' => 0, 'idempotency_key' => 'concurrent-tariff-key', 'expected_minor_units' => 2, 'expected_currency' => 'AED']);
        $request->setUserResolver(fn () => $actor);
        app(FinanceController::class)->createTariff($request, Station::findOrFail((int) $invoiceId));
    } else {
        $service = app(FinanceService::class);
        $invoice = Invoice::findOrFail((int) $invoiceId);
        if ($operation === 'payment') {
            $service->pay($invoice, (int) $actorId, 100, 'concurrent-shared-key');
        } else {
            $service->refund($invoice, (int) $actorId, 100, 'concurrent-shared-key', 'Concurrent simulation refund');
        }
    }
    echo '201';
} catch (HttpException $exception) {
    echo $exception->getStatusCode();
} catch (Throwable $exception) {
    // Never serialize connection details or credentials in process diagnostics.
    fwrite(STDERR, get_class($exception)."\n");
    exit(2);
}
