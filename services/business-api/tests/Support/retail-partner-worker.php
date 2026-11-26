<?php

use App\Models\User;
use App\Services\Retail\PartnerRegistry;
use App\Services\Retail\PartnerRewardGovernance;
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
    $user = User::findOrFail($actor);
    $values = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
    if (str_starts_with($action, 'reward:')) {
        $review = $values['review'];
        unset($values['review']);
        app(PartnerRewardGovernance::class)->record($user, (int) $partner, $review, substr($action, 7), $values);
    } else {
        app(PartnerRegistry::class)->mutate($user, $partner === '0' ? null : (int) $partner, $action, $values);
    }
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
