<?php

use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\LocalDemoBackendFailure;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || DB::getDriverName() !== 'sqlite' || config('payments.provider') !== 'mock' || ! preg_match('/^[1-9][0-9]{0,15}$/D', $argv[1] ?? '')) {
    exit(2);
}
$scope = DB::table('reservations as r')->join('users as u', 'u.id', '=', 'r.user_id')->join('csms_bindings as b', 'b.station_id', '=', 'r.station_id')->where('r.id', $argv[1])->get(['u.email', 'b.identifier']);
if ($scope->count() !== 1 || ! preg_match('/^client-demo-customer-([a-f0-9]{8})@example\.test$/D', $scope[0]->email, $match) || ! preg_match('/^ev-test-demo-'.preg_quote($match[1], '/').'-(16|201)$/D', $scope[0]->identifier)) {
    exit(2);
}
$session = ChargingSession::where('reservation_id', $argv[1])->first();
$commands = $session ? ChargerCommand::where('payload->session_id', $session->id)->orderBy('id')->limit(2)->get(['id', 'action', 'status', 'failure_code']) : [];
$tail = '';
$log = realpath(storage_path('logs/laravel.log'));
$directory = realpath(storage_path('logs'));
if ($log && $directory && str_starts_with($log, $directory.DIRECTORY_SEPARATOR) && is_file($log)) {
    $stream = fopen($log, 'rb');
    if ($stream) {
        fseek($stream, max(0, filesize($log) - 65536));
        $tail = (string) fread($stream, 65536);
        fclose($stream);
    }
}
echo json_encode(['commands' => $commands, 'backend' => LocalDemoBackendFailure::classify($tail)], JSON_THROW_ON_ERROR);
