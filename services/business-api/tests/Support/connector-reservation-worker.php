<?php

use App\Http\Controllers\ChargerAdmissionController;
use App\Http\Controllers\ChargingOperationsController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\ReservationLifecycleController;
use App\Models\ChargerCommand;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\ReservationLifecycle;
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
    $operation = $argv[5] ?? 'reserve';
    if ($operation === 'configure') {
        echo app(ChargerAdmissionController::class)->configure($request, VendorCompany::findOrFail($body['company_id']), VendorCharger::findOrFail($body['charger_id']))->getStatusCode();
    } elseif ($operation === 'revoke') {
        echo app(ChargerAdmissionController::class)->revoke($request, VendorCompany::findOrFail($body['company_id']), VendorCharger::findOrFail($body['charger_id']))->getStatusCode();
    } elseif ($operation === 'credential') {
        echo app(ChargerAdmissionController::class)->credential($request, VendorCompany::findOrFail($body['company_id']), VendorCharger::findOrFail($body['charger_id']))->getStatusCode();
    } elseif ($operation === 'case') {
        echo app(ChargingOperationsController::class)->open($request, ChargerCommand::findOrFail($body['command_id']))->getStatusCode();
    } elseif ($operation === 'reconcile') {
        echo (int) app(CommandReconciler::class)->forCommand((int) $body['command_id']);
    } elseif ($operation === 'arrival') {
        echo app(ReservationLifecycleController::class)->checkIn($request, Reservation::findOrFail($body['reservation_id']), app(ReservationLifecycle::class))->getStatusCode();
    } elseif ($operation === 'expire') {
        echo app(ReservationLifecycle::class)->expire(1, $body['reservation_id'])['expired'];
    } elseif ($operation === 'cancel') {
        app(PlatformController::class)->cancel($request, Reservation::findOrFail($body['reservation_id']));
        echo '200';
    } else {
        echo app(PlatformController::class)->reserve($request)->getStatusCode();
    }
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
