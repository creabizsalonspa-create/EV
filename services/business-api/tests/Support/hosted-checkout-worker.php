<?php

use App\Models\User;
use App\Services\Commercial\Payments\HostedCheckout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    throw new RuntimeException('Disposable testing database required');
}
config(['payments.provider' => 'mock']);
[$operation,$input,$go,$ready] = array_slice($argv, 1);
$values = json_decode(file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(5000);
}if (! is_file($go)) {
    throw new RuntimeException('Missing process barrier');
}
try {
    $actor = User::findOrFail($values['actor']);
    $s = app(HostedCheckout::class);
    $r = match ($operation) {
        'create' => $s->create($actor, $values['values']),'capture' => $s->capture($actor, $values['hosted']),default => throw new RuntimeException('Unknown operation')
    };
    echo json_encode(['status' => 201, 'id' => $r['id']]);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
