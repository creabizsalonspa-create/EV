<?php

use App\Http\Controllers\OcpiController;
use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\TechnicalAgreement;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $directory, $index] = $argv;
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')
    || ! preg_match('#^'.preg_quote(sys_get_temp_dir(), '#').'/ocpi-agreement-race-[a-f0-9]{16}$#D', $directory)
    || ! is_dir($directory) || is_link($directory) || ! preg_match('/^[0-9]{1,2}$/D', $index)) {
    throw new RuntimeException('Guarded disposable PostgreSQL agreement worker required.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, flags: JSON_THROW_ON_ERROR);
config($input['configuration']);
Http::preventStrayRequests();
$actor = User::findOrFail($input['admin_id']);
$provider = OcpiProvider::findOrFail($input['provider_id']);
$operation = $input['operations'][(int) $index];
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 20;
while (! is_file($directory.'/go') && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($directory.'/go')) {
    throw new RuntimeException('Agreement race barrier timed out.');
}
try {
    $service = app(TechnicalAgreement::class);
    if ($operation['kind'] === 'propose') {
        $id = $service->propose($provider, $actor, $operation['body']);
        echo json_encode(['status' => 201, 'id' => $id]);
    } elseif ($operation['kind'] === 'decide') {
        $id = $service->decide($provider, $actor, $operation['agreement_id'], $operation['body']);
        echo json_encode(['status' => 200, 'id' => $id]);
    } elseif ($operation['kind'] === 'authorize') {
        $request = Request::create('/api/ocpi/providers/'.$provider->id.'/authorization', 'PATCH', ['authorized' => true]);
        $request->setUserResolver(fn () => $actor);
        $response = app(OcpiController::class)->authorization($request, $provider, app(PartnerClient::class));
        echo json_encode(['status' => $response->getStatusCode()]);
    } else {
        throw new RuntimeException('Unknown agreement race operation.');
    }
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
} catch (OcpiException) {
    echo json_encode(['status' => 409]);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).' at '.basename($e->getFile()).':'.$e->getLine().($e instanceof QueryException ? ' SQLSTATE '.($e->errorInfo[0] ?? 'unknown') : '')."\n");
    exit(1);
}
