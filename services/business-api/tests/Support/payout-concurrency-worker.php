<?php

use App\Services\Commercial\Payouts\MockPayoutProvider;
use App\Services\Commercial\Payouts\PayoutService;
use App\Services\Commercial\SettlementService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script, $operation, $settlement, $account, $actor, $payout, $gate, $ready] = $argv;
config(['payouts.enabled' => true, 'payouts.mock_outcome' => 'paid']);
$app->instance(MockPayoutProvider::class, new class($operation) extends MockPayoutProvider
{
    public function __construct(private string $operation) {}

    public function create(object $payout, object $account, object $attempt): array
    {
        $result = parent::create($payout, $account, $attempt);
        if ($this->operation === 'crash') {
            exit(67);
        } // After durable sent marker; before applying provider response.
        usleep(600000);

        return $result;
    }
});
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(2);
}
try {
    $service = app(PayoutService::class);
    if ($operation === 'create_same' || $operation === 'create_other') {
        $service->create((int) $settlement, (int) $account, (int) $actor, $operation);
        echo '201';
    } elseif ($operation === 'legacy') {
        app(SettlementService::class)->transition((int) $settlement, (int) $actor, 'simulate-payout');
        echo '200';
    } else {
        $service->execute((int) $payout, (int) $actor);
        echo '200';
    }
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(2);
}
