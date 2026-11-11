<?php

use App\Http\Controllers\PlatformController;
use App\Http\Controllers\StationInformationController;
use App\Models\Station;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    throw new RuntimeException('Explicit disposable PostgreSQL required.');
}
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET lock_timeout = '10s'");
$input = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::findOrFail($input['actor_id']);
$station = Station::findOrFail($input['station_id']);
DB::beginTransaction();
try {
    // The first independent connection deterministically owns the same station
    // lock both controllers use; the second must observe its committed policy.
    if ($input['first']) {
        Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
    }
    touch($argv[3]);
    $deadline = microtime(true) + 15;
    while (! is_file($argv[2])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Calendar concurrency barrier timed out.');
        }
        usleep(5000);
    }
    $request = Request::create('/api/test-calendar', 'POST', $input['body']);
    $request->setUserResolver(fn () => $actor);
    $reply = $input['operation'] === 'publish'
        ? app(StationInformationController::class)->save($request, $station)
        : app(PlatformController::class)->reserve($request);
    DB::commit();
    echo json_encode(['status' => $reply->getStatusCode(), 'body' => $reply->getData(true)], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $e) {
    DB::rollBack();
    echo json_encode(['status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}
