<?php

use App\Models\User;
use App\Services\Commercial\Referrals\ReferralRegistry;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script, $operation, $actorId, $invoiceId, $key, $go, $ready] = $argv;
config(['loyalty.enabled' => true]);
$actor = User::findOrFail($actorId);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($go)) {
    exit(2);
}
try {
    $registry = app(ReferralRegistry::class);
    if ($operation === 'withdraw') {
        $registry->withdraw($actor, 'Synthetic racing withdrawal');
    } else {
        $registry->qualify($actor, (int) $invoiceId, $key);
    }
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(2);
}
