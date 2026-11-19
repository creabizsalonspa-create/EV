<?php

use App\Http\Controllers\ChargerCommandController;
use App\Models\Station;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'pgsql' ||
    ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    exit(2);
}
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET lock_timeout = '10s'");
$input = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$actor = User::findOrFail((int) $input['actor']);
$station = Station::findOrFail((int) $input['station']);
touch($argv[3]);
$deadline = microtime(true) + 15;
while (! is_file($argv[2]) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($argv[2])) {
    exit(3);
}
try {
    $request = Request::create('/api/stations/'.$station->id.'/commands', 'POST', [
        'action' => 'start', 'idempotency_key' => $input['key'],
    ]);
    $request->setUserResolver(fn () => $actor);
    echo app(ChargerCommandController::class)->create($request, $station)->getStatusCode();
} catch (HttpExceptionInterface $error) {
    echo $error->getStatusCode();
} catch (Throwable $error) {
    fwrite(STDERR, $error::class);
    exit(1);
}
