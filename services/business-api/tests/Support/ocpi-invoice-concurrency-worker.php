<?php

use App\Models\User;
use App\Services\Ocpi\TestInvoiceLineage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! preg_match('/_(review|test|testing)$/D', DB::connection()->getDatabaseName())) {
    exit(2);
}
[$script, $operation, $fixture, $gate, $ready] = $argv;
$data = json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
config($data['configuration']);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(2);
}
try {
    $actor = User::findOrFail($data['actor']);
    $order = DB::table('ocpi_roaming_orders')->find($data['order']);
    $service = app(TestInvoiceLineage::class);
    if ($operation === 'resolve') {
        $service->resolve($actor, $order, $data['intent'] + ['cancel_unissued' => true]);
    } else {
        $service->issue($actor, $order, $data['intent']);
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(2);
}
