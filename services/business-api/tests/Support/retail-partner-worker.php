<?php

use App\Models\User;
use App\Services\Retail\PartnerRegistry;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $actor, $partner, $action, $payload, $go, $ready] = $argv;
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($go)) {
    throw new RuntimeException('Race barrier timed out.');
}
try {
    app(PartnerRegistry::class)->mutate(User::findOrFail($actor), $partner === '0' ? null : (int) $partner, $action, json_decode($payload, true, 32, JSON_THROW_ON_ERROR));
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
