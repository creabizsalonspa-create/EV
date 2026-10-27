<?php

use App\Http\Controllers\PlatformController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || DB::connection()->getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    throw new RuntimeException('Disposable PostgreSQL required.');
}
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET lock_timeout = '10s'");
$body = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::findOrFail((int) $argv[2]);
touch($argv[4]);
$deadline = microtime(true) + 15;
while (! is_file($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    } usleep(5000);
}
try {
    $request = Request::create('/api/reservations', 'POST', $body);
    $request->setUserResolver(fn () => $actor);
    echo app(PlatformController::class)->reserve($request)->getStatusCode();
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
